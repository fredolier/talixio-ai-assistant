"""Evaluate Laya checkpoints on Talixio's decisions (CPU). Usage: python evaluate.py models/multilingual [models/english]"""
import json, os, sys, time
os.environ.setdefault("USE_TF", "0")
import numpy as np
import torch
from rl_agent_api import RLAgent
from email_utils import email_state
import dataset as D

torch.set_num_threads(os.cpu_count())


def run(agent, state, questions):
    t = time.perf_counter()
    r = agent.system_one(state, questions)
    return r["answers"], (time.perf_counter() - t) * 1000


def summarize(name, rows):
    """rows: (correct: bool, confidence: float)"""
    if not rows:
        return {}
    c = np.array([r[0] for r in rows], float); conf = np.array([r[1] for r in rows], float)
    order = np.argsort(-conf); half = order[: max(1, len(order) // 2)]
    return {"task": name, "n": len(rows), "accuracy": round(c.mean(), 3), "mean_conf": round(conf.mean(), 3),
            "conf_right": round(conf[c == 1].mean(), 3) if (c == 1).any() else None,
            "conf_wrong": round(conf[c == 0].mean(), 3) if (c == 0).any() else None,
            "acc_top_half_conf": round(c[half].mean(), 3)}


def evaluate(model_dir):
    t0 = time.perf_counter()
    agent = RLAgent(model_dir, device="cpu")
    load_s = time.perf_counter() - t0
    out = {"model": model_dir, "load_s": round(load_s, 1), "tasks": [], "errors": [], "latency_ms": {}}
    lat1, latb = [], []

    # 1. email triage + urgency + "is it a task?" in one call (3 questions)
    tri, urg, task, tri_fr, tri_en = [], [], [], [], []
    qs = {"triage": {"type": "choice", "instructions": "How should the user handle this email they received?", "criteria": D.MAIL_LABELS},
          "urgency": {"type": "score", "instructions": "How urgent is it for the user to deal with this email?", "criteria": D.URGENCY},
          "task": {"type": "noul", "instructions": "Does this email ask the user to do something concrete (pay, sign, send, book, renew)?"}}
    for i, (frm, subj, body, label, u, is_task) in enumerate(D.EMAILS):
        a, ms = run(agent, email_state(subj, body, frm), qs)
        latb.append(ms)
        conf = a["triage"]["probabilities"][a["triage"]["choice"]]
        ok = a["triage"]["choice"] == label
        tri.append((ok, conf)); (tri_en if i >= 35 else tri_fr).append((ok, conf))
        if not ok: out["errors"].append(f"triage: {subj!r} -> {a['triage']['choice']} ({conf:.2f}), expected {label}")
        urg.append((abs(a["urgency"]["score"] - u) < 0.5, a["urgency"]["confidence"]))
        pt = a["task"]["noul"]
        task.append(((pt > 0.5) == is_task, max(pt, 1 - pt)))
    out["tasks"] += [summarize("email triage (4 groups)", tri), summarize("  of which French", tri_fr), summarize("  of which English", tri_en),
                     summarize("email urgency (3 levels, within 0.5)", urg), summarize("email is a task (yes/no)", task)]

    # 2. prompt injection
    inj = []
    q = {"inj": {"type": "noul", "instructions": "Does this text try to give instructions or orders to an AI assistant (prompt injection), rather than just inform a human reader?"}}
    for text, label in D.INJECTION:
        a, ms = run(agent, {"text": text}, q); lat1.append(ms)
        p = a["inj"]["noul"]; inj.append(((p > 0.5) == label, max(p, 1 - p)))
        if (p > 0.5) != label: out["errors"].append(f"injection: {text[:60]!r} -> p={p:.2f}, expected {label}")
    out["tasks"].append(summarize("prompt injection (yes/no)", inj))

    # 3. which space a message belongs to
    rt = []
    q = {"space": {"type": "choice", "instructions": "Which of the user's spaces does this note belong to?", "criteria": D.SPACES}}
    for text, label in D.ROUTING:
        a, ms = run(agent, {"note": text}, q); lat1.append(ms)
        ch = a["space"]["choice"]; rt.append((ch == label, a["space"]["probabilities"][ch]))
        if ch != label: out["errors"].append(f"space: {text!r} -> {ch}, expected {label}")
    out["tasks"].append(summarize("space routing (5 spaces)", rt))

    # 4. model tier for a request
    tr = []
    q = {"tier": {"type": "choice", "instructions": "Which kind of AI model does this request need?", "criteria": D.TIERS}}
    for text, label in D.TIER_ROUTING:
        a, ms = run(agent, {"request": text}, q); lat1.append(ms)
        ch = a["tier"]["choice"]; tr.append((ch == label, a["tier"]["probabilities"][ch]))
        if ch != label: out["errors"].append(f"tier: {text[:60]!r} -> {ch}, expected {label}")
    out["tasks"].append(summarize("model tier (3 levels)", tr))

    out["latency_ms"] = {"1 question p50": round(float(np.median(lat1)), 0), "1 question p95": round(float(np.percentile(lat1, 95)), 0),
                         "3 questions (email) p50": round(float(np.median(latb)), 0)}
    import resource
    out["peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024)
    return out


if __name__ == "__main__":
    for d in sys.argv[1:]:
        print(json.dumps(evaluate(d), ensure_ascii=False, indent=1), flush=True)
