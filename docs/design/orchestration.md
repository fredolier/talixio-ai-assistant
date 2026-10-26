# Orchestration — the corporate knowledge hub and the session conductor

> Status: design spec, Oct 2026. Forward plan. Builds on the agent factory
> (`agent-factory.md`) — this is its §6 "teams of teams" grown into two concrete
> product use cases. Build in the phases at the end; the trust-boundary pieces
> are gated behind the safe, buildable-now foundation.

## 1. Why — two real use cases

Talixio today is **one assistant, alone**: a person's instance runs its own
agents and background jobs, and the only thing it shares with other instances is
a read-only role/app catalogue (the store). Two needs push past that wall.

1. **The corporate knowledge hub.** In a company, every person has their own
   Talixio. They should be able to **draw on, and contribute to, one shared
   corporate brain** — the knowledge base, templates, brand assets, policies,
   proven roles that the whole enterprise reuses. "What's our latest MSA
   template?" is answered from the hub; "add our new brand chart to the company
   knowledge base" publishes it for everyone.
2. **The session conductor.** A developer runs several Claude Code sessions, one
   per project, across machines, each waiting for instructions. Their assistant
   should be the **engineering manager**: one overview of every project, decide
   what each session does next, prioritise, dispatch, unblock — managing a team
   of developers where each works a different project.

Both are "my assistant reaching **other running agents**." They have *different
shapes*, and naming the shape is what keeps this safe.

## 2. The shape of each — not a mesh

The dangerous design is an open mesh where any assistant can reach any other.
Neither use case wants that.

- **Use case 1 is hub-and-spoke.** Spokes (personal assistants) talk only to the
  **hub** (the corporate assistant). Spokes never talk to each other. One
  well-known address, not N×N reachability.
- **Use case 2 is manager-and-reports.** One orchestrator (your assistant) drives
  N sessions; the sessions don't talk to each other through it. A star, not a mesh.

Both are stars with a single centre. That is the whole safety story: there is
always exactly one trusted hub per relationship, and trust flows along named,
consented edges — never ambiently.

## 3. The backbone — one inter-agent messaging protocol

Both use cases ride the same small primitive, built once so the consent / budget
/ loop / untrusted-input logic isn't reinvented (and left to drift) twice.

- **Address.** Every reachable agent — a directive worker, a whole assistant, an
  external Claude session — has a stable address. The directory lives in the
  `store` (for assistants) and in config (for sessions).
- **Mailbox.** A durable, append-only message queue per address. It **outlives
  the chat that started it** (this is "reach a session across time") and survives
  container restarts. Backed by a `messages` table.
