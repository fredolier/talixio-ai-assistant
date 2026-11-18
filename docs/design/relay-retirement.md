# Retiring the cloud relay (orchestrator bridge) without losing a feature

Status: plan, first written at 1.41.1 from a code reading and updated 2026-11-18 with the owner's decisions. Line numbers are those of 1.41.1 and drift with each delivery; the names do not.

**Owner's decisions (2026-11-18):** the Journal and Passes (G7) are retired, not migrated. The status file `pilot-status.md` (G6) is retired. The relay's threads and decisions are not archived as a file: they become the director's knowledge (`director-knowledge.md`): threads become digests, decisions become the decision log that precedents read. G8 (claude.ai cloud sessions) still needs a real test.

Name trap: `src/Relay.php` and the tables `relay_messages` / `relay_grants` are the director-worker mailbox (`src/Director.php`, `src/Tools.php`). They are **not** the cloud relay and stay.

## 1. Inventory

### Backend
| Item | Where | Fate |
|---|---|---|
| Bridge: `bindingFile` ... `fleetStatus` (pairing `setup`, `inbox`, `tick`, `pull`, `deliver`, `agentSay`, `toFleet`, `ground`, `inboxView`, journal, threads, unread, notify mode, status file) | `src/Conductor.php:99-942` | remove |
| Tier D: `dispatch`, `poll`, `sessions`, `busy`, `guard` | `src/Conductor.php:33-98` | keep. `guard()` is shared by `dispatch`, `toFleet`, `agentSay`; it stays for `dispatch` |
| Public routes `/api/conductor/inbox`, `/tick`, `/pull` and their handlers | `src/Web.php:47,48,50`, `1840-1887` | replace with a 410 tombstone (section 3) |
| Session routes `GET /api/conductor`, `POST setup / disconnect / notify / read`, `agents/{id}/thread / read / say` | `src/Web.php:210`, `236-245` | remove |
| `GET /api/pilot/status.md` | `src/Web.php:84-88` | G6 |
| `fleet_unread` in `/api/status` | `src/Web.php:872` | replace (G1) |
| Router area `'conductor'` | `src/Web.php:125` | remove (`'pilot'` stays) |
| Tools `fleet_setup`, `fleet_dispatch`, `fleet_inbox`, `fleet_decide`, `fleet_status`, `fleet_disconnect` | `src/Tools.php:617-632`, `734-737` | remove |
| Tool `fleet_priorities` | `src/Tools.php:633-640` | **shared**: writes `Pilot::setPolicy`, which the board uses (G3) |
| Tool lists: group `'director'` prefix `fleet_`, `READS`, `EFFECTS`, `untrustedSource` | `src/Tools.php:37`, `1338`, `1346`, `1355` | edit (`conductor_` prefix stays) |
| Legacy triage in `Pilot::tick` (after its `boardTick` call) | `src/Pilot.php:140-153` | reduce `tick` to `boardTick`; `src/Worker.php:81` unchanged |
| `Pilot::wake`, `triagePrompt`, `decide`, `learnAnswer`, `proposal`; state keys `pending`, `last_wake` | `src/Pilot.php:126-130`, `291-298`, `313-344`, `372-375`, `452-456`, `37` | remove |
| **S1** `Pilot` calls `conductor()->refreshStatusFile()` from shared code | `src/Pilot.php:99` (`setPolicy`), `406` (`score`) | cut or repoint (G6) |
| **S2** `Pilot::precedents` reads `conductor()->decisions()` | `src/Pilot.php:440` | migrate the data first (section 3) |
| Shared and staying: `limits`, `sensitive`, `score`, `verdict`, `same`, `levelOf`, `overview`, `setPolicy`, all `board*` | `src/Pilot.php` | keep |
| `App::conductor()` | `src/App.php:219` | keep (Tier D) |
| `Secrets::FILES` lists `conductor.json` | `src/Secrets.php:170` | remove after cleanup |
| Comments naming the webhook | `src/Http.php:157`, `src/Pilot.php:8` | reword |

