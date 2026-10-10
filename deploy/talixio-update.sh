#!/usr/bin/env bash
# Talixio updates, run as root by systemd (talixio-update.service).
#   check     fetch the git repository and list new commits
#   update    backup, fast-forward the repository, run install.sh again, restart
#   rollback  go back to the previously installed version
#   auto      what systemd runs: handle a request from the web app, or a periodic check
#             (and install at night when automatic updates are on)
set -euo pipefail

# install.sh replaces this file while an update runs: work from a private copy
if [ "${TALIXIO_UPDATE_COPY:-}" != 1 ]; then
  tmp="$(mktemp /tmp/talixio-update.XXXXXX)"; cp "$0" "$tmp"; chmod 700 "$tmp"
  TALIXIO_UPDATE_COPY=1 exec "$tmp" "$@"
fi
trap 'rm -f "$0"' EXIT

CONF=/etc/talixio/install.conf
DATA=/var/lib/talixio
APP=/opt/talixio
STATUS="$DATA/update-status.json"
LOG="$DATA/update.log"
[ -f "$CONF" ] || { echo "no $CONF: install with install.sh first" >&2; exit 1; }
# shellcheck disable=SC1090
. "$CONF"   # SRC OWNER BRANCH ARGS

asowner() { runuser -u "$OWNER" -- "$@"; }
git_src() { asowner git -C "$SRC" "$@"; }

# status file for the web app: state message [extra json fields from the commit list]
status() {
  local state="$1" msg="${2:-}"
  php -r '
    [$f, $state, $msg, $current, $latest, $log, $commits, $app] = array_slice($argv, 1);
    $old = json_decode((string)@file_get_contents($f), true) ?: [];
    $list = [];
    foreach (array_filter(explode("\n", $commits)) as $l) { $p = explode("\t", $l, 3); if (count($p) === 3) $list[] = ["sha" => $p[0], "date" => $p[1], "subject" => $p[2]]; }
    $s = array_merge($old, ["state" => $state, "message" => $msg, "updated_at" => time()]);
    if ($current !== "") $s["current"] = $current;
    if ($latest !== "") { $s["latest"] = $latest; $s["commits"] = $list; $s["behind"] = count($list); $s["checked_at"] = time(); }
    $s["log"] = mb_substr((string)@file_get_contents($log), -6000);
    $s["can_rollback"] = is_dir($app . ".prev");
    file_put_contents("$f.tmp", json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); rename("$f.tmp", $f); chmod($f, 0644);
  ' "$STATUS" "$state" "$msg" "${CURRENT:-}" "${LATEST:-}" "$LOG" "${COMMITS:-}" "$APP"
}

installed_sha() { php -r '$v = json_decode((string)@file_get_contents($argv[1]), true); echo $v["sha"] ?? "";' "$APP/VERSION.json"; }

check() {
  CURRENT="$(installed_sha)"
  status checking "Checking for updates…"
  if ! timeout 120 runuser -u "$OWNER" -- git -C "$SRC" fetch --quiet origin "$BRANCH" 2>>"$LOG"; then
    status failed "Could not reach the repository (git fetch failed: network, or credentials of user $OWNER)."; return 1
  fi
  LATEST="$(git_src rev-parse "origin/$BRANCH")"
  local range="origin/$BRANCH"
  if [ -n "$CURRENT" ] && git_src cat-file -e "$CURRENT^{commit}" 2>/dev/null; then range="$CURRENT..origin/$BRANCH"; else range="HEAD..origin/$BRANCH"; fi
  COMMITS="$(git_src log --no-merges --format='%h%x09%ad%x09%s' --date=short "$range" | head -50)"
  if [ "$LATEST" = "$CURRENT" ] || [ -z "$COMMITS" ]; then status up_to_date "You have the latest version."; else
    NEXT="$(git_src show "origin/$BRANCH:VERSION" 2>/dev/null | head -1 | tr -cd '0-9A-Za-z.-')"
    N="$(printf '%s\n' "$COMMITS" | grep -c .)"
    if [ -n "$NEXT" ] && [ "$NEXT" != "$(cat "$APP/VERSION" 2>/dev/null)" ]; then status available "Version $NEXT is available ($N new change(s))."; else status available "$N new change(s) available."; fi
  fi
}

update() {
  : > "$LOG"
  CURRENT="$(installed_sha)"
  status updating "Saving a backup…"
  { echo "== backup"; "$APP/deploy/talixio-cli" backup; } >>"$LOG" 2>&1 || { status failed "Backup failed: update cancelled (see the log)."; return 1; }
  status updating "Downloading the new version…"
  if ! { echo "== git"; timeout 300 runuser -u "$OWNER" -- git -C "$SRC" fetch origin "$BRANCH" && git_src merge --ff-only "origin/$BRANCH"; } >>"$LOG" 2>&1; then
    status failed "The repository has local changes or diverged from origin/$BRANCH: update it by hand (git pull) and run install.sh."; return 1
  fi
  status updating "Installing… the app restarts in a moment."
  # shellcheck disable=SC2086
  if ! { echo "== install.sh $ARGS"; eval "bash \"$SRC/install.sh\" $ARGS"; } >>"$LOG" 2>&1; then
    status failed "install.sh failed (see the log). You can restore the previous version."; return 1
  fi
  CURRENT="$(installed_sha)"; LATEST="$CURRENT"; COMMITS=""
  status done "Updated to $(git_src log -1 --format='%h: %s')."
}

rollback() {
  [ -d "$APP.prev" ] || { status failed "No previous version to restore."; return 1; }
  status updating "Restoring the previous version…"
  rm -rf "$APP.failed"; mv "$APP" "$APP.failed"; mv "$APP.prev" "$APP"
  systemctl restart talixio-worker talixio-web >>"$LOG" 2>&1 || true
  CURRENT="$(installed_sha)"
  status rolled_back "Previous version restored. Run a check to see available updates."
}

auto_update_on() {
  php -r '$s = json_decode((string)@file_get_contents($argv[1]), true) ?: []; if (isset($s["TALIXIO_AUTO_UPDATE"])) { echo $s["TALIXIO_AUTO_UPDATE"]; exit; }
    foreach (@file($argv[2]) ?: [] as $l) if (preg_match("/^TALIXIO_AUTO_UPDATE=(.*)$/", trim($l), $m)) echo $m[1];' "$DATA/settings.json" /etc/talixio/talixio.env
}

case "${1:-auto}" in
  check) check ;;
  update) update ;;
  rollback) rollback ;;
  auto)
    if [ -f "$DATA/update.request" ]; then rm -f "$DATA/update.request" "$DATA/check.request"; update
    elif [ -f "$DATA/rollback.request" ]; then rm -f "$DATA/rollback.request"; rollback
    elif [ -f "$DATA/check.request" ]; then rm -f "$DATA/check.request"; check
    else
      check || exit 0
      hour="$(date +%-H)"
      if [ "$(auto_update_on)" = 1 ] && [ "$hour" -ge 2 ] && [ "$hour" -le 5 ] && grep -q '"state":"available"' "$STATUS"; then update; fi
    fi ;;
  *) echo "usage: $0 check|update|rollback|auto" >&2; exit 2 ;;
esac
