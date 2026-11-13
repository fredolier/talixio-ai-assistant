#!/usr/bin/env bash
# Talixio: copy everything an assistant host needs (data and config) with rsync, and put it back.
# Works for the simple install (install.sh, systemd) and for the multi-user server (Docker), or both on one host.
#
#   sudo talixio-hostbackup.sh backup  DEST   [--no-stop] [--with-models] [--stack DIR] [--dry-run]
#   sudo talixio-hostbackup.sh restore SRC    [--yes] [--stack DIR] [--dry-run]
#   sudo talixio-hostbackup.sh list    SRC
#   sudo talixio-hostbackup.sh size           [--with-models] [--stack DIR]   (what backup would copy, and how big)
#
# DEST / SRC: a local folder (/mnt/backup/talixio) or a remote one over ssh (backup@nas:/srv/talixio).
# The copy is a mirror (what was deleted here is deleted there): keep versions with UrBackup or snapshots of DEST.
#
# backup   stops the services while copying (the database is a live SQLite file: a copy taken while it is written
#          can be broken), then starts them again, even on an error or Ctrl-C. --no-stop copies while running, after
#          a consistent "talixio backup" archive (only that archive is then guaranteed whole).
#          --with-models also copies /opt/whisper.cpp (large; install.sh downloads it again otherwise).
#          --stack DIR: the Docker stack folder (deploy/docker, for its .env); found on its own when it can be.
# restore  on this host or a new one. On a new host, first run install.sh (simple) or install-stack.sh (Docker) at
#          the same version, then restore: the app comes from the install, the data and keys from the copy.
#          What restore overwrites or deletes here is moved aside to <folder>.before-restore-<date> first.
#
# What is copied:
#   simple   /var/lib/talixio (all the data, incl. nightly backups, Signal, Claude Code's sign-in)
#            /etc/talixio     (talixio.env, install.conf, secret.key: the key that opens the saved secrets)
#            /etc/caddy       (when Caddy serves Talixio, install.sh --domain)
#   docker   every talixio-* Docker volume (each person's data, the agent's fleet.json: the root of each
#            assistant's key, the admin page, the store, Caddy's certificates) and the stack's .env
set -euo pipefail

DATA="${TALIXIO_HB_DATA:-/var/lib/talixio}"   # the overrides are for tests only
ETC="${TALIXIO_HB_ETC:-/etc/talixio}"
CADDY="${TALIXIO_HB_CADDY:-/etc/caddy}"
WHISPER="${TALIXIO_HB_WHISPER:-/opt/whisper.cpp}"
APP="${TALIXIO_HB_APP:-/opt/talixio}"

say()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mxx\033[0m %s\n' "$*" >&2; exit 1; }
usage() { sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

[ $# -ge 1 ] || usage
CMD="$1"; shift
if [ "$CMD" = size ]; then TARGET=""; else [ $# -ge 1 ] || usage; TARGET="${1%/}"; shift; fi
NOSTOP=0 MODELS=0 YES=0 DRY=() STACK=""
while [ $# -gt 0 ]; do
  case "$1" in
    --no-stop) NOSTOP=1 ;; --with-models) MODELS=1 ;; --yes) YES=1 ;; --dry-run|-n) DRY=(--dry-run) ;;
    --stack) STACK="${2:?--stack needs a folder}"; shift ;;
    *) usage ;;
  esac; shift
done
[ "${TALIXIO_HB_ANYUSER:-0}" = 1 ] || [ "$(id -u)" = 0 ] || die "run it as root (sudo): the data is private to the talixio user"
command -v rsync >/dev/null || die "rsync is not installed (apt-get install rsync)"

