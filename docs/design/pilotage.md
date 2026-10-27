# Pilotage — the director's cockpit (phased build)

> Status: design + build plan, Oct 2026. Forward plan. This is the **human-facing
> UI** over machinery that is already shipped: the Director (directives/worksites),
> the Assessor + role registry (`agent-factory.md`), and the Conductor/Fleet bridge
> (`orchestration.md` §9.1–§9.5, all shipped through 0.28.99). Nothing here opens a
> new trust edge; it surfaces, groups and governs what the services already expose.
> Validated against the clickable mockup (`scratchpad/fleet-ui-mockups.html`, v7).

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
| Specialization grouping | — | a `scope`/`specialization` header field + grouping |
| Overlap / "doublon" signal | `Skills::find` ranking (embeddings/`Meaning`) | a pairwise closeness pass + the banner/merge panel |
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

- **Specialization**: read the existing `scope` header field (or add an optional
  `specialization` field, preserved by `Skills::save`'s header order) and group
  the map by it. A role with none falls into "Sans spécialisation". Pure grouping
  by shared topic/actions/goals — **no** behavioural coupling.
- **Overlap** (the separate, finer signal): a pairwise closeness pass over role
  briefs reusing `Skills::find`/`Meaning` embeddings; pairs above a threshold
  surface the banner ("Overlap détecté — researcher ≈ web-researcher, consignes
  ~87 % proches") and the inline hint on the detail card. Computed on demand /
  cached, not stored as truth.
- **Merge panel**: side-by-side brief compare + a "fusionner" action that drafts a
  combined role via `role_improve` (human-approved, never automatic). Overlap is a
  *suggestion*, never an auto-merge.

### Slice 4 — role lifecycle: archive, delete-guard, improve (0.29.3) · *Medium*

- **Archiver**: an `archived: true` header flag (via `Skills::save`); archived
  roles drop out of the map and the Director's `role_find`, but their file and
  **all Assessor history are kept**. **Archivés…** opens the filtered list with a
  one-click *Réactiver*.
- **Supprimer**, guarded: allowed only when `Assessor` run count is 0 (never
  executed); otherwise the panel refuses and steers to Archiver — history must not
  be lost silently. Reuses `Skills::delete`.
- **Améliorer**: the before/after golden-case A/B panel over
  `Assessor::improve`+`gate`, surfacing the same promotion decision the engine
  already makes.

### Slice 5 — top-of-rail switch + KB glance (0.29.4) · *Small–Medium*

- Lift the **assistant** switch (0.28.40 one-sign-in) and the **space** switch
  (`switchSpace`) to a dedicated block at the **top of the rail** — direct access,
  not buried in the account menu. Assistant name on top, account (person) stays at
  the bottom; per-space colour drives the space dot.
- **KB** tab: a read-only summary of the space's grounding — library root, counts,
  and any active hub pull (`Spaces::hubGrants`) — with a link into Library for
  edits. No new storage.

### Slice 6 — polish & naming settle (0.29.5) · *Small*

- Fold the settled naming: Routines → **Automatisations**, Jobs/Emploi → **Travaux
  en arrière-plan**, merged into one entry with `[Programmées · Arrière-plan]`
  tabs (pending the user's final nod).
- Empty states, keyboard access, and the Activity ↔ Pilotage cross-links so the
  two boards agree (Activity = everything; Pilotage = the director's slice).

## 5. Open decisions (carry from the mockup)

- `scope` reused vs. a new `specialization` field — lean: reuse `scope` if its
  current meaning doesn't clash, else add `specialization`.
- Overlap threshold + whether to cache the pairwise matrix or recompute per visit.
- Merge default: always human-approved draft (lean yes) vs. allow auto-merge for
  near-identical low-risk roles.
- Naming: confirm Automatisations / Travaux en arrière-plan and the single merged
  entry.
- Does Chantiers fully replace the directive cards on Activity, or do both stay
  (Activity as the superset)? Lean: both, Pilotage is the focused view.
- Voice: keep the shipped record-then-transcribe (press, speak, release,
  transcribe) — lean yes for slice 1a — or add live/streaming transcription later?
  And: after transcribing a directive to the pilot, require a read-back-then-send
  (lean yes, dictation is never auto-sent) vs. an opt-in "send on release".
