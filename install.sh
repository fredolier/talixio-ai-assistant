#!/usr/bin/env bash
# Install or update Talixio on Debian/Ubuntu.
#   sudo ./install.sh                         first install, or update after `git pull`
#   sudo ./install.sh --domain bot.example.com   also set up HTTPS with Caddy (DNS must point here)
#   sudo ./install.sh --with-claude-code      also install the Claude Code CLI (use a Claude Pro/Max plan)
set -euo pipefail

DOMAIN="" ; WITH_CLAUDE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="${2:?--domain needs a value}"; shift 2 ;;
    --with-claude-code) WITH_CLAUDE=1; shift ;;
    -h|--help) sed -n '2,6p' "$0"; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done
[ "$(id -u)" -eq 0 ] || exec sudo "$0" "$@"

SRC="$(cd "$(dirname "$0")" && pwd)"
APP=/opt/talixio DATA=/var/lib/talixio ETC=/etc/talixio ENV=/etc/talixio/talixio.env
say() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }

say "Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq php-cli php-curl php-sqlite3 php-mbstring rsync curl ca-certificates >/dev/null
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { echo "PHP 8.2+ required (found $(php -r 'echo PHP_VERSION;')). Use Debian 12+ or Ubuntu 24.04+." >&2; exit 1; }

say "Creating user and directories"
id talixio >/dev/null 2>&1 || useradd --system --home-dir "$DATA" --shell /usr/sbin/nologin talixio
install -d -o talixio -g talixio -m 750 "$DATA"
install -d -m 755 "$ETC"

say "Copying the app to $APP"
rsync -a --delete --exclude .git --exclude data --exclude '*.deb' "$SRC/" "$APP/"
chown -R root:root "$APP"
ln -sf "$APP/deploy/talixio-cli" /usr/local/bin/talixio

NEW_PASSWORD=""
if [ ! -f "$ENV" ]; then
  say "Writing default config to $ENV"
  NEW_PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)"
  sed "s|^TALIXIO_WEB_PASSWORD=.*|TALIXIO_WEB_PASSWORD=$NEW_PASSWORD|" "$SRC/config/talixio.env.example" > "$ENV"
  TZ_GUESS="$(timedatectl show -p Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null || echo UTC)"
  sed -i "s|^TALIXIO_TIMEZONE=.*|TALIXIO_TIMEZONE=${TZ_GUESS:-UTC}|" "$ENV"
fi
chown root:talixio "$ENV"; chmod 640 "$ENV"

if [ "$WITH_CLAUDE" = 1 ]; then
  say "Installing Claude Code CLI for the talixio user"
  runuser -u talixio -- env HOME="$DATA" bash -c 'curl -fsSL https://claude.ai/install.sh | bash' || echo "Claude Code install failed; see https://docs.claude.com/en/docs/claude-code/setup" >&2
fi

say "Installing services"
install -m 644 "$APP/deploy/talixio-worker.service" "$APP/deploy/talixio-web.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable -q talixio-worker talixio-web
systemctl restart talixio-worker talixio-web

BIND="$(grep -E '^TALIXIO_WEB_BIND=' "$ENV" | cut -d= -f2-)"; BIND="${BIND:-127.0.0.1:8080}"
PORT="${BIND##*:}"
URL="http://$BIND"

if [ -n "$DOMAIN" ]; then
  say "Setting up HTTPS for $DOMAIN with Caddy"
  apt-get install -y -qq caddy >/dev/null
  CADDYFILE=/etc/caddy/Caddyfile
  if [ ! -s "$CADDYFILE" ] || grep -q '# managed by talixio' "$CADDYFILE" || grep -qE '^:80\s*\{' "$CADDYFILE"; then
    printf '# managed by talixio\n%s {\n\treverse_proxy 127.0.0.1:%s\n}\n' "$DOMAIN" "$PORT" > "$CADDYFILE"
    systemctl reload caddy || systemctl restart caddy
    URL="https://$DOMAIN"
  else
    echo "Your $CADDYFILE is customised; add this block yourself:"
    printf '  %s {\n    reverse_proxy 127.0.0.1:%s\n  }\n' "$DOMAIN" "$PORT"
  fi
fi

sleep 1
echo
say "Done. Status:"
systemctl --no-pager --lines=0 status talixio-worker talixio-web | grep -E '●|Active:' || true
echo
echo "  Web app:   $URL"
[ -n "$NEW_PASSWORD" ] && echo "  Password:  $NEW_PASSWORD   (stored in $ENV)"
case "$BIND" in 127.0.0.1:*|localhost:*) [ -z "$DOMAIN" ] && echo "  From your computer: ssh -L $PORT:127.0.0.1:$PORT <user>@<this-vm>  then open http://localhost:$PORT" ;; esac
echo "  Config:    sudo nano $ENV   then  sudo systemctl restart talixio-worker talixio-web"
echo "  Check:     sudo talixio doctor"
echo "  Logs:      journalctl -u talixio-worker -u talixio-web -f"
echo "  Update:    git pull && sudo ./install.sh"