RSYNC=(rsync -aH --numeric-ids --delete --mkpath --info=stats1 "${DRY[@]}")
remote() { case "$TARGET" in *:*) [[ "$TARGET" != /* ]] ;; *) false ;; esac; }
have_systemd() { command -v systemctl >/dev/null && systemctl list-unit-files talixio-web.service >/dev/null 2>&1 && systemctl cat talixio-web.service >/dev/null 2>&1; }
have_docker()  { command -v docker >/dev/null && docker info >/dev/null 2>&1; }
volumes()      { have_docker && docker volume ls -q --filter name='^talixio-' | grep -E '^talixio-' || true; }
find_stack() {
  [ -n "$STACK" ] && { echo "$STACK"; return; }
  have_docker || return 0
  local d; d="$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' talixio-agent 2>/dev/null || true)"
  [ -n "$d" ] && [ -f "$d/compose.yml" ] && echo "$d"
}

# ---------- stopping and starting again ----------
STOPPED_UNITS=() STOPPED_CONTAINERS=() tmp=""
stop_all() {
  if have_systemd; then
    for u in talixio-update.timer talixio-update.path talixio-worker talixio-web; do
      systemctl is-active --quiet "$u" && { systemctl stop "$u"; STOPPED_UNITS+=("$u"); }
    done
  fi
  if have_docker; then
    mapfile -t STOPPED_CONTAINERS < <(docker ps -q --filter name='^talixio-')
    [ ${#STOPPED_CONTAINERS[@]} -gt 0 ] && docker stop -t 30 "${STOPPED_CONTAINERS[@]}" >/dev/null
  fi
  [ ${#STOPPED_UNITS[@]} -gt 0 ] || [ ${#STOPPED_CONTAINERS[@]} -gt 0 ] && say "stopped: ${STOPPED_UNITS[*]:-} ${#STOPPED_CONTAINERS[@]} container(s)"
  return 0
}
start_all() {
  local i
  [ ${#STOPPED_CONTAINERS[@]} -gt 0 ] && docker start "${STOPPED_CONTAINERS[@]}" >/dev/null || true
  for (( i=${#STOPPED_UNITS[@]}-1; i>=0; i-- )); do systemctl start "${STOPPED_UNITS[$i]}" || warn "could not start ${STOPPED_UNITS[$i]}"; done
  [ ${#STOPPED_UNITS[@]} -gt 0 ] || [ ${#STOPPED_CONTAINERS[@]} -gt 0 ] && say "started again"
  STOPPED_UNITS=() STOPPED_CONTAINERS=()
  return 0
}

# ---------- backup ----------
backup() {
  local did=0; tmp="$(mktemp -d)"; trap 'start_all; rm -rf "$tmp"' EXIT; trap 'exit 130' INT TERM
  if [ "$NOSTOP" = 1 ]; then
    if [ -d "$DATA" ] && command -v talixio >/dev/null; then say "consistent archive first: talixio backup"; talixio backup || warn "talixio backup failed"; fi
    warn "--no-stop: the live database files may be copied half-written; the archive in backups/ is the safe one"
  elif [ ${#DRY[@]} -eq 0 ]; then stop_all; fi
  {
    echo "date=$(date -Is)"; echo "host=$(hostname)"
    [ -f "$APP/VERSION" ] && echo "version=$(cat "$APP/VERSION")"
    [ -d "$DATA" ] && echo "simple=1"
    echo "volumes=$(volumes | tr '\n' ' ')"
  } > "$tmp/MANIFEST"

  if [ -d "$DATA" ]; then
    say "simple install: $DATA"
    "${RSYNC[@]}" --exclude='/core.[0-9]*' "$DATA/" "$TARGET/simple/data/"   # crash dumps: see talixio doctor
    [ -d "$ETC" ] && { say "config: $ETC"; "${RSYNC[@]}" "$ETC/" "$TARGET/simple/etc/"; }
    [ -f "$ETC/secret.key" ] || warn "no $ETC/secret.key yet (it comes with version 1.35.42): run this again after the upgrade"
    if [ -f "$CADDY/Caddyfile" ] && grep -qi talixio "$CADDY/Caddyfile" 2>/dev/null; then say "Caddy: $CADDY"; "${RSYNC[@]}" "$CADDY/" "$TARGET/simple/caddy/"; fi
    if [ "$MODELS" = 1 ] && [ -d "$WHISPER" ]; then say "transcription models: $WHISPER"; "${RSYNC[@]}" "$WHISPER/" "$TARGET/simple/whisper/"; fi
    did=1
  fi
  local v mp stack
  for v in $(volumes); do
    mp="$(docker volume inspect -f '{{.Mountpoint}}' "$v")"
    say "Docker volume $v"
    "${RSYNC[@]}" --exclude='/core.[0-9]*' "$mp/" "$TARGET/docker/volumes/$v/"
    did=1
  done
  stack="$(find_stack || true)"
  if [ -n "$stack" ]; then
    say "Docker stack settings: $stack/.env"
    [ -f "$stack/.env" ] && "${RSYNC[@]}" "$stack/.env" "$TARGET/docker/stack.env"
    echo "stack=$stack" >> "$tmp/MANIFEST"
  elif [ -n "$(volumes)" ]; then warn "Docker stack folder not found: give --stack DIR to copy its .env"; fi

  [ "$did" = 1 ] || die "nothing to copy: no $DATA and no talixio-* Docker volume"
  "${RSYNC[@]}" "$tmp/MANIFEST" "$TARGET/MANIFEST"
  say "done: $TARGET"; cat "$tmp/MANIFEST"
}

# ---------- restore ----------
fetch_manifest() { rsync -a "$TARGET/MANIFEST" "$1" 2>/dev/null || die "no MANIFEST in $TARGET: is it a copy made by this script?"; }
restore() {
  local stamp; tmp="$(mktemp -d)"; stamp="$(date +%Y%m%d-%H%M%S)"
  trap 'start_all; rm -rf "$tmp"' EXIT; trap 'exit 130' INT TERM
  fetch_manifest "$tmp/MANIFEST"
  say "copy to restore:"; sed 's/^/    /' "$tmp/MANIFEST"
  local simple vols; simple="$(sed -n 's/^simple=//p' "$tmp/MANIFEST")"; vols="$(sed -n 's/^volumes=//p' "$tmp/MANIFEST")"
  if [ -f "$APP/VERSION" ] && grep -q '^version=' "$tmp/MANIFEST" && [ "$(sed -n 's/^version=//p' "$tmp/MANIFEST")" != "$(cat "$APP/VERSION")" ]; then
    warn "the copy was made with version $(sed -n 's/^version=//p' "$tmp/MANIFEST"), this host runs $(cat "$APP/VERSION"): an older app on newer data may not start"
  fi
  if [ "$YES" != 1 ] && [ ${#DRY[@]} -eq 0 ]; then
    printf 'This replaces the Talixio data and config on this host (the current ones are moved aside). Type "restore" to go on: '
    local a; read -r a; [ "$a" = restore ] || die "cancelled"
  fi
  [ ${#DRY[@]} -eq 0 ] && stop_all
  local aside=(--backup)
  if [ "$simple" = 1 ]; then
    id talixio >/dev/null 2>&1 || [ "${TALIXIO_HB_ANYUSER:-0}" = 1 ] || die "no talixio user here: run install.sh first, then restore"
    say "data -> $DATA (what it replaces: $DATA.before-restore-$stamp)"
    "${RSYNC[@]}" "${aside[@]}" --backup-dir="$DATA.before-restore-$stamp" "$TARGET/simple/data/" "$DATA/"
    say "config -> $ETC"
    "${RSYNC[@]}" "${aside[@]}" --backup-dir="$ETC.before-restore-$stamp" "$TARGET/simple/etc/" "$ETC/"
    if rsync -a --list-only "$TARGET/simple/caddy/" >/dev/null 2>&1; then
      say "Caddy -> $CADDY"; "${RSYNC[@]}" "${aside[@]}" --backup-dir="$CADDY.before-restore-$stamp" "$TARGET/simple/caddy/" "$CADDY/"
    fi
    if rsync -a --list-only "$TARGET/simple/whisper/" >/dev/null 2>&1; then
      say "transcription models -> $WHISPER"; "${RSYNC[@]}" "$TARGET/simple/whisper/" "$WHISPER/"
    fi
    if [ ${#DRY[@]} -eq 0 ] && id talixio >/dev/null 2>&1; then # a new host's talixio user may have another number
      chown -R talixio:talixio "$DATA"
      for f in "$ETC/talixio.env" "$ETC/secret.key"; do [ -f "$f" ] && { chown root:talixio "$f"; chmod 640 "$f"; }; done
    fi
    [ -f "$ETC/secret.key" ] || warn "the copy has no secret.key: if the data came from 1.35.42 or later, its secrets will not open (put the key back in $ETC/secret.key)"
  fi
  local v mp
  for v in $vols; do
    have_docker || die "the copy holds Docker volumes, and Docker does not answer here: run install-stack.sh first"
    docker volume inspect "$v" >/dev/null 2>&1 || { [ ${#DRY[@]} -eq 0 ] && docker volume create "$v" >/dev/null; }
    mp="$(docker volume inspect -f '{{.Mountpoint}}' "$v" 2>/dev/null || echo "/var/lib/docker/volumes/$v/_data")"
    say "Docker volume $v (what it replaces: $mp.before-restore-$stamp)"
    "${RSYNC[@]}" "${aside[@]}" --backup-dir="$mp.before-restore-$stamp" "$TARGET/docker/volumes/$v/" "$mp/"
  done
  local stack; stack="$(sed -n 's/^stack=//p' "$tmp/MANIFEST")"; stack="${STACK:-$stack}"
  if [ -n "$vols" ] && rsync -a --list-only "$TARGET/docker/stack.env" >/dev/null 2>&1; then
    if [ -n "$stack" ] && [ -d "$stack" ]; then say "stack settings -> $stack/.env"; "${RSYNC[@]}" "${aside[@]}" --suffix=".before-restore-$stamp" "$TARGET/docker/stack.env" "$stack/.env"
    else warn "stack folder not found: copy $TARGET/docker/stack.env to deploy/docker/.env yourself (or give --stack DIR)"; fi
  fi
  say "restored. Check it: sudo talixio doctor (Docker: admin page, or docker exec talixio-<id> talixio doctor)"
}

list() {
  local tmp; tmp="$(mktemp -d)"; fetch_manifest "$tmp/MANIFEST"; cat "$tmp/MANIFEST"; rm -rf "$tmp"
  rsync -a --list-only "$TARGET/" | sed 's/^/    /'
}

# ---------- size: what backup would copy ----------
SIZE_TOTAL=0
size_line() { # size_line PATH [LABEL] [INDENT]
  local b; b="$(du -sb --exclude="core.[0-9]*" "$1" 2>/dev/null | cut -f1)"; b="${b:-0}"
  [ -z "${3:-}" ] && SIZE_TOTAL=$((SIZE_TOTAL + b))
  printf '%s%8s  %s\n' "${3:-}" "$(numfmt --to=iec --suffix=B "$b" 2>/dev/null || echo "$b")" "${2:-$1}"
}
sizes() {
  local any=0 v mp d stack
  if [ -d "$DATA" ]; then
    echo "simple install:"
    size_line "$DATA"
    for d in "$DATA"/* "$DATA"/.[!.]*; do [ -e "$d" ] && size_line "$d" "$(basename "$d")" "            "; done | sort -k1,1 -h -r
    [ -d "$ETC" ] && size_line "$ETC"
    [ -f "$ETC/secret.key" ] || echo "          (no $ETC/secret.key yet: it comes with version 1.35.42)"
    [ -f "$CADDY/Caddyfile" ] && grep -qi talixio "$CADDY/Caddyfile" 2>/dev/null && size_line "$CADDY"
    if [ -d "$WHISPER" ]; then if [ "$MODELS" = 1 ]; then size_line "$WHISPER"; else echo "          ($WHISPER: $(du -sh "$WHISPER" 2>/dev/null | cut -f1), copied only with --with-models)"; fi; fi
    any=1
  fi
  if [ -n "$(volumes)" ]; then
    echo "Docker volumes:"
    for v in $(volumes); do mp="$(docker volume inspect -f '{{.Mountpoint}}' "$v")"; size_line "$mp" "$v"; done
    stack="$(find_stack || true)"
    if [ -n "$stack" ] && [ -f "$stack/.env" ]; then size_line "$stack/.env"; else echo "          (stack folder not found: give --stack DIR)"; fi
    any=1
  fi
  [ "$any" = 1 ] || die "nothing to copy: no $DATA and no talixio-* Docker volume"
  printf -- '--------\n%8s  total\n' "$(numfmt --to=iec --suffix=B "$SIZE_TOTAL" 2>/dev/null || echo "$SIZE_TOTAL")"
}

case "$CMD" in
  backup) backup ;; restore) restore ;; list) list ;; size) sizes ;;
  *) usage ;;
esac
