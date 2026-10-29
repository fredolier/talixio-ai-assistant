# Pilotage — the director's cockpit (phased build)

> Status: design + build plan, Oct 2026. Forward plan. This is the **human-facing
> UI** over machinery that is already shipped: the Director (directives/worksites),
> the Assessor + role registry (`agent-factory.md`), and the Conductor/Fleet bridge
> (`orchestration.md` §9.1–§9.5, all shipped through 0.28.99). Nothing here opens a
> new trust edge; it surfaces, groups and governs what the services already expose.
> Validated against the clickable mockup (`scratchpad/fleet-ui-mockups.html`, v7).
>
> **Deliberate divergences from the mockup** (the mockup is a concept sketch, not
> the contract — these stay as shipped):
> - **KB tab** surfaces a *library glance* (space, library root, file/subfolder/size
>   stats, hub-pull state, "Open the Library" link), not the mockup's hub-resource
>   document list — see Slice 5.
> - **Rôles tab** renders the specialization "map" close to the mockup: bordered
>   per-specialization **group cards** (`.pspec-card`, header + `· N rôles` count)
>   holding clickable **role nodes** (`.pnode` — an accept-rate ring whose size
>   scales with run volume), and clicking a node opens a **detail card** below
>   (`#prole-detail` → `roleDetail`: ring, metrics, sparkline, inline specialization
>   editor, lifecycle actions). Archived roles open from an **Archivés…** toolbar
>   button, not a trailing `<details>`. What stays divergent from the mockup: there
>   is **no local-vs-corporate split** (no such field in `/api/roles`), so no
>   corporate group or legend; and the node is a ring, not the mockup's SVG
>   ring-cluster. Roles are authored conversationally (the assistant's `role_save`
>   tool, which runs the same duplicate check the mockup's drawer promises),
>   installed from the store (`role_install`), seeded by the director and the
>   fleet, or — since 1.6.0 — created from the catalogue's **+ Nouveau rôle**
>   header button, an inline form (name, one-line purpose, optional
>   specialization, brief) wired to `POST /api/roles` → the same `role_save`
>   tool and its duplicate check.
> - **Chantiers** are launched from the chat composer ("Parler au pilote"), so there
>   is no "+ Nouveau chantier" header button.

## 1. Why — one place to steer the assistant's own work

Today the pieces a person uses to *direct* their assistant are scattered: roles
are markdown files with no screen, directives live mixed into the **Activity**
page, and the fleet orchestrator is configured from a panel buried in space
settings. The Director has a board, the Assessor has metrics, the Conductor has a
status — but no single surface answers *"what is my assistant running, with which
roles, how well, and what do I change?"*

**Pilotage** is that surface: one menu entry with four tabs, plus the
assistant/space switch lifted to the top of the rail so the person always knows
*who* they are steering and *where*.

Pilotage is **voice-first**: you talk to a manager, you don't type at one. A mic
sits on every input (role briefs, instructions, worksite goals, chat to a
session) and a prominent **"Parler au pilote"** push-to-talk heads the agents tab,
because dictating a directive to the orchestrator is the natural act. This reuses
the dictation path already shipped (`toggleDictate` + `/api/transcribe` +
`Transcriber`), so it is a wiring job, not a new capability — it ships with the
shell (slice 1a).

- **KB** — the knowledge the director grounds on (the space's library + any hub
  pull). Read-only glance; deep edits stay in Library.
- **Pilote autonome (agents)** — the Conductor/Fleet: the delegated orchestrator
  session and the Tier-D agents it runs across machines. (Renamed from "flotte"
  → **agents**, decided with the mockup.)
- **Rôles** — the role registry as a managed catalogue: grouped by
  **specialization**, each with its Assessor metrics, an **overlap** signal, and
  the lifecycle actions (Évaluer / Améliorer / Publier / Archiver / Supprimer /
  Archivés…).
- **Chantiers** — the Director's directives ("worksites"): open / pilot /
  instruct / prioritise / redirect / cancel / close, each worker rated on close so
  the roles learn.

## 2. The homogeneity invariant (why Pilotage is not a second system)

The overriding requirement holds: **everything uses the same roles so it improves
as it goes.** Pilotage therefore never introduces a parallel spawn path, a second
metrics store, or a separate budget. Concretely:

- "Rôles" reads and writes the **same registry** (`Skills`, `kind: role`) the
  Director and Conductor already seed from (`Director::plan`/`seed`). An
  improvement published here (`role_improve` → `role_publish`) reaches in-process
  workers and Tier-D sessions alike.
- "Rôles" metrics come **only** from the `Assessor` (`metrics`, `health`, `cases`,
  `backtest`), the same numbers the A/B gate uses. No new numbers are computed for
  display.
- "Chantiers" is the existing `Director` board (`board`, `active`, `steer`,
  `rate`, `finalize`), not a new directive engine.
- "Agents" is the existing `Conductor` (`statusInfo`, `fleetStatus`, `dispatch`,
  `toFleet`), not a new dispatcher.

So Pilotage is **almost entirely a view layer**. The few genuinely new things are
small and listed per slice below.

## 3. What already exists vs. what Pilotage adds

| Pilotage element | Reuse (shipped) | New (this plan) |
|---|---|---|
| Rôles list + metrics card | `Skills::all/meta`, `Assessor::metrics/health/cases` | the view; a `/api/roles` aggregate |
| Specialization grouping | — | a new `specialization` header field + grouping |
| Overlap / "doublon" signal | `Skills::find` ranking (lexical tokens) | `Skills::overlaps()` pairwise closeness + the banner/merge panel |
| Archiver / Archivés… | `Skills` files; `Assessor` history kept | an `archived` header flag + filtered listing |
| Supprimer (guarded) | `Skills::delete`, `Assessor` run counts | the "jamais exécuté" guard that steers to archive |
| Évaluer / Améliorer / Publier | `Assessor::runEvaluation/improve`, `Catalog`/`AppStore` role publish | the buttons + panels |
| Chantiers tab | `Director::board/active/steer/rate/finalize`, Activity cards | re-presentation inside Pilotage |
| Agents tab | `Conductor::statusInfo/fleetStatus`, the 0.28.99 panel | re-presentation; move panel out of space settings |
| Top-of-rail assistant switch | 0.28.40 one-sign-in + `switchSpace` | direct assistant switch at top of rail |
| Top-of-rail space switch | `switchSpace`, space menu | lift it to the top of the rail |
| KB glance | `Library`/`Documents`, hub pull (`Spaces::hubGrants`) | a read-only summary tab |
| Voice on every input + "Parler au pilote" | `toggleDictate`/`.dictate-btn`, `POST /api/transcribe`, `Transcriber` (sovereign/local), privacy `PURPOSE.transcription` | a `.dictate-btn[data-dictate-for]` on each Pilotage field + a push-to-talk composer |

## 4. Phased build

Sizes are relative to the shipped slices. Each slice ships behind the existing
per-space privacy guard where it touches dispatch or publish; none opens a new
network edge. Version numbers continue from 0.28.99: slices 1 + 1a open the
**0.29.x** line, and **1.0.0 is reserved for when Pilotage is complete** — the
cockpit consolidated and voice-first throughout (i.e. after slice 6). 0.5.0 is
not an option (versions only climb from 0.28.99).

### Slice 1 — the Pilotage shell + Chantiers + Agents (0.29.0) · *Small–Medium*

Stand up the menu and move what already exists into it, with **no new backend**.

- New nav entry **Pilotage** in `web/public/app.html` (rail + the "more" sheet),
  `data-view="pilotage"`, with four tab panels in `app.js`.
- **Chantiers** tab renders `Director::board/active` (the cards already on
  Activity), wiring the existing `steer`/`rate`/`finalize` endpoints. Activity
  keeps the full cross-cutting log; Pilotage shows the directive-centric view.
- **Agents** tab renders `Conductor::statusInfo` + `fleetStatus`, and **moves** the
  0.28.99 Fleet-orchestrator connect/bootstrap panel here from space settings
  (one orchestrator per assistant, so it belongs at assistant scope, not per
  space; the per-space dispatch grant stays shown inline).
- Responsive from the start, per the mockup: rail → bottom tab bar + compact top
  bar < 560 px; tabs → scrollable chips; drawers → full-height bottom sheets.

*Ships value immediately (consolidation) with zero model/schema change.*

### Slice 1a — voice-first input, everywhere (0.29.0, with the shell) · *Small*

Voice is the headline interaction, so it lands with the shell, not later. **No new
backend** — it reuses the shipped dictation path end to end.

- A `.dictate-btn` with `data-dictate-for="<field id>"` on **every** Pilotage text
  field — the orchestrator chat ("Parler au pilote"), a worksite goal, a role
  brief, an instruction to a worker, a close-out note. The one-liner that wires
  `toggleDictate` to `.dictate-btn` already exists in `app.js`; Pilotage's
  dynamically-rendered drawers just re-run it after render (as the mockup does
  with `wireMics`).
- A prominent **push-to-talk** composer at the head of the **agents** tab:
  press, speak a directive, it transcribes into the field, you read it back, then
  send. Dictation is **transcribed and never kept** (the existing behaviour), and
  honours the space's privacy level via `PURPOSE.transcription` — a Confidential
  space routes transcription to the sovereign/local engine or asks, unchanged.
- Degrades cleanly where the mic or `MediaRecorder` is unavailable (the existing
  "needs a recent browser over HTTPS" / "microphone refused" toasts); the field
  stays usable by keyboard.

*The user's primary way in. Pure wiring over shipped `Transcriber` +
`/api/transcribe`.*

### Slice 2 — Rôles catalogue, read-only (0.29.1) · *Medium*

The role registry gets its first screen.

- New `GET /api/roles` in `Web.php`: for each `kind: role` file, join
  `Skills::meta` with `Assessor::metrics`+`health` (accept %, mean cost, run
  count, last-run, golden-case pass/fail, trend series, protected/published
  flags). One aggregate call so the view does no per-role round-trips.
- **Rôles** tab: the specialization map + the detail card (ring = accept rate,
  trend sparkline, golden squares, actions list) — read-only first; action
  buttons present but deferred to slice 4.
- Detail actions that are already one call wire now: **Évaluer**
  (`Assessor::runEvaluation` via the existing job path) and **Publier**
  (`role_publish`/`Catalog`), since they reuse shipped flows.

### Slice 3 — specialization grouping + overlap signal (0.29.2) · *Medium*

The two genuinely new *concepts*, kept orthogonal (decided with the mockup:
**grouping ≠ duplicate**).

- **Specialization** *(shipped)*: a new optional `specialization` header field
  (preserved by `Skills::save`'s header order; set via `Skills::setField` behind
  `POST /api/roles/<role>/specialization`), grouping the map by it. A role with
  none falls into "Sans spécialisation". A one-field inline editor on each card
  makes the map editable. `scope` was *not* reused — it already means the role's
  visibility (private/shared/published). Pure grouping by shared topic — **no**
  behavioural coupling.
- **Overlap** (the separate, finer signal) *(shipped)*: `Skills::overlaps()`, a
  pairwise set-cosine over the same lexical tokens `Skills::find` ranks on
  (name, description, skills, tools, when-to-use), default threshold 0.6 — not
  `Meaning`/embeddings, which are off in the test env, so the signal stays
  consistent with the registry's own ranker. Pairs at/above the threshold surface
  the banner ("Overlap détecté — researcher ≈ web-researcher, ~87 % proches") and
  the inline hint on the card. Recomputed on demand, carried on the `/api/roles`
  payload, never stored as truth.
- **Merge panel** *(shipped)*: side-by-side brief compare + "Proposer un rôle
  fusionné au pilote", which drafts a combined-role instruction into the ordinary
  chat for the Director to run with its own tools (keep the stronger brief, union
  skills + tools, confirm before saving). Human-approved, never an auto-merge.

### Slice 4 — role lifecycle: archive, delete-guard, improve (0.29.3) · *Medium*

- **Archiver** *(shipped)*: an `archived: true` header flag (set via
  `Skills::setField`, the slice-3 one-field writer). `Skills::all()` drops
  archived roles by default, so they fall out of the map, the system-prompt
  index, the Director's `role_find` **and** the overlap pass at once — their file
  and **all Assessor history are kept**. An **Archivés** disclosure at the foot of
  the catalogue lists them with one-click *Réactiver* (clears the flag).
- **Supprimer**, guarded *(shipped)*: `POST /api/roles/<role>/delete` is allowed
  only when `Assessor::runCount` is 0 (never executed); otherwise it refuses with
  409 and steers to Archiver — history must not be lost silently. The archived row
  disables Delete for a role with runs. Reuses `Skills::delete`. (A `/delete`
  POST, not a DELETE verb, to keep the app's one CSRF-guarded mutation path.)
- **Améliorer** *(shipped)*: an editor pre-filled with the current brief; the
  candidate is handed to `role_improve` — the Assessor's existing A/B eval job,
  which runs both briefs on the golden cases, scores them, and promotes the
  candidate only if it clearly wins. The same promotion decision the engine
  already makes; the result comes back in the chat. Protected roles expose none of
  the lifecycle actions (a shared `roleWritable` guard: 404 on a non-role, 403 on
  a protected one) — their lifecycle is the user's to manage directly.

### Slice 5 — top-of-rail switch + KB glance (0.29.4) · *Small–Medium* · *(shipped)*

- **Top-of-rail switch** *(shipped)*: the **assistant** switch (0.28.40
  one-sign-in) already lived at the top of the rail (the brand mark opens the
  assistant menu); slice 5 adds the **space** switch there too, in a dedicated
  `.rail-space-head` block directly under the assistant name — the current
  space's own colour drives its dot, and it is reachable from **any** view, not
  only the chat topbar. The account (sign-out) stays at the foot of the rail. The
  homogeneity choice: the rail switch and the chat-topbar switch share **one
  builder (`buildSpaceMenu`) and one close path (`closeSpaceMenus`)**, so both
  steer the same spaces through the same `switchSpace` — no second switch logic.
  On a phone the rail collapses to the bottom tab bar (`.rail-space-head` hidden)
  and the topbar switch carries on, so nothing is lost.
- **KB glance** *(shipped)*: the KB tab went from a one-line pointer to a
  read-only glance backed by one new aggregate, **`GET /api/library/kb`**, which
  reports the space's library root, the **recursive file count under that root**
  (from `Library::tree`'s `total`), its **subfolder** count, the whole library's
  size for context (`Library::stats`), and the active **hub pull** grant
  (`Spaces::hubGrants`) — reusing shipped methods only and **writing nothing**. An
  **"Open the Library"** link deep-links straight to the space's root folder
  (segment-encoded to match `libGo`). No new storage, no new trust edge.

### Slice 6 — polish & naming settle (0.29.5) · *Small* — **shipped**

- **Naming folded (user confirmed "Yes, as planned").** Routines → **Automatisations**
  (EN source "Automations"), Jobs/Emploi → **Travaux en arrière-plan** (the FR value
  for "Background jobs", changed from "Jobs en arrière-plan"), merged into **one**
  rail entry ("Automations") with `[Programmées · Arrière-plan]` subtabs — EN
  "Scheduled" / "Background". Kept deliberately view-only: both boards keep their
  hash (`#tasks`, `#jobs`), their views (`#view-tasks`, `#view-jobs`) and their
  loaders (`loadTasks`, `loadJobs`) unchanged. A shared `.subtabs` strip sits at the
  top of each view; `route()` lights the single rail entry (`.rail a[data-view=tasks]`)
  for either hash and toggles `.active`/`aria-selected` on the subtab links. The
  mobile "More" sheet folds the two old entries into the one.
- **Cross-links (Activity ↔ Pilotage).** Pilotage's Worksites panel carries a
  static link to **"See full activity and costs"** (→ `#activity`); the Activity
  header carries one back to **"Steer agents, roles and worksites in Pilotage"**
  (→ `#pilotage`). Activity stays the superset; Pilotage is the director's focused
  slice — the open decision below is settled this way (both stay).
- **Polish.** A friendlier empty state on the Scheduled board (invites a chat
  example rather than "No scheduled tasks yet"). Keyboard access: the rail and
  topbar space switches close on **Escape** and restore focus to their button; the
  subtabs are `role="tab"` with `aria-selected` and a visible focus ring.
- This completes the six Pilotage slices; **1.0.0** is reserved for the cut on the
  user's confirmation.

## 5. Open decisions (carry from the mockup)

- ~~`scope` reused vs. a new `specialization` field~~ — **settled (slice 3):**
  added a new optional `specialization` header field. `scope` already means
  private/shared/published (the visibility of a role), which clashes with a
  topical grouping label, so reusing it would have overloaded one field with two
  meanings.
- ~~Overlap threshold + whether to cache the pairwise matrix or recompute per
  visit~~ — **settled (slice 3):** recompute per visit (`Skills::overlaps()`,
  default threshold 0.6), a symmetric set-cosine over the same lexical tokens
  `Skills::find` ranks on — embeddings/`Meaning` are off in the test env, so the
  signal stays consistent with the registry's own ranker and holds no second
  similarity store to keep in sync. A suggestion, never stored as truth.
- ~~Merge default~~ — **settled (slice 3):** always a human-approved draft. The
  Compare panel drafts a merge instruction into the ordinary chat for the pilot
  to run with its own tools; merging is never automatic, even for near-identical
  roles.
- ~~Naming: confirm Automatisations / Travaux en arrière-plan and the single merged
  entry.~~ — **settled (slice 6):** confirmed by the user ("Yes, as planned"); one
  "Automations" rail entry with `[Programmées · Arrière-plan]` subtabs.
- ~~Does Chantiers fully replace the directive cards on Activity, or do both stay
  (Activity as the superset)?~~ — **settled (slice 6):** both stay, joined by
  reciprocal cross-links. Activity is the whole picture; Pilotage is the director's
  focused slice.
- Voice: keep the shipped record-then-transcribe (press, speak, release,
  transcribe) — lean yes for slice 1a — or add live/streaming transcription later?
  And: after transcribing a directive to the pilot, require a read-back-then-send
  (lean yes, dictation is never auto-sent) vs. an opt-in "send on release".
