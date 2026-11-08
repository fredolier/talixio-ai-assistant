# The optional "models" service: Google TimesFM 2.5 and Amazon Chronos-2 (time-series forecasting) behind a small HTTP server.
# Built by install-stack.sh --with-models. CPU only (about 200M parameters: a forecast takes well under a second
# once loaded). The weights (about 1 GB) are downloaded from Hugging Face on first start into the models volume.
FROM python:3.12-slim
ENV PIP_NO_CACHE_DIR=1 PYTHONUNBUFFERED=1 HF_HOME=/models/hf
RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates && rm -rf /var/lib/apt/lists/*
RUN pip install --extra-index-url https://download.pytorch.org/whl/cpu torch numpy huggingface_hub safetensors \
 && git clone --depth 1 https://github.com/google-research/timesfm.git /opt/timesfm \
 && pip install -e "/opt/timesfm[torch]" \
 && pip install "chronos-forecasting>=2.0" "pandas[pyarrow]"
COPY deploy/models/server.py /opt/server.py
RUN useradd -m models && mkdir -p /models/hf && chown -R models /models
USER models
EXPOSE 8765
HEALTHCHECK --interval=60s --timeout=5s CMD python -c "import urllib.request;urllib.request.urlopen('http://127.0.0.1:8765/health',timeout=3)" || exit 1
CMD ["python", "/opt/server.py"]