There is no relay setting or env key (the notify mode lives in `conductor.json`) and no relay DB table or column. `jobs.session` and `kind='conductor'` (`src/Store.php:122`, `src/Jobs.php:255`) and `TALIXIO_CLAUDE_BIN` belong to Tier D. Relay notification titles such as `'Fleet orchestrator connected'` (`src/Conductor.php:323`) have no entry in `src/I18n.php` (only the two board strings at lines 88-89), so that file loses nothing.

### Frontend
| Item | Where | Fate |
|---|---|---|
| `<details id="ab-legacy">`: status card, pairing steps, status file, notify switch, tabs Needs you / Agents / Journal / Autonomy stub | `web/public/app.html:201-259` | remove |
| Space editor "Fleet orchestrator" row `#sp-orch-*` | `web/public/app.html:973-981` | remove |
| `initOrchestratorPanel`; its call and the dispatch note in `openSpaceEditor` | `web/public/app.js:484-511`, `528`, `540-545` | remove |
| `plSeen`, `plStale`, `PSTATE`, `renderPilotNotify`, `PL` and helpers, `plRender`, `plShowTab`, Needs you, `plClarify`, `pilotStatusFile` | `web/public/app.js:6442-6614` | remove, except the shared names below |
| Roster, threads, `plSay`, Journal, `plWire` | `web/public/app.js:6666-6801` | remove, after S3 |
| Legacy half of `pilotAgents` | `web/public/app.js:6982-7005` | reduce to `abWire(); abRefresh()` |
| `setFleetBadge`, the `fleet_unread` poll | `web/public/app.js:7007-7012`, `7449` | repoint (G1) |
| **Shared, keep:** `pilotAgo` (6445), `pN` (6452), `plainLine` / `plClip` (6481-6483), `PL.auto`, `plLoadAuto` / `plRenderAuto` / `plSaveAuto` / `plVerdict` (6615-6665), `#pl-auto` inside `#ab-auto` (`app.html:169`) | | keep |
| **S3** the board's Autonomy **Save** and the ✓/✗ buttons are dispatched by `plWire`'s click handler | `web/public/app.js:6785-6786` | move both branches into `abWire` before `plWire` goes |
| CSS `.pl-*`, `.pilot-orch`, `.pilot-status*`, `.pilot-notify`, `.pilot-steps`, `.pilot-cfg`, `.orch-steps`, `.ab-legacy` | `web/public/app.css:1578`, `1619-1641`, `1826-1972`, `1983`, `2005-2007`, `2065-2066` | remove what `#pl-auto` does not use (`.pl-card`, `.pl-lv`, `.pl-switch`, `.pl-nums`, `.pl-two`, `.pl-dict`, `.pl-rec`, `.pl-rw`, `.pilot-dot`, `.pstate-*` stay) |
| i18n candidates | `web/public/i18n/fr.js` around lines 51, 108-110, 1743-1754, 1810, 2520, 3081-3168, 3274, 3319-3335, 3443-3458 | remove a key only when no `t('...')` uses it any more (autonomy keys are shared) |

### Docs and tests
| Item | Where | Fate |
|---|---|---|
| FAQ "Cloud orchestrator (previous approach)" and "Passes" | `docs/faq/English/Using Talixio/Pilotage.md:77-143`; `docs/faq/Français/Utilisation/Pilotage.md:86-155` | remove |
| FAQ "Reports from your autonomous pilot" | `.../Channels and notifications.md:14-17`; `.../Canaux et notifications.md:15-18` | rewrite for the board (G1) |
| Design docs | `docs/design/orchestration.md` §5.2-B, §5.5, §5.6 and lines 524-535; `docs/design/pilotage.md:57-63`, `272-277`, `303-306`, `338-350`; `docs/ROADMAP.md:83-104` | mark retired |
| Relay PHP tests | `tests/run.php:1423-1749` | remove. The Tier-D tests at `1391-1420` stay |
| ...of which `pilot:` checks of shared code (L4/L5 kept, clean lists, cap clamps, `levelOf`, `same`, `suggest`, deploy never sent, correction lowers a level) | inside `tests/run.php:1636-1690` | **port** to board fixtures before deleting |
| | `tests/more/conductor_tick.php` (whole file); `tests/more/jsonfile.php:26-36` | remove (`:57-59`, the `pilot.json` permission check, stays) |
| Browser flows | `tests/browser/flows.mjs:808-815` (space editor), `994-1087` (legacy cockpit) | remove. Autonomy is already covered at `893-936` |