- **Consent + capability grant.** Before anyone may message an address, its owner
  has granted a **scoped, revocable** capability ("may ask to draft; may *not*
  send mail or spend"). **Default closed.** No ambient reachability.
- **Budget envelope + loop guard.** A message carries a budget that only *shrinks*
  downstream. A depth limit and cycle detection are built in from the first
  commit, not bolted on — two agents can otherwise burn real money in a loop.
- **Untrusted-input posture.** A message from another assistant or session is
  treated exactly like a GitHub comment or a fetched web page: **data, never a
  command.** It can never silently trigger a send, a spend, or a delete. Same
  rule Talixio already applies to external content.

Use case 1 is a **hub adapter** over this; use case 2 is a **session adapter**.

## 4. Use case 1 — the corporate knowledge hub

### 4.1 Space is the unit of sharing (not the assistant)

Inside one assistant there are **spaces**, and a space is already Talixio's
isolation + privacy boundary (privacy levels, locks, instructions, root folder).
Sharing is granted **per space**, so it inherits that boundary instead of
inventing a second one that could disagree with it. Assistant-level would be too
coarse: everyone has a *Work* space and a *Personal* space in one assistant, and
only Work should ever touch the corporate hub.

**Three layers:**

- **Assistant (the Docker container)** = *identity + credential*. Who you are to
  the hub; holds the token/address to reach it. One per person.
- **Space** = *the authorisation + content boundary*. The grants "may **pull**
  from hub X" and "may **contribute** to hub X" live on the space, as two
  separate capabilities (read ≠ write), default-closed, revocable.
- **Hub** = itself just another Talixio assistant; the corporate knowledge base
  is **a space inside it**.

The model is self-similar: a spoke's *Work* space links to the hub's *Company-KB*
space. Space ↔ space, mediated by the two assistant identities. Multi-hub falls
out for free — a contractor keeps two Work spaces, each linked to a different
corporate hub.

### 4.2 Pull & ask (spoke → hub, read)

- **Fetch** a published asset (a template, a brand chart, a policy doc) into the
  space's library.
- **Ask** the corporate brain a natural-language question; the hub runs a turn
  **scoped to its Company-KB space only** (RAG over the shared library) and
  returns the answer + the asset it drew on. This is the "corporate brain you can
  ask", not a file mount.

### 4.3 Contribute (spoke → hub, write, reviewed)

Submitting *up* is the store's existing **outbox → review → publish** flow,
widened from roles/apps to arbitrary enterprise assets (a spec, a brand chart, a
template, an FAQ entry):

1. A collaborator's Work space **submits** an asset to the hub's outbox
   (*"add our new brand chart to the company knowledge base"*).
2. A **curator approves** it — the corporate admin, or an auto-approve policy for
   trusted contributors / low-risk asset types. (Reuses `Catalog::collect/decide/
   publish`.)
3. On publish, the hub drops it into its **Company-KB library (GED), embeds and
   indexes it**, so the Q&A endpoint finds it immediately and every spoke can pull
   it.

### 4.4 The three things contribution adds that role-sharing didn't

- **Leakage guard (privacy-native).** Submission respects the *source space's*
  privacy level and locks: only content at or below a shareable level may go up,
  **locked/Secret items never**, and a space with no contribute-grant simply
  can't submit. The confidentiality check is the space boundary already enforced,
  not a new gate.
- **Provenance + versioning.** Every published asset records which
  **assistant + space** submitted it, when. A new spec **supersedes with history**
  (not last-write-wins), so "our latest MSA template" always resolves to current
  and no one reuses a stale one.
- **Governance.** Who may approve, and what (if anything) auto-publishes.
  Templates from the design lead may be auto; a policy doc needs a human nod.

### 4.5 Buildable now vs later

The **asset path** (contribute an asset, review it, publish it, pull it, index
it) reuses the **shared store volume** as transport — the exact mechanism role
sharing already uses across containers, needing **no new network channel**.
So §4.3/§4.4 are buildable on existing machinery today. The **live Q&A reach**
(§4.2 "ask the corporate brain") needs a live request/response channel to the
hub assistant and comes in a later slice; until then, pull-of-published-assets +
local RAG over what you've pulled already covers most of the value.

## 5. Use case 2 — the session conductor

### 5.1 The fabric — what's actually documented (verified Oct 2026)

There is **no supported, externally-callable API** (REST or SDK) by which a
third-party app like Talixio can, as the user, *list*, *read the transcript of*,
or *message* the user's Remote-Control / cloud sessions. That was verified
against the Claude Code docs; building a client against a guessed endpoint (or
the unofficial, reverse-engineered Remote-Control web endpoints) is off the table.
The **documented** surface is narrower and host-local:

- **Create** a cloud session: `claude --cloud "task"`, run on a host where the
  user is signed in (`claude auth login` with a **claude.ai account** — an API key
  is *not* sufficient — and org policy `allow_remote_sessions` enabled). Returns a
  session id + a claude.ai/code URL.
- **Create** by HTTP, fire-only: the scheduled-tasks trigger
  (`POST …/claude_code/routines/<id>/fire`) — starts a *new* session, returns its
  id/URL. Cannot list or message existing ones.
- **Follow-up** to a session: `claude -p --cloud <id> --output-format json` — posts
  **one message and exits without waiting for a reply**.
- **Local background sessions only**: `claude agents --json` lists them (id, state,
  status, waitingFor), `claude logs <id>` reads one, `claude --resume <id> --bg`
  continues one. **None of these reach cloud/Remote-Control sessions.**
- A cloud session's **replies are read in the transcript at claude.ai/code**, not
  returned to whatever dispatched the message.
- The cross-session fabric the user is picturing (`ListAgents` / `SendMessage`,
  and the `claude-code-remote` MCP tools) is an **in-session** capability — it
  exists *inside* a Claude session connected to Remote Control, **not** as an
  external API. The orchestrator that can natively see and message all the
  sessions is therefore *itself a Claude session*, not an outside app.

### 5.2 Two honest shapes for the conductor

Given 5.1, Talixio cannot be a pure external API client. Two buildable shapes:

- **A — Talixio-native dispatch (partial).** Talixio runs on a host where the user
  is `claude auth login`'d. It can **start** cloud sessions and **send one-way
  follow-ups**, and it can **fully manage local background sessions** on that host
  (`claude agents`/`logs`/`--resume`). What it *cannot* do natively: a unified live
  overview of cloud sessions across other machines, or read their replies (those
  live at claude.ai/code). Overview would need a tiny reporter on each machine.
- **B — delegated conductor (fuller, recommended).** Talixio runs/drives a Claude
  **"manager" session** — ideally a *local background* session on Talixio's own
  host, so its transcript is readable via `claude agents --json` / `claude logs` /
  the Agent SDK. That manager session, being a real connected Claude session,
  natively reaches the user's peers through Remote Control (`ListAgents` /
  `SendMessage`). Talixio is the brain and the UI; the Claude session is the hands
  that touch the fabric. Talixio closes the loop by reading the manager session's
  **local** transcript — all documented mechanisms.

