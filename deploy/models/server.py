#!/usr/bin/env python3
"""A small HTTP service around Google's TimesFM 2.5 (time-series forecasting), for Talixio.

  GET  /health    -> {"ok": true, "model": "TimesFM 2.5 (200M)", "loaded": bool}
  POST /forecast  {"series": [numbers, oldest first], "horizon": 1..256}
                  -> {"model", "horizon", "mean": [...], "p10": [...], "p90": [...]}

Listens on 0.0.0.0:8765 inside its container (reachable only on the stack's private network, and through Caddy
for /forecast and /health). No authentication of its own: it holds no data and keeps nothing. The model loads on
the first forecast (a few seconds, then it stays in memory).
"""
import json, os, sys, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MAX_POINTS = int(os.environ.get("TIMESFM_MAX_CONTEXT", "1024"))   # a multiple of 32
MAX_HORIZON = 256
MAX_BODY = 2_000_000
NAME = "TimesFM 2.5 (200M)"
_lock = threading.Lock()
_model = None


def load():
    """Load and compile the model once (needs the timesfm package; see models.Dockerfile)."""
    global _model
    if _model is None:
        import numpy  # noqa: F401
        import torch
        import timesfm
        torch.set_float32_matmul_precision("high")
        m = timesfm.TimesFM_2p5_200M_torch.from_pretrained("google/timesfm-2.5-200m-pytorch")
        m.compile(timesfm.ForecastConfig(
            max_context=MAX_POINTS, max_horizon=MAX_HORIZON, normalize_inputs=True,
            use_continuous_quantile_head=True, force_flip_invariance=True,
            infer_is_positive=False, fix_quantile_crossing=True))
        _model = m
    return _model


def predict(series, horizon):
    """Returns (mean, p10, p90) as lists of floats. Replaced in tests."""
    import numpy as np
    m = load()
    with _lock:
        point, quant = m.forecast(horizon=horizon, inputs=[np.asarray(series[-MAX_POINTS:], dtype=np.float32)])
    # quant: (1, horizon, 10) = mean, then the 10th..90th percentiles
    q = quant[0]
    return [float(x) for x in point[0][:horizon]], [float(x) for x in q[:horizon, 1]], [float(x) for x in q[:horizon, 9]]


def check(body):
    s = body.get("series")
    if not isinstance(s, list) or len(s) < 3:
        raise ValueError("series: at least 3 numbers")
    out = []
    for v in s:
        if isinstance(v, bool) or not isinstance(v, (int, float)) or v != v or v in (float("inf"), float("-inf")):
            raise ValueError("series: only finite numbers")
        out.append(float(v))
    h = body.get("horizon", 12)
    if isinstance(h, bool) or not isinstance(h, int) or not 1 <= h <= MAX_HORIZON:
        raise ValueError("horizon: an integer from 1 to %d" % MAX_HORIZON)
    return out, h


class Handler(BaseHTTPRequestHandler):
    server_version = "talixio-models"

    def log_message(self, fmt, *args):
        sys.stderr.write("%s %s\n" % (self.address_string(), fmt % args))

    def send(self, code, obj):
        data = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path == "/health":
            return self.send(200, {"ok": True, "model": NAME, "loaded": _model is not None})
        self.send(404, {"error": "not found"})

    def do_POST(self):
        if self.path != "/forecast":
            return self.send(404, {"error": "not found"})
        try:
            n = int(self.headers.get("Content-Length", "0"))
            if n <= 0 or n > MAX_BODY:
                return self.send(413, {"error": "body too large or empty"})
            body = json.loads(self.rfile.read(n))
            if not isinstance(body, dict):
                raise ValueError("a JSON object is expected")
            series, horizon = check(body)
        except (ValueError, json.JSONDecodeError) as e:
            return self.send(422, {"error": str(e)})
        try:
            mean, p10, p90 = predict(series, horizon)
        except Exception as e:  # model missing, out of memory…
            sys.stderr.write("forecast failed: %r\n" % (e,))
            return self.send(500, {"error": "forecast failed: %s" % type(e).__name__})
        self.send(200, {"model": NAME, "horizon": horizon, "mean": mean, "p10": p10, "p90": p90})


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "8765"))
    if os.environ.get("TIMESFM_PRELOAD", "1") == "1":
        threading.Thread(target=load, daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()
