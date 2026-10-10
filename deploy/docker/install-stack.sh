#!/usr/bin/env bash
# Talixio for several people on one server (Docker). Run from the git clone, as root:
#   sudo deploy/docker/install-stack.sh --domain assistant.example.com --email you@example.com [--migrate fred]
#
#   --domain      the address (DNS must point to this server; ports 80 and 443 open)
#   --email       for Let's Encrypt
#   --migrate ID  move an installation made with install.sh (this server) into the assistant "ID",
#                 with its data, settings and password; the old services are stopped (not deleted)
#   --name NAME   the person's name for --migrate
#   --behind-proxy [ADDR:]PORT
#                 another reverse proxy (e.g. Caddy on another machine) does HTTPS and forwards to this server:
#                 the stack then serves plain HTTP on this port only (e.g. 8080), no certificates here.
#                 That proxy:  assistant.example.com { reverse_proxy <this server>:8080 }
# Run it again to rebuild the image from this clone and restart the stack (the data stays).
set -euo pipefail
cd "$(dirname "$0")"
STACK="$PWD"; REPO="$(cd ../.. && pwd)"
DOMAIN=""; EMAIL=""; MIGRATE=""; NAME=""; PROXY=""
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --migrate) MIGRATE="$2"; shift 2 ;;
    --name) NAME="$2"; shift 2 ;;
    --behind-proxy) PROXY="$2"; shift 2 ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
[ "$(id -u)" = 0 ] || { echo "Run as root (sudo)." >&2; exit 1; }

if ! command -v docker >/dev/null || ! docker compose version >/dev/null 2>&1; then
  say "Installing Docker"
  apt-get update -qq
  apt-get install -y -qq docker.io >/dev/null
  apt-get install -y -qq docker-compose-v2 >/dev/null 2>&1 || apt-get install -y -qq docker-compose-plugin >/dev/null
  systemctl enable -q --now docker
fi

ENV="$STACK/.env"
if [ ! -f "$ENV" ]; then
  [ -n "$DOMAIN" ] || { echo "First run: give --domain (and --email)." >&2; exit 2; }
  say "Writing $ENV"
  GIT_URL="$(git -C "$REPO" remote get-url origin 2>/dev/null || true)"
  cat > "$ENV" <<EOF
# Talixio stack. Keep this file private (it holds the agent's token).
DOMAIN=$DOMAIN
ACME_EMAIL=${EMAIL:-admin@$DOMAIN}
AGENT_TOKEN=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')
DOCKER_GID=$(getent group docker | cut -d: -f3)
# Updates from the admin page build this repository. A private GitHub repository needs a token with
# read access: https://<token>@github.com/owner/repo.git
GIT_URL=$GIT_URL
GIT_BRANCH=$(git -C "$REPO" rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)
EOF
  chmod 600 "$ENV"
fi
if [ -n "$PROXY" ]; then # HTTPS is done by another proxy: plain HTTP on this port, 443 kept to this machine
  sed -i '/^BEHIND_PROXY=/d; /^HTTP_PUBLISH=/d; /^HTTPS_PUBLISH=/d' "$ENV"
  printf 'BEHIND_PROXY=1\nHTTP_PUBLISH=%s\nHTTPS_PUBLISH=127.0.0.1:8443\n' "$PROXY" >> "$ENV"
fi
set -a; . "$ENV"; set +a

say "Building the Talixio image (a few minutes the first time)"
docker build -t talixio:current \
  --build-arg VERSION_SHA="$(git -C "$REPO" rev-parse HEAD 2>/dev/null || echo unknown)" \
  --build-arg VERSION_DATE="$(git -C "$REPO" log -1 --format=%cI 2>/dev/null || true)" \
  --build-arg VERSION_SUBJECT="$(git -C "$REPO" log -1 --format=%s 2>/dev/null || true)" "$REPO"

if [ -n "$MIGRATE" ]; then
  [ -d /var/lib/talixio ] || { echo "No installation in /var/lib/talixio to migrate." >&2; exit 1; }
  say "Moving the current installation into the assistant \"$MIGRATE\""
  systemctl disable --now talixio-web talixio-worker talixio-update.path talixio-update.timer 2>/dev/null || true
  docker volume create "talixio-$MIGRATE" >/dev/null
  docker run --rm --user 0 --entrypoint sh -v /var/lib/talixio:/old:ro -v "talixio-$MIGRATE:/data" talixio:current -c '
    cp -a /old/. /data/ && mkdir -p /data/home
    for f in .claude .claude.json .config .cache; do [ -e "/data/$f" ] && mv "/data/$f" /data/home/; done
    rm -rf /data/.local /data/signal/.cache 2>/dev/null; chown -R 1000:1000 /data'
  [ -f /etc/talixio/talixio.env ] && docker run --rm --entrypoint php -v /etc/talixio/talixio.env:/old.env:ro -v "talixio-$MIGRATE:/data" talixio:current /opt/talixio/deploy/docker/import-env.php /old.env
  echo "    data copied (the original stays in /var/lib/talixio)"
fi

# the stack takes ports 80/443: a Caddy installed by install.sh has to make room
if systemctl is-active -q caddy 2>/dev/null; then
  say "Stopping the Caddy of the previous installation (the stack has its own)"
  systemctl disable --now caddy
fi
if systemctl is-active -q ollama 2>/dev/null; then
  say "Stopping the Ollama of the previous installation (the stack has its own, shared by everyone)"
  systemctl disable --now ollama
fi

say "Starting the stack"
docker compose -f "$STACK/compose.yml" --env-file "$ENV" up -d --remove-orphans
for i in $(seq 1 30); do docker exec talixio-agent php -r 'exit(0);' 2>/dev/null && break; sleep 1; done

if [ -n "$MIGRATE" ]; then
  docker exec talixio-agent php /opt/talixio/deploy/docker/fleetctl.php adopt "$MIGRATE" "$NAME"
fi
docker exec talixio-agent php /opt/talixio/deploy/docker/fleetctl.php reload >/dev/null 2>&1 || true
# the existing assistants move to the image just built
docker exec talixio-agent php -r 'require "/opt/talixio/src/autoload.php"; $f = new Talixio\Fleet("/state"); foreach (array_keys($f->state()["instances"]) as $id) { $f->recreate($id); echo "    $id restarted on the new image\n"; }'

say "Downloading the embedding model for memory search (in the background)"
( docker exec talixio-ollama ollama pull qwen3-embedding:0.6b >/dev/null 2>&1 && echo "    model ready" ) &

CODE="$(docker logs talixio-admin 2>&1 | grep -o 'setup code: [A-Z0-9]*' | tail -1 | cut -d' ' -f3 || true)"
echo
say "Done"
[ "${BEHIND_PROXY:-0}" = 1 ] && echo "  Your proxy:  $DOMAIN { reverse_proxy <this server>:${HTTP_PUBLISH##*:} }   (HTTPS stays there)"
echo "  Admin page:  https://$DOMAIN/admin/"
[ -n "$CODE" ] && echo "  Setup code:  $CODE   (first visit: you choose the admin password and scan the authenticator code)"
[ -n "$MIGRATE" ] && echo "  Your assistant: https://$DOMAIN/$MIGRATE/  (same password as before; install the app again on your phone from this address)"
echo "  Status:      docker compose -f $STACK/compose.yml ps"
echo "  Logs:        docker logs -f talixio-<person>"
wait
