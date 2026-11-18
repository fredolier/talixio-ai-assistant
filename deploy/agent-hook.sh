#!/bin/sh
# Talixio agent hook: reports this coding-agent session to your Talixio board, word for word and as it happens,
# and brings back the answers you give there (a reply, allow, deny). Needs sh and curl (macOS, Linux, WSL).
#   sh agent-hook.sh event       report an event (the hook's JSON on stdin)
#   sh agent-hook.sh permission  report a permission request, wait up to TALIXIO_WAIT seconds for allow/deny
#   sh agent-hook.sh reply       report the end of a turn, then wait in the background for a reply (exit 2 wakes the session)
#   sh agent-hook.sh send <file> <session_id>   send a file the agent made (a report, a page, a PDF) to the session's thread
# A session started from the board by agent-listen.sh carries TALIXIO_JOB, which links it to that job.
# Other agents: pipe {"session_id":"…","hook_event_name":"Stop","last_assistant_message":"…","cwd":"…"} with TALIXIO_AGENT=<name>.
U=@URL@
T=@TOKEN@
M=@MACHINE@
A="${TALIXIO_AGENT:-claude-code}"
WAIT="${TALIXIO_WAIT:-30}"
mode="${1:-event}"
if [ "$mode" = send ]; then
  f="$2"; sid="${3:-}"
  [ -f "$f" ] || { echo "talixio: no such file: $f" >&2; exit 1; }
  [ -n "$sid" ] || { echo "talixio: give the session id too: sh $0 send <file> <session_id>" >&2; exit 1; }
  out=$(mktemp 2>/dev/null || echo "/tmp/talixio-send.$$")
  trap 'rm -f "$out"' EXIT
  code=$(curl -sS -m 300 -o "$out" -w '%{http_code}' -H "Authorization: Bearer $T" -H "X-Talixio-Machine: $M" -H "X-Talixio-Name: $(basename "$f")" \
    -H 'Content-Type: application/octet-stream' --data-binary @"$f" "$U/api/agents/file?agent=$A&session=$sid" 2>&1) || { echo "talixio: Talixio could not be reached: $code" >&2; exit 1; }
  if [ "$code" = 200 ]; then
    printf 'Sent to Talixio: %s, in %s. The user can open it from the agent board.\n' \
      "$(sed -n 's/.*"name" *: *"\([^"]*\)".*/\1/p' "$out")" "$(sed -n 's/.*"folder" *: *"\([^"]*\)".*/\1/p' "$out")"
    exit 0
  fi
  echo "talixio: not sent ($code): $(sed -n 's/.*"error" *: *"\([^"]*\)".*/\1/p' "$out")" >&2
  exit 1
fi
in=$(cat)
sid=$(printf '%s' "$in" | tr '\n' ' ' | sed -n 's/.*"session_id" *: *"\([^"]*\)".*/\1/p')
[ -n "$sid" ] || exit 0
r=$(printf '%s' "$in" | curl -sS -m 10 -H "Authorization: Bearer $T" -H "X-Talixio-Machine: $M" -H 'Content-Type: application/json' \
  --data-binary @- "$U/api/agents/hook?agent=$A&job=${TALIXIO_JOB:-}" 2>/dev/null) || exit 0
seq=$(printf '%s' "$r" | sed -n 's/.*"seq" *: *\([0-9][0-9]*\).*/\1/p')
[ -n "$seq" ] || exit 0
# stopped from the board: the agent stops here
case "$r" in *'"stop":true'*) printf '%s\n' '{"continue":false,"stopReason":"Stopped from Talixio."}'; exit 0 ;; esac
# at the start of a session, the agent learns how to show the user a file it made (Claude Code adds this to its context)
case "$in" in *'"hook_event_name"'*'"SessionStart"'*) [ "$mode" = event ] && printf 'Talixio: to show the user a file you made (a report, an HTML page, a PDF, a picture), run: sh %s send <file> %s\n' "$0" "$sid" ;; esac
[ "$mode" = event ] && exit 0
out=$(mktemp 2>/dev/null || echo "/tmp/talixio-hook.$$")
trap 'rm -f "$out"' EXIT
ask() { curl -sS -m 15 -o "$out" -w '%{http_code}' -H "Authorization: Bearer $T" "$U/api/agents/wait?agent=$A&session=$sid&for=$1&seq=$seq" 2>/dev/null; }
if [ "$mode" = permission ]; then
  end=$(( $(date +%s) + WAIT ))
  while [ "$(date +%s)" -lt "$end" ]; do
    case "$(ask permission)" in
      200) cat "$out"; exit 0 ;;
      204) sleep 2 ;;
      *) exit 0 ;;   # answered in the session itself, or Talixio unreachable: the usual prompt shows
    esac
  done
  exit 0
fi
# reply: in the background (asyncRewake) for up to a day, until the session moves on
end=$(( $(date +%s) + 86400 ))
while [ "$(date +%s)" -lt "$end" ]; do
  case "$(ask reply)" in
    200) { printf 'Message from the user, sent from Talixio:\n\n'; cat "$out"; } >&2; exit 2 ;;
    204) sleep 4 ;;
    410|401|404) exit 0 ;;
    *) sleep 30 ;;   # Talixio unreachable for a moment
  esac
done
exit 0
