# Talixio in a container: one container per person (web app + worker), data in a volume at /data.
#   docker build -t talixio .
#   docker run -d -v fred-data:/data -e TALIXIO_WEB_PASSWORD=... -p 8080:8080 talixio
# Several people on one server: see deploy/docker/README.md (Caddy, Ollama, admin page).
ARG BASE=debian:trixie-slim

# whisper.cpp, built apart so the compilers stay out of the image: transcription on the server. The models
# are not in the image but in the shared volume talixio-whisper (install-stack.sh downloads one).
FROM ${BASE} AS whisper
ARG WITH_WHISPER=1
RUN set -eux; mkdir -p /out; \
    if [ "$WITH_WHISPER" = 1 ]; then \
      apt-get update; apt-get install -y --no-install-recommends build-essential cmake git ca-certificates; \
      { git clone -q --depth 1 https://github.com/ggml-org/whisper.cpp /src \
        && cmake -S /src -B /src/build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DWHISPER_BUILD_TESTS=OFF -DWHISPER_BUILD_EXAMPLES=ON \
        && cmake --build /src/build -j"$(nproc)" --target whisper-cli \
        && cp /src/build/bin/whisper-cli /out/; } \
      || echo "WARNING: whisper.cpp could not be built; the image has no transcription on the server"; \
    fi

FROM ${BASE}

# Optional parts (each adds size): headless browser, LibreOffice previews, sandboxed shell, Node (MCP + Claude Code),
# whisper.cpp (local transcription), signal-cli (Signal channel, x86_64 only)
ARG WITH_WHISPER=1
ARG WITH_SIGNAL=1
ARG WITH_BROWSER=1
ARG WITH_OFFICE=1
ARG WITH_SHELL=1
ARG WITH_NODE=1
ARG WITH_CLAUDE_CODE=1

ENV DEBIAN_FRONTEND=noninteractive
RUN set -eux; \
    pkgs="php-cli php-curl php-sqlite3 php-mbstring php-gd php-zip php-xml curl ca-certificates git ffmpeg poppler-utils tini tzdata"; \
    [ "$WITH_BROWSER" = 1 ] && pkgs="$pkgs chromium fonts-liberation fonts-noto-color-emoji"; \
    [ "$WITH_SHELL" = 1 ] && pkgs="$pkgs bubblewrap python3 python3-numpy python3-pandas python3-matplotlib python3-openpyxl fonts-dejavu-core"; \
    [ "$WITH_OFFICE" = 1 ] && pkgs="$pkgs libreoffice-writer-nogui libreoffice-calc-nogui libreoffice-impress-nogui fonts-crosextra-carlito fonts-crosextra-caladea fonts-liberation fonts-dejavu-core"; \
    [ "$WITH_NODE" = 1 ] && pkgs="$pkgs nodejs npm"; \
    [ "$WITH_WHISPER" = 1 ] && pkgs="$pkgs libgomp1"; \
    apt-get update; apt-get install -y --no-install-recommends $pkgs; \
    if [ "$WITH_SIGNAL" = 1 ] && [ "$(dpkg --print-architecture)" = amd64 ]; then \
      url="$(curl -fsSL https://api.github.com/repos/AsamK/signal-cli/releases/latest | grep -oE '"browser_download_url": *"[^"]*Linux-native\.tar\.gz"' | head -1 | cut -d'"' -f4 || true)"; \
      if [ -n "$url" ] && install -d /opt/signal-cli && curl -fsSL "$url" | tar -xz -C /opt/signal-cli; then \
        ln -s "$(find /opt/signal-cli -type f -name signal-cli -perm -u+x | head -1)" /usr/local/bin/signal-cli; signal-cli --version; \
      else echo "WARNING: signal-cli could not be downloaded; the image has no Signal channel"; rm -rf /opt/signal-cli; fi; \
    fi; \
    if [ "$WITH_NODE" = 1 ] && [ "$WITH_CLAUDE_CODE" = 1 ]; then npm install -g --omit=dev @anthropic-ai/claude-code && npm cache clean --force; fi; \
    rm -rf /var/lib/apt/lists/* /root/.npm; \
    useradd --uid 1000 --home-dir /data/home --shell /usr/sbin/nologin talixio; \
    install -d -o talixio -g talixio -m 750 /data /state /admin-data /caddy-config; \
    install -d /opt/whisper.cpp/build/bin /opt/whisper.cpp/models

COPY --from=whisper /out/ /opt/whisper.cpp/build/bin/
COPY --chown=root:root . /opt/talixio
ARG VERSION_SHA=unknown
ARG VERSION_DATE=
ARG VERSION_SUBJECT=
RUN set -eux; chmod 755 /opt/talixio/deploy/docker/entrypoint.sh /caddy-config; \
    ln -s /opt/talixio/bin/talixio /usr/local/bin/talixio; \
    php -r 'file_put_contents("/opt/talixio/VERSION.json", json_encode(["sha" => $argv[1], "short" => substr($argv[1], 0, 7), "branch" => "image", "date" => $argv[2], "subject" => $argv[3], "installed_at" => time()]));' \
      "$VERSION_SHA" "$VERSION_DATE" "$VERSION_SUBJECT"

# Settings come from the environment and from Settings in the app (stored in /data); keys are each person's own.
ENV TALIXIO_DATA_DIR=/data \
    TALIXIO_WEB_BIND=0.0.0.0:8080 \
    TALIXIO_MANAGED=1 \
    TALIXIO_CLAUDE_BIN=claude \
    TALIXIO_SIGNAL_CLI=signal-cli \
    HOME=/data/home \
    PHP_CLI_SERVER_WORKERS=10

USER talixio
WORKDIR /data
VOLUME /data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD curl -fsS -o /dev/null http://127.0.0.1:8080/sw.js || exit 1
ENTRYPOINT ["/usr/bin/tini", "-g", "--", "/opt/talixio/deploy/docker/entrypoint.sh"]