## 2. Feature map

| The relay does today | On the board | Proof |
|---|---|---|
| Lists sessions across machines with state | `AgentHub::board` (`src/AgentHub.php:205`), `abRender` (`app.js:6923`) | `tests/more/agent_hub.php:24,30,53`; `flows.mjs:852` |
| Shows a question, as copied by the orchestrator | last message word for word, `AgentHub::event` (`:115-118`) | `agent_hub.php:53` |
| Reply to a session | `AgentHub::command` (`:185`), `app.js:6866` | `agent_hub.php:61-68`; `flows.mjs:875` |
| Approvals: display only | Allow / Deny (`AgentHub.php:103-108`) | `agent_hub.php:36-45`; `flows.mjs:880-883` |
| Per-agent thread | `AgentHub::thread` (`:216`) | `agent_hub.php:68`; `flows.mjs:874` |
| Send work (`fleet_dispatch`) | New session / `agent_start` (`AgentHub.php:287`, `Tools.php:714`) and Stop | `tests/more/agent_start.php`; `flows.mjs:943-985` |
| Assistant reads the fleet (`fleet_status`, `fleet_inbox`) | `agents_list` (`Tools.php:698`, `AgentHub::summary` `:245`) | `agent_hub.php:84,87` |
| Assistant answers from chat | `agent_answer` (`Tools.php:701`) | `agent_hub.php:86` |
| Autonomy: triage, limits, suggestion, verdict, learning | `Pilot::boardTick` / `decideBoard` (`Pilot.php:171`, `217`), `app.js:6963-6968` | `tests/more/pilot_board.php` (all); `flows.mjs:893-936` |
| Pair / disconnect / re-pair | Connect a machine, New token (`AgentHub.php:41`, `Web.php:1946-1968`) | `agent_hub.php:18-21,92-97`; `flows.mjs:853-857` |
| "Orchestrator asleep" warning | per-machine `listening` (`AgentHub::machines` `:267`), real ages on cards | `agent_start.php` |
| "Ask for the full question" | not needed: text is verbatim | - |
| Unread badge on the rail and the Agents tab; switch badge / push + e-mail / off | **GAP G1.** Both badges are fed only by `fleet_unread` (`Web.php:872`, `app.js:7449`). The board pushes on a permission request (`AgentHub.php:108`) and in `tell` mode; a finished turn notifies nobody and there is no switch. Build a needs-you count in `/api/status`, the badges, and a notify setting | none |
| Confidential / secret space cannot send outward (`guard()` in `toFleet`, `agentSay`) | **GAP G2.** `agent_answer` and `agent_start` have no level check (`Tools.php:701-721`). Apply the same refusal | none |
| Priorities and rules told in chat (`fleet_priorities`) | **GAP G3.** The board UI promises it (`app.js:6643`) but the only tool is `fleet_*`. Keep it under a board name | none |
| Assistant reads one session's whole thread (`fleet_inbox` with an agent) | **GAP G4.** `summary()` gives only the last message. Add an optional session argument to `agents_list` | none |
| Roster search, filters, group by machine | **GAP G5** (small). Three columns only | none |
| `pilot-status.md`: view, copy, download, on disk | **GAP G6.** Rebuild from `AgentHub::summary` + `Pilot::overview`, or retire by decision | none |
| Journal (fleet-wide summaries, alerts) and Passes | **GAP G7, by design.** These are the orchestrator's own writing, which 1.36.0 set out to remove. Alerts are covered once G1 ships. Needs the owner's explicit agreement to retire | none |
| Outgoing instructions grounded in the knowledge base (`ground`, `:779`) | Partial. `agent_answer` text comes from an assistant that has the KB tools; `boardPrompt` uses rules, precedents and Mind, not the KB. Decide: accept, or add recall hits | none |
| Decision log across agents (who answered what) | Partial: per-session thread plus "Its latest answers" (`Pilot::overview`). No cross-session list of the user's own answers | `pilot_board.php:42` |
| Reaches cloud / Remote-Control sessions and native Windows, through the account-level orchestrator | **GAP G8, unverified.** The board sees only sessions whose machine runs the `sh` + `curl` hook (macOS, Linux, WSL per `app.html:180`). Whether a claude.ai cloud session can carry the hook was not tested | none |

