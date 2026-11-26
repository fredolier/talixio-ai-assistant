#!/bin/sh
# Talixio agent listener: lets your Talixio board start and stop Claude Code sessions on this machine.
# Leave it running (a terminal, tmux, or a login item). Needs sh, curl and Claude Code with the agent hooks.
#   sh agent-listen.sh
# It asks the board every few seconds for work. A session starts only in a folder where a session already
# ran on this machine (the board offers no other) and, on this side, only under the folders it may use: TALIXIO_ROOT,
# else the ones chosen when it was installed, else your home folder. Several folders: separate them with ':'
# (TALIXIO_ROOT="$HOME:/app/projects"). Check one before starting a session there: sh agent-listen.sh --check <folder> It runs `claude -p` there with the prompt, and keeps the process ids in ~/.talixio/jobs: a stop
# from the board names a job, never a process, so only the sessions this listener started can be ended here.
# A reply from the board to a session it started continues it with `claude -p --resume <session>`: the reply is
# the session's next prompt, as the first one was.
# Settings: TALIXIO_CLAUDE (the claude command), TALIXIO_ROOT, TALIXIO_CLAUDE_ARGS (e.g. --permission-mode acceptEdits).
U=@URL@
T=@TOKEN@
# 1.56.1: installed by the one-line command, the token is not in this file but in ~/.talixio/agent.env (yours only, 600)
[ -n "$T" ] || T="${TALIXIO_TOKEN:-}"
[ -n "$T" ] || T=$(sed -n "s/^TALIXIO_TOKEN='\([0-9a-f]*\)'\$/\1/p" "$HOME/.talixio/agent.env" 2>/dev/null)
M=@MACHINE@
CLAUDE="${TALIXIO_CLAUDE:-claude}"
R=@ROOTS@
ROOT="${TALIXIO_ROOT:-${R:-$HOME}}"
# allowed <real folder>: under one of the ROOT folders (their real paths: no way out with .. or a link)
allowed() {
  _ok=; _ifs=$IFS; IFS=:; set -f
  for _r in $ROOT; do
    case "$_r" in '~') _r=$HOME ;; '~/'*) _r="$HOME/${_r#'~/'}" ;; esac
    _rr=$(cd "$_r" 2>/dev/null && pwd -P) || continue
    case "$1" in "$_rr"|"$_rr"/*) _ok=1 ;; esac
    [ "$_rr" = / ] && _ok=1
  done
  IFS=$_ifs; set +f
  [ -n "$_ok" ]
}
refused() { # the folder as asked
  echo "Refused: $1 is not under the folders this listener may use ($ROOT)."
  echo "To allow it, restart the listener with that folder added, e.g.: TALIXIO_ROOT='$ROOT:$1' sh ~/.talixio/agent-listen.sh"
  echo "(or install it again from the board, with the folder in \"Folders it may start sessions in\")."
}
if [ "$1" = --check ]; then
  real=$(cd "$2" 2>/dev/null && pwd -P)
  if [ -z "$real" ]; then echo "No such folder on this machine: $2"; exit 2; fi
  if allowed "$real"; then echo "Allowed: the board can start sessions in $2."; exit 0; fi
  refused "$2"; exit 1
fi
D="${TALIXIO_HOME:-$HOME/.talixio}/jobs"
mkdir -p "$D" || exit 1
out=$(mktemp 2>/dev/null || echo "/tmp/talixio-listen.$$")
trap 'rm -f "$out"; exit 0' INT TERM
trap 'rm -f "$out"' EXIT
report() { # job state [file with the output] [pid]
  if [ -n "$3" ] && [ -f "$3" ]; then tail -c 4000 "$3"; fi | curl -sS -m 10 -o "$out.r" -H "Authorization: Bearer $T" -H "X-Talixio-Machine: $M" \
    --data-binary @- "$U/api/agents/job?id=$1&state=$2&pid=${4:-0}" 2>/dev/null
  grep -q '"stop":true' "$out.r" 2>/dev/null && [ -n "$4" ] && kill "$4" 2>/dev/null
  rm -f "$out.r"
}
echo "Talixio listener for $M: waiting for work from $U (Ctrl-C to stop). Folders it may use: $ROOT"
while :; do
  for f in "$D"/*.pid; do # sessions that ended
    [ -f "$f" ] || continue
    id=$(basename "$f" .pid)
    kill -0 "$(cat "$f")" 2>/dev/null && continue
    report "$id" exited "$D/$id.log"; rm -f "$f" "$D/$id.prompt"
  done
  code=$(curl -sS -m 20 -o "$out" -w '%{http_code}' -H "Authorization: Bearer $T" -H "X-Talixio-Machine: $M" "$U/api/agents/next" 2>/dev/null)
  case "$code" in
    200)
      kind=$(sed -n 1p "$out"); id=$(sed -n 2p "$out")
      case "$id" in ''|*[!0-9]*) sleep 5; continue ;; esac
      if [ "$kind" = check ]; then # 1.57.0: an objective's checks, in its folder; one command per line, plain words only
        dir=$(sed -n 3p "$out"); real=$(cd "$dir" 2>/dev/null && pwd -P)
        sed '1,3d' "$out" > "$D/check-$id.cmds"
        (
          n=0
          while IFS= read -r c || [ -n "$c" ]; do
            case "$c" in ''|*[!A-Za-z0-9_./:=,@+\ -]*) rc=126; o="Refused by the listener: not a plain command." ;;
              *) if [ -z "$real" ] || ! allowed "$real"; then rc=126; o=$(refused "$dir")
                 else o=$(cd "$real" && sh -c "$c" 2>&1 < /dev/null); rc=$?; fi ;;
            esac
            printf '=== %s %s\n%s\n' "$n" "$rc" "$(printf '%s' "$o" | tail -c 2500)"
            n=$((n + 1))
          done < "$D/check-$id.cmds" > "$D/check-$id.out"
          curl -sS -m 20 -o /dev/null -H "Authorization: Bearer $T" -H "X-Talixio-Machine: $M" --data-binary @"$D/check-$id.out" "$U/api/agents/check?id=$id" 2>/dev/null
          rm -f "$D/check-$id.cmds" "$D/check-$id.out"
        ) &
        echo "Checking objective $id in $dir."
      elif [ "$kind" = stop ]; then
        [ -f "$D/$id.pid" ] && kill "$(cat "$D/$id.pid")" 2>/dev/null
      elif [ "$kind" = start ] || [ "$kind" = resume ]; then
        dir=$(sed -n 3p "$out"); sid=
        if [ "$kind" = resume ]; then sid=$(sed -n 4p "$out"); sed '1,4d' "$out" > "$D/$id.prompt"; else sed '1,3d' "$out" > "$D/$id.prompt"; fi
        real=$(cd "$dir" 2>/dev/null && pwd -P)
        if [ "$kind" = resume ] && case "$sid" in ''|*[!A-Za-z0-9_-]*) true ;; *) false ;; esac; then
          echo "Not a session id this listener resumes: $sid" > "$D/$id.log"; report "$id" failed "$D/$id.log"
        elif [ "$kind" = resume ] && [ -f "$D/$id.pid" ] && kill -0 "$(cat "$D/$id.pid")" 2>/dev/null; then
          # still running (Claude Code may wait for its background tasks): the board keeps the reply until it ends
          echo "Job $id is still running (process $(cat "$D/$id.pid")): the reply goes when it ends."
          report "$id" busy "" "$(cat "$D/$id.pid")"
        elif [ -z "$real" ]; then echo "No such folder on this machine: $dir" > "$D/$id.log"; report "$id" failed "$D/$id.log"
        elif ! allowed "$real"; then refused "$dir" > "$D/$id.log"; report "$id" failed "$D/$id.log"
        elif ! command -v "$CLAUDE" >/dev/null 2>&1; then echo "Claude Code ($CLAUDE) is not installed or not on the PATH of this listener." > "$D/$id.log"; report "$id" failed "$D/$id.log"
        else
          # shellcheck disable=SC2086
          if [ -n "$sid" ]; then
            (cd "$real" && TALIXIO_JOB=$id && export TALIXIO_JOB && exec "$CLAUDE" -p --resume "$sid" $TALIXIO_CLAUDE_ARGS < "$D/$id.prompt" > "$D/$id.log" 2>&1) &
          else
            (cd "$real" && TALIXIO_JOB=$id && export TALIXIO_JOB && exec "$CLAUDE" -p $TALIXIO_CLAUDE_ARGS < "$D/$id.prompt" > "$D/$id.log" 2>&1) &
          fi
          pid=$!
          echo "$pid" > "$D/$id.pid"
          echo "$( [ -n "$sid" ] && echo Resumed || echo Started ) job $id in $dir (process $pid)."
          report "$id" started "" "$pid"
        fi
      fi
      continue ;;
    204) ;;
    401) echo "The board refused this listener's token: download it again (Connect a machine)." >&2; sleep 60 ;;
    *) sleep 10 ;;
  esac
  sleep 3
done