**Prerequisites/limits either way:** a claude.ai-authenticated host (not API key),
`allow_remote_sessions`, and the standing fact that cloud replies aren't returned
to a dispatcher — status/results come from reading a transcript, not an API.

### 5.3 Posture

A session's output (transcripts, summaries, proposed actions) is **untrusted
input** per §3 — the conductor surfaces and summarises it, but a session asking
to spend, deploy, or delete never auto-executes on the orchestrator's side.
Dispatch to a session carries a budget envelope (§3).

## 6. Security & the trust boundary

- **The fleet wall stays the default.** `Fleet` isolates instances at the network
  level ("instances can't reach each other"); the asset path keeps that intact by
  using the **store volume**, not instance-to-instance traffic. Only the later
  **live hub reach** (§4.5) and the **session API** (§5) open outbound edges, and
  each is a **named, consented, revocable** capability on a specific space — never
  ambient reachability between instances.
- **Direction of trust is one-way and narrow.** Spokes *ask and contribute*; the
  hub never reaches into a personal assistant uninvited. The conductor *drives*
  sessions; sessions never drive the conductor.
- **Everything audited.** Every cross-edge message (contribution, pull, Q&A,
  dispatch) is logged with who/when/what and the budget it carried.
- **No second trust model.** Roles/assets are capability declarations approved the
  same way apps and connectors are; privacy levels gate what leaves a space; the
  session token is a connector secret. Reuse, don't reinvent.

## 7. UI surfaces

- **Space settings → "Knowledge hub".** Link this space to a hub; toggle **pull**
  and **contribute** (default off); shows the hub, the grant, and a revoke button.
- **Contribute action.** From a document/asset in a hub-linked space: *"Share to
  the company knowledge base"* → confirmation showing exactly what leaves and its
  privacy level → goes to the hub outbox.
- **Hub admin (curator).** The store review page, widened: a queue of submitted
  assets with provenance, approve/reject, version history.
- **Project board (conductor).** One page: a card per session/project with status,
  last activity, cost; click to read the latest transcript summary, dispatch an
  instruction, reprioritise, or interrupt. The engineering-manager glance.

## 8. Reuse — build on what exists

| Need | Reuse |
|---|---|
| Hub-and-spoke substrate | **`Fleet`** (one container per person; store volume shared) |
| Submit → review → publish | **`Catalog` / `AppStore`** (outbox → decide → publish), widened to assets |
| Cross-container transport (asset path) | **the shared store volume** (no new network channel) |
| Asset storage + retrieval | **`Library` / `Documents`** (the GED) |
| Index + RAG + "already have one like this?" | **`Embeddings` / `Meaning`** |
| Leakage guard | **`Privacy`** (per-space levels + locks) |
| Durable mailbox / audit | **`Store`** (+ a new `messages` table) |
| Session orchestration | documented CLI surface only — `claude --cloud` (create), `claude -p --cloud <id>` (one-way follow-up), `claude agents --json` / `logs` / `--resume` (local bg); **no external list/read/message API** (§5.1) |
| Running the conductor | `ClaudeCodeProvider` / the `claude` CLI already wired; a local-bg manager session (§5.2-B) |

## 9. Phased build (safe foundation first, trust-boundary edges gated)