## 3. Data, and the machine still running the old client

Stored state:
- `<data>/conductor.json` (0600, sealed): pairing code hash, steady secret, webhook URL, roster, threads (200 turns per agent), journal (100), decisions (60), passes (100), poll queue, notify mode, unread counters.
- `<data>/pilot-status.md` (and a possible `.tmp`).
- `<data>/pilot.json`: **stays**. Relay-only keys `pending`, `last_wake`; relay predictions (those without `board: true`) feed the per-level accuracy and precedents, so keep them.
- `<data>/conductor/<job>` and `conductor/logs`: Tier D, untouched.
- No DB migration.

A cleanup is needed, once, idempotent, when `conductor.json` exists:
1. Copy `decisions` into `pilot.json` and make `precedents()` read them from there (S2). Without this the board's autonomy forgets every answer the user gave through the relay.
2. Turn each agent's thread into a digest of the director's knowledge (`director-knowledge.md` §6, `source = relay`). The journal and passes are dropped (owner's decision).
3. Delete `conductor.json` (it holds a live secret and a webhook) and `pilot-status.md` (G6 is retired).
4. Tell the user once through the notifier.

Old client after the upgrade. If the three public routes are simply deleted, its calls fall through to the session router at `Web.php:51+` and get a generic refusal (I did not check which status); a push-mode orchestrator hears nothing at all, since Talixio stops calling its webhook. Instead keep the three paths as tombstones for a few versions: **HTTP 410** with a JSON body written for the model that reads it, for example `{"error": "retired", "message": "This Talixio no longer uses a fleet orchestrator. Stop your keep-warm loop and tell the user: connect each machine in Pilotage → Agents → Connect a machine."}`, with no authentication needed and no state written. On the first such call per day, notify the user that an old orchestrator session is still running and can be closed.

## 4. Steps (each one delivery: version bump, CHANGELOG, FAQ in both languages when visible)

1. **1.42.0 - untangle and retire the status file (S1, S3, G6).** `Pilot` no longer calls `Conductor::refreshStatusFile`; `GET /api/pilot/status.md` and the Status file button go. The autonomy Save and the ✓/✗ buttons move from `plWire` to `abWire`. The shared-code `pilot:` checks are ported from `tests/run.php` to `tests/more/pilot_board.php`.
2. **1.43.0 - badge and chat parity (G1, G3, G4).** A needs-you count in `/api/status` feeds the rail and tab badges; the priorities tool gets a board name; `agents_list` takes an optional session. G2 (refusal from a confidential space) already shipped through `outsideRuntime`.
3. **1.44.0 - director knowledge, level 1-2 (done).** Decision log, session digests, project dossiers; `precedents()` reads the decision log; "What I know" in Pilotage → Agents; `agents_knowledge` tool.
4. **1.45.0 - switch the relay off (done).** Tombstones (410), the one-time cleanup (decisions → decision log, threads → digests, then `conductor.json` and `pilot-status.md` deleted, Journal and Passes dropped, one notification), the `fleet_*` tools and the legacy triage removed, `#ab-legacy` and the space-editor row removed.
5. **1.46.0 - deduced rules and the decision hierarchy** (`director-knowledge.md` §3 level 3, §4).
6. **1.46.x - delete the dead code**, no behaviour change: `Conductor.php` bridge half, session routes, `PL` JS, CSS, i18n keys, relay tests, FAQ and design-doc sections.
7. **Later - remove the tombstones and the cleanup code**, once no old client can still be running.

G5 (roster search and filters) can ship at any point. G8 needs a test on a real cloud session; it was not run before step 4 (the owner does not use the relay), so a cloud session can still not report to the board.
