# Talixio in a container: one container per person (web app + worker), data in a volume at /data.
# The dependencies are in a separate image (deploy/docker/base.Dockerfile), built first:
#   docker build -t talixio-base:current - < deploy/docker/base.Dockerfile
#   docker build -t talixio .
#   docker run -d -v fred-data:/data -e TALIXIO_WEB_PASSWORD=... -p 8080:8080 talixio
# Several people on one server: see deploy/docker/README.md (Caddy, Ollama, admin page).
ARG BASE_IMAGE=talixio-base:current
FROM ${BASE_IMAGE}

COPY --chown=root:root . /opt/talixio
ARG VERSION_SHA=unknown VERSION_DATE= VERSION_SUBJECT=
RUN set -eux; chmod 755 /opt/talixio/deploy/docker/entrypoint.sh /caddy-config; \
    ln -s /opt/talixio/bin/talixio /usr/local/bin/talixio; install -d -o talixio -g talixio -m 755 /store; \
    php -r 'file_put_contents("/opt/talixio/VERSION.json", json_encode(["sha" => $argv[1], "short" => substr($argv[1], 0, 7), "branch" => "image", "date" => $argv[2], "subject" => $argv[3], "installed_at" => time()]));' \
      "$VERSION_SHA" "$VERSION_DATE" "$VERSION_SUBJECT"

USER talixio