*Reordered after the §5.1 finding: the corporate-hub asset path is fully
buildable today; the conductor is partly blocked by the missing sessions API, so
its first slice is the small documented-surface piece, not the imagined board.*

1. **Space-scoped asset contribution (hub, asset path).** The first real build.
   A new store **`asset`** kind mirroring the role path: submit a text asset (a
   spec, a template, an FAQ/note) from a space to the outbox → curator review →
   publish into the shared catalogue with **provenance + supersede-with-history**;
   another assistant installs/pulls it into its library. Reuses the store volume —
   no new channel, no fleet-boundary change. *Medium.* **✅ shipped in 0.28.93** —
   `Catalog::cleanAsset` + the `asset` kind through collect/decide/publish/add,
   `AppStore::submitAsset`/`installAsset` (installed as a library note under
   `/Company KB`, tracked in `store-assets.json`), the `asset_publish` /
   `asset_install` chat tools (gated by the existing `sharedWrite` privacy guard, so
   a confidential/secret space cannot publish), and the admin store view. Re-install
   updates the note in place. Per-space grants and a dedicated contribute UI are
   slice 2.
2. **Per-space hub grants + leakage guard + the contribute action UI.** Make
   contribution space-scoped (pull/contribute grants, default off), wire the
   privacy leakage guard (locked/Secret never leaves), and the *Share to the
   company knowledge base* action + curator review UI. Binary assets (a brand-chart
   image) add a blob store beside catalog.json here. *Medium.* **✅ shipped in
   0.28.94** — two per-space columns `hub_pull`/`hub_contribute` (default closed,
   revocable; General via store keys), `Spaces::hubGrants`/`setHub` and an
   `/api/spaces/{id}/hub` endpoint; `asset_install`/`asset_publish` now check the
   current space's grant and refuse (pointing at the space settings) on top of the
   existing `sharedWrite` leakage guard; two checkboxes in the space editor, shown
   only in a fleet; and **provenance** (`by` = contributor name, instance and space)
   recorded on each published asset through collect/decide/publish and shown on the
   admin store page. *Deferred to a follow-up in this slice:* binary/blob assets
   (the brand-chart image) and the one-click library *Share* button — the
   tool/endpoint path is the shipped surface.
3. **Conductor, documented-surface slice.** What §5.1 actually allows: store the
   claude.ai-host prerequisite; **start** a cloud session (`claude --cloud`) and
   **send one-way follow-ups** (`claude -p --cloud <id>`) as tools; **fully manage
   local background sessions** on Talixio's host (`claude agents --json` → a board,
   `claude logs`, `--resume`). No cross-machine cloud overview yet. *Small–Medium.*
4. **Conductor, delegated shape (§5.2-B).** Talixio runs a local-background Claude
   *manager* session that reaches the user's peers via Remote Control; Talixio reads
   that session's local transcript for status/results and relays dispatch. Closes
   the loop with documented local-session reads. *Medium.*
5. **The messaging backbone, generalised.** Address + durable mailbox + consent +
   budget envelope + loop guard, as one service both adapters sit on; in-assistant
   worker mailbox (reach a running worker, worker→director question) as its proof.
   *Medium.*
6. **Live hub reach — ask the corporate brain.** Request/response channel to the
   hub assistant; hub runs a Company-KB-scoped RAG turn and answers. Opens the
   first consented instance-to-instance edge; gated behind 1 + 2 + 5. *Large.*

## 10. Open decisions

- Hub curator: a human role on the corporate assistant, or can the corporate
  assistant auto-curate low-risk asset types against a policy?
- Asset supersede: automatic on a matching title/path, or an explicit "new version
  of …" link the contributor sets?
- Conductor shape: ship the Talixio-native dispatch (§5.2-A) first, or go straight
  to the delegated manager session (§5.2-B) that gives the real cross-machine
  overview? Lean: A for dispatch + local sessions now, B when the overview is worth it.
- Conductor board: no cloud list/status API exists (§5.1), so a cross-machine
  overview needs either a per-machine reporter or the §5.2-B manager session reading
  peers. Which?
- One space → one hub (simple) vs one space → many hubs (contractor case) — ship
  one-to-one first?
- Does the session conductor belong in the Activity page (directives + jobs +
  sessions as siblings) or its own board? Lean: its own board, linked from Activity.
