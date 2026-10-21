# The dependencies of the Talixio image (system packages, chromium, LibreOffice, whisper.cpp, signal-cli,
# sqlite-vec, Claude Code), without the application. Long to build, so built only when this file changes:
# its tag is the start of this file's SHA-1 (talixio-base:<12 hex>), see install-stack.sh and Fleet::update.
# The application (../../Dockerfile) starts from it and only copies the code: an update takes seconds.
#   docker build -t talixio-base:current - < deploy/docker/base.Dockerfile
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
# whisper.cpp (local transcription), signal-cli (Signal channel), sqlite-vec (fast search by meaning)
ARG WITH_WHISPER=1
ARG WITH_SIGNAL=1
ARG WITH_BROWSER=1
ARG WITH_OFFICE=1
ARG WITH_SHELL=1
ARG WITH_NODE=1
ARG WITH_CLAUDE_CODE=1
ARG WITH_SQLITE_VEC=1
ARG SQLITE_VEC_VERSION=0.1.9

ENV DEBIAN_FRONTEND=noninteractive
# signal-cli: the native build (no Java) needs x86_64 and a recent processor (x86-64-v2 or more); a virtual
# machine with an old processor model gets the Java build instead
RUN set -eux; \
    pkgs="php-cli php-curl php-sqlite3 php-mbstring php-gd php-zip php-xml curl ca-certificates git ffmpeg poppler-utils tini tzdata"; \
    [ "$WITH_BROWSER" = 1 ] && pkgs="$pkgs chromium fonts-liberation fonts-noto-color-emoji"; \
    [ "$WITH_SHELL" = 1 ] && pkgs="$pkgs bubblewrap python3 python3-numpy python3-pandas python3-matplotlib python3-openpyxl fonts-dejavu-core"; \
    [ "$WITH_OFFICE" = 1 ] && pkgs="$pkgs libreoffice-writer-nogui libreoffice-calc-nogui libreoffice-impress-nogui fonts-crosextra-carlito fonts-crosextra-caladea fonts-liberation fonts-dejavu-core"; \
    [ "$WITH_NODE" = 1 ] && pkgs="$pkgs nodejs npm"; \
    [ "$WITH_WHISPER" = 1 ] && pkgs="$pkgs libgomp1"; \
    apt-get update; apt-get install -y --no-install-recommends $pkgs; \
    # PHP2Shell (CVE-2026-109618, CVE-2026-109619, 2026-10-21): refuse to build with a PHP older than the fix,
    # and turn off the upload-progress handler (one of the two attack paths; Talixio does not use it)
    v="$(dpkg-query -W -f '${Version}' php8.4-cli 2>/dev/null || true)"; [ -z "$v" ] || dpkg --compare-versions "$v" ge 8.4.27-1~deb13u1 || { echo "PHP $v has PHP2Shell: the security update is missing" >&2; exit 1; }; \
    v="$(dpkg-query -W -f '${Version}' php8.2-cli 2>/dev/null || true)"; [ -z "$v" ] || dpkg --compare-versions "$v" ge 8.2.35-1~deb12u1 || { echo "PHP $v has PHP2Shell: the security update is missing" >&2; exit 1; }; \
    for d in /etc/php/*/cli/conf.d; do echo 'session.upload_progress.enabled=0' > "$d/90-talixio-security.ini"; done; \
    if [ "$WITH_SIGNAL" = 1 ]; then \
      assets="$(curl -fsSL https://api.github.com/repos/AsamK/signal-cli/releases/latest | grep -oE '"browser_download_url": *"[^"]*\.tar\.gz"' | cut -d'"' -f4 || true)"; \
      native="$(echo "$assets" | grep -- '-Linux-native\.tar\.gz$' | head -1)"; java="$(echo "$assets" | grep -v -- '-Linux' | grep -E '/signal-cli-[0-9.]+\.tar\.gz$' | head -1)"; \
      install -d /opt/signal-cli; \
      if [ -n "$native" ] && [ "$(dpkg --print-architecture)" = amd64 ] && curl -fsSL "$native" | tar -xz -C /opt/signal-cli \
         && "$(find /opt/signal-cli -type f -name signal-cli -perm -u+x | head -1)" --version; then \
        ln -s "$(find /opt/signal-cli -type f -name signal-cli -perm -u+x | head -1)" /usr/local/bin/signal-cli; \
      else \
        rm -rf /opt/signal-cli; install -d /opt/signal-cli; echo "signal-cli: native build unusable here, using the Java build"; \
        if [ -n "$java" ] && apt-get install -y --no-install-recommends default-jre-headless && curl -fsSL "$java" | tar -xz -C /opt/signal-cli --strip-components=1 \
           && /opt/signal-cli/bin/signal-cli --version; then \
          ln -s /opt/signal-cli/bin/signal-cli /usr/local/bin/signal-cli; \
        else echo "WARNING: signal-cli could not be installed; the image has no Signal channel"; rm -rf /opt/signal-cli; fi; \
      fi; \
    fi; \
    if [ "$WITH_SQLITE_VEC" = 1 ]; then \
      v="$SQLITE_VEC_VERSION"; arch="$(uname -m)"; \
      if curl -fsSL "https://github.com/asg017/sqlite-vec/releases/download/v$v/sqlite-vec-$v-loadable-linux-$arch.tar.gz" | tar -xz -C /tmp \
         && install -D -m 644 /tmp/vec0.so /usr/local/lib/sqlite-vec/vec0.so \
         && for d in /etc/php/*/cli/conf.d; do echo 'sqlite3.extension_dir=/usr/local/lib/sqlite-vec' > "$d/30-sqlite-vec.ini"; done \
         && php -r '$d = new SQLite3(":memory:"); $d->loadExtension("vec0.so"); echo "sqlite-vec ", $d->querySingle("select vec_version()"), "\n";'; then :; \
      else echo "WARNING: sqlite-vec not installed; search by meaning compares vectors in PHP (slower)"; rm -rf /usr/local/lib/sqlite-vec /etc/php/*/cli/conf.d/30-sqlite-vec.ini; fi; \
      rm -f /tmp/vec0.so; \
    fi; \
    if [ "$WITH_NODE" = 1 ] && [ "$WITH_CLAUDE_CODE" = 1 ]; then npm install -g --omit=dev @anthropic-ai/claude-code && npm cache clean --force; fi; \
    rm -rf /var/lib/apt/lists/* /root/.npm; \
    useradd --uid 1000 --home-dir /data/home --shell /usr/sbin/nologin talixio; \
    install -d -o talixio -g talixio -m 750 /data /state /admin-data /caddy-config; \
    install -d /opt/whisper.cpp/build/bin /opt/whisper.cpp/models

COPY --from=whisper /out/ /opt/whisper.cpp/build/bin/

# Settings come from the environment and from Settings in the app (stored in /data); keys are each person's own.
ENV TALIXIO_DATA_DIR=/data \
    TALIXIO_WEB_BIND=0.0.0.0:8080 \
    TALIXIO_MANAGED=1 \
    TALIXIO_CLAUDE_BIN=claude \
    TALIXIO_SIGNAL_CLI=signal-cli \
    HOME=/data/home \
    PHP_CLI_SERVER_WORKERS=10

# still root here: the application image (../../Dockerfile) copies the code, then switches to USER talixio
WORKDIR /data
VOLUME /data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD curl -fsS -o /dev/null http://127.0.0.1:8080/sw.js || exit 1
ENTRYPOINT ["/usr/bin/tini", "-g", "--", "/opt/talixio/deploy/docker/entrypoint.sh"]
