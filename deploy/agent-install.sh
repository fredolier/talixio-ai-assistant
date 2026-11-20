#!/bin/sh
# Talixio agent installer (1.48.0): connects this machine's Claude Code to your Talixio board, in one command:
#   curl -fsSL '<your Talixio>/api/agents/install?c=<one-time code>' | sh
# It saves ~/.talixio/agent-hook.sh (and the listener, if asked), then adds the board's hooks to
# ~/.claude/settings.json: your other hooks and settings are kept, a backup is made first, and running it again
# replaces the Talixio hooks instead of adding them twice. Needs sh, curl, and python3, node or jq for the merge.
# The code works once, for 15 minutes. This script holds the board's address and token: keep it to your machines.
# Everything runs in main(), called on the last line: a download cut short runs nothing.

main() {
  set -e
  D="$HOME/.talixio"
  C="${CLAUDE_CONFIG_DIR:-$HOME/.claude}"
  S="$C/settings.json"
  umask 077
  mkdir -p "$D" "$C"

  cat > "$D/agent-hook.sh" <<'TALIXIO_HOOK_EOF'
@HOOK@
TALIXIO_HOOK_EOF
  chmod 700 "$D/agent-hook.sh"
  echo "Talixio: saved $D/agent-hook.sh (machine: @MACHINE_SHOWN@)."
  if [ @LISTENER@ = 1 ]; then
    cat > "$D/agent-listen.sh" <<'TALIXIO_LISTEN_EOF'
@LISTEN@
TALIXIO_LISTEN_EOF
    chmod 700 "$D/agent-listen.sh"
    echo "Talixio: saved $D/agent-listen.sh (it may start sessions in: @ROOTS_SHOWN@)."
  fi

  H=$(mktemp 2>/dev/null || echo "$D/hooks.$$.json")
  trap 'rm -f "$H" "$S.talixio-new"' EXIT
  cat > "$H" <<'TALIXIO_JSON_EOF'
@HOOKS_JSON@
TALIXIO_JSON_EOF

  if [ -s "$S" ]; then
    cp "$S" "$S.talixio-backup"
    echo "Talixio: your settings were saved first as $S.talixio-backup."
  fi
  tool="${TALIXIO_MERGE:-}"
  if [ -z "$tool" ]; then
    for t in python3 node jq; do command -v "$t" >/dev/null 2>&1 && { tool=$t; break; }; done
  fi
  # the merge: ours replace any earlier Talixio hook (a command naming .talixio/agent-hook.sh); every other hook stays
  case "$tool" in
    python3) python3 -I -c '
import json, os, sys
s, h, out = sys.argv[1:4]
cur = {}
if os.path.exists(s) and os.path.getsize(s) > 0:
    with open(s) as f: cur = json.load(f)
if not isinstance(cur, dict): raise SystemExit("not a JSON object")
ours = json.load(open(h))["hooks"]
hooks = cur.get("hooks") if isinstance(cur.get("hooks"), dict) else {}
for ev, groups in ours.items():
    kept = []
    for g in hooks.get(ev) or []:
        if not isinstance(g, dict): kept.append(g); continue
        hs = [x for x in g.get("hooks") or [] if ".talixio/agent-hook.sh" not in str(x.get("command", "") if isinstance(x, dict) else "")]
        if hs or not g.get("hooks"): kept.append(dict(g, hooks=hs))
    hooks[ev] = kept + groups
cur["hooks"] = hooks
with open(out, "w") as f: json.dump(cur, f, indent=2); f.write("\n")
' "$S" "$H" "$S.talixio-new" ;;
    node) node -e '
const fs = require("fs"), [s, h, out] = process.argv.slice(1);
let cur = {};
if (fs.existsSync(s) && fs.statSync(s).size > 0) cur = JSON.parse(fs.readFileSync(s, "utf8"));
if (!cur || typeof cur !== "object" || Array.isArray(cur)) throw new Error("not a JSON object");
const ours = JSON.parse(fs.readFileSync(h, "utf8")).hooks;
const hooks = cur.hooks && typeof cur.hooks === "object" && !Array.isArray(cur.hooks) ? cur.hooks : {};
for (const [ev, groups] of Object.entries(ours)) {
  const kept = [];
  for (const g of hooks[ev] || []) {
    if (!g || typeof g !== "object") { kept.push(g); continue; }
    const hs = (g.hooks || []).filter(x => !String((x && x.command) || "").includes(".talixio/agent-hook.sh"));
    if (hs.length || !(g.hooks || []).length) kept.push({ ...g, hooks: hs });
  }
  hooks[ev] = kept.concat(groups);
}
cur.hooks = hooks;
fs.writeFileSync(out, JSON.stringify(cur, null, 2) + "\n");
' "$S" "$H" "$S.talixio-new" ;;
    jq) { if [ -s "$S" ]; then cat "$S"; else echo '{}'; fi; } | jq --slurpfile o "$H" '
      .hooks = (((.hooks | objects) // {}) as $h | reduce ($o[0].hooks | to_entries[]) as $e ($h;
        .[$e.key] = ([(.[$e.key] // [])[] | if type == "object" then
            (.hooks // []) as $all | .hooks = [$all[] | select(((.command? // "") | tostring | contains(".talixio/agent-hook.sh")) | not)]
            | select((.hooks | length) > 0 or ($all | length) == 0) else . end] + $e.value)))' > "$S.talixio-new" ;;
    *)
      echo "Talixio: the script is saved, but this machine has neither python3, node nor jq to add the hooks to $S." >&2
      echo "Add these hooks to it by hand (merge them with the hooks already there):" >&2
      cat "$H" >&2
      exit 1 ;;
  esac || { echo "Talixio: $S could not be read as JSON, so it was left as it was. Fix it, or add the hooks by hand (Pilotage, Agents, Connect a machine)." >&2; exit 1; }
  mv "$S.talixio-new" "$S"
  echo "Talixio: the board's hooks are in $S (merged with $tool; your other settings are kept)."
  echo "Done. Start a new Claude Code session on this machine: it appears on the board."
  if [ @LISTENER@ = 1 ]; then
    echo "To start and stop sessions from the board, leave this running (a terminal, tmux, or a login item):"
    echo "  sh $D/agent-listen.sh"
  fi
}
main "$@" </dev/null
