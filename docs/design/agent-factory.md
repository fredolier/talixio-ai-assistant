# The agent factory — director, roles, and a self-improving capability registry

> Status: design spec. Phase 1 (the role registry) is built as of 0.28.85; the rest is forward plan.
> Mockups: the "Factory Director UI" canvas
> (Chat · Board · Phone · Activity · Capability library · Map).
> This is the forward plan discussed Oct 2026; build it in the phases at the end,
> only when real work of the "when it earns its keep" class shows up.

## 1. Why — what this is for

Talixio already runs single agents with tools and background jobs. This adds a
layer above that: an assistant that can **organise a team of its own sub-agents
toward a longer goal, keep improving how it does your recurring work, and reuse
what it learns instead of reinventing it.**

It is **artillery**. It is wrong for most requests, and the design actively keeps
it holstered (see Parsimony). A request earns the factory when it has **two or
more** of these properties:

1. **Breadth / parallelism** — many independent pieces (6 posters, 10 competitors, 50 emails, 12 contracts).
2. **Duration** — the goal outlives one sitting and should run in the background, reporting back.
3. **Mixed expertise** — genuinely different hats with different rules, tools and *model sizes* (researcher, legal reviewer, writer, checker).
4. **Repeatability** — you'll do this *class* of task again; capturing it compounds.
5. **A quality gate** — the output must be checked before it's trusted (contracts, figures, compliance).

None of these → a single quick agent. Two or three → the factory.

**Example jobs** (grounded in Talixio): a GDPR-safe hosting comparison across 8
providers (uses the EUR-Lex / Légifrance connectors); supplier/contract due
diligence; an ongoing competitor price watch; a quarterly board pack (gather →
draft → charts → `kb_make_document` → assessor checks figures); triaging 300
unread emails and drafting the 20 that matter.

**The product value:** it turns the assistant from *a tool you drive* into *an
operation you run* (you set goals and standards, at a budget, under your eye),
and — because roles/teams are captured and measured — it gets better at *your*
specific recurring work. For the multi-user product, a **shared, self-improving
capability registry** means an organisation's assistants get smarter together.
That is the differentiator.

## 2. Vocabulary

- **Skill** — an atomic reusable method (already exists in Talixio: Markdown + header, use-counted, indexed, meaning-searchable). The knowledge base is built on these.
- **Role** — a worker definition = *a bundle of skills* + a model tier + tools/connectors + a brief + its own quality bar (eval spec). A role is **not** an atom; it's made of skills. Stored as an enriched skill, not a new silo.
- **Team** — a named group of roles that work well together, saved for reuse.
- **Assessor (role #0)** — the role that scores other roles against their metrics and gates improvements. Itself a versioned role; changes to it are human-gated.
- **Director** — the main assistant when a request needs a team: it decomposes the request, assembles/【re]uses roles, sizes the model per job, runs them, reports back, and stays in conversation. **The director is itself a role** (so a sub-director is just a role that spawns roles — this keeps "teams of teams" possible later without a new concept).
- **Directive** — one goal/objective the director is driving (the unit of control and prioritisation, P1/P2/P3). A request becomes a directive.
- **Run** — one execution of a directive; groups its workers.
- **Worker** — one running instance of a role inside a run. Mechanically a background **job** (reuses the jobs engine), but presented under its directive, never as a flat list.

## 3. Architecture — reuse, don't duplicate

The anti-duplication principle applies to the system itself: build on what exists.

| Need | Reuse |
|---|---|
| Knowledge base of methods | **Skills** (`skill_save/read/delete`, indexed, meaning-search) |
| Roles / teams storage | **Skills, enriched** with a role/team header — no parallel table |
| Fleet sharing + review | **Store / Catalog** (connectors/apps already share this way, admin-reviewed) |
| "Is there already one like this?" | **Embeddings / meaning-search** |
| Running workers | **Background jobs** (tiers, status, cost, cancel) |
| Roles with their own rules/isolation | **Spaces** (a space is a role's sandboxed context) |
| Self-improvement loop | **Mind + feedback/ratings** |
| Live updates | **Notifications inbox + heartbeat timeline** |
| Voice in | **Voice notes** (transcription already present) |
| Local law / documents the roles use | the **Légifrance/EUR-Lex** clients and **`kb_make_document`** just shipped |

**Role/team data shape** (on the skill header): `kind: role|team`, `purpose`
(the one line you search on), `brief`, `tier`, `skills: []`, `tools: []`,
`when_to_use` / `when_not`, `metrics: []`, `golden_cases: []`, `version`,
`uses`, `acceptance`, `scope: private|space|shared`, `provenance`. A team adds
`roles: []`.

## 4. The director — parsimony first

- **Default is inline.** The director's first question is always "can I just do
  this in one step, no sub-agents?" Spawning a team is the exception, justified
  by real parallelism/expertise — never the reflex.
- **A complexity budget per request:** estimate effort, pick the *smallest
  structure that fits*, escalate only when the simple path fails.
- **Parsimony is a metric on the director itself** (it's a role): agents spawned,
  tokens, time, $ *relative to outcome*. Ten agents for a trivial task scores
  badly, so the minimal correct structure wins the eval. Simplicity is enforced
  by measurement, not by hope.
- **Always available & interruptible:** the director stays in the conversation
  while workers run; you steer in plain words ("posters first", "make Lisbon
  warmer") and it folds changes into work in flight.
- **Asks before it spends / when unsure:** proposes a plan + rough cost with
  Start / Adjust options; flags budget with concrete trade-offs (cap, downgrade
  a model) rather than silently draining quota.
- **Reports on a cadence, not a flood:** quiet status lines at milestones, full
  messages only when there's something to decide.

## 5. The registry — reuse over sprawl

- **Search-before-create is a hard rule.** `role_find(purpose)` does a
  meaning-search; the director must reuse (≥ threshold), else adapt an existing
  role, else create — then save back. On save, an embedding check warns/merges
  near-duplicates.
- **A skill shows every role that uses it** (and the inverse: select a skill →
  see dependants), so nothing drifts into a quiet duplicate.
- **Scopes mirror privacy:** private / space / shared. **A Secret-space role is
  never published.**
- **Sharing via the store:** publish a role/team to the fleet (admin-reviewed,
  like a connector). Co-improvement = a reviewed, versioned submission back.
  Dedup at publish via embeddings.

## 6. Metrics & improvement — honest, not self-congratulatory

Metrics-first. Each role carries an **eval spec**.

- **One anchor metric is un-editable: real-world acceptance + cost** (did you
  accept/use/👍 it, at what cost). External → can't be gamed from inside.
- **Proxy metrics may evolve** (objective where possible: tests pass, citations
  resolve, document rendered, rubric score; plus cost/efficiency), **but a new
  metric must backtest — correlate with the anchor on past runs — before it's
  adopted.** This is how good new metrics are discovered rather than invented to
  flatter.
- **Improvement is promoted by A/B on golden cases**, not opinion: run old vs new
  role on the role's reference tasks, score both, promote only if the new version
  wins on the weighted metrics.
- **The assessor (role #0)** computes this and may auto-approve ordinary role
  changes. **Human-gated, always:** changes to the assessor itself, any **metric
  change**, any role that gains a **new capability** (tool/connector/environment),
  and any **publish to the fleet**. (Guards against a degraded assessor blessing
  degraded roles, and against metric gaming / Goodhart.)

## 7. Execution environments — declared, isolated, phased

A role **declares** the environment it needs; the user approves that declaration;
the director provisions exactly that — the same model as the existing app rule
("an app never has more power than its declaration").

| Tier | What | Status |
|---|---|---|
| A. Headless web | browse, click, read pages | exists (Browser/Playwright) |
| B. Sandboxed shell/code | compute, convert, files | exists (bubblewrap) |
| C. Disposable desktop | open real apps, GUI automation | **later** — an ephemeral throwaway container per task (own Docker, no mount of data/keys/host, given only the task's inputs, destroyed on finish). Big; opt-in; don't let it block the registry. |

## 8. Observability & UI — the glass box

Never a black box. Surfaces (see the mockup canvas):

- **The conversation (home).** The **main chat IS the director** — no separate
  director chat. It gains a team behind the scenes and keeps talking. Status
  lines are woven in but visually quieter than real messages (a muted inset),
  so the thread stays a conversation, not a log. Clapboard 🎬 marks director
  turns. A slim live bar (`7 workers · $7.14 left`) with **Open board ▸**.
- **The board.** Budget meter (burn-down, turns amber, director warns),
  directives column (P1/P2/P3, raise/pause/cancel; raising one slows the others),
  worker cards (role · tier · status · step N/M · progress · cost · pause/retask),
  the queue (assessor before delivery), and the director conversation with
  budget trade-offs.
- **Phone glance.** Budget, directives by priority, the running worker, the
  director's budget nudge with one-tap controls.
- **Activity.** Directives and standalone jobs as **single rows, side by side**;
  **workers live inside their directive** (expand, or open its board), never as a
  flat list. A "show individual workers" toggle (off) for debugging. This is the
  evolution of today's Jobs page — same "what's running" meaning, right altitude.
- **Capability library.** Teams ▸ roles ▸ skills, browsable. Each role card
  names its **skills (blue)** separate from **tools (grey)**, with tier, uses,
  acceptance %, metrics, and health (improving ↑ / duplicate ⚠ / under review).
  Its own top-level section — **not** crammed into Memory.
- **Map.** Regions are **skill clusters**; a role sits where its skills are
  (selecting it draws links to its skills). Team membership is tags, not rings
  (a role reuses across teams). Duplicates pop in red with a merge prompt. The
  lens for spotting sprawl.

**Voice everywhere.** A mic is a standard affordance on every input (composers,
search, inline fields). Two modes: **dictate** (speech→text you can edit — the
default) and **hands-free talk** (a spoken turn straight to the director — best
when you're away, which is when the factory matters most). Transcription reuses
voice notes; a live "listening" state.

## 9. Security

A role is a **capability declaration**, like an app, and inherits the same model:
a role never has more power than its declared skills/tools/connectors/environment;
the user approves that declaration; a shared role can't silently gain power;
Secret-space roles never leave the server. No second trust model — the existing
app/connector approval and privacy guards cover roles, teams, and the registry.

## 10. The Jobs vs Activity decision (resolved)

Factory workers **run on the jobs engine** but do **not** appear as individual
rows on the Jobs/Activity page — that would bury the jobs you care about. The
page shows the **directive (run) as one row**; workers live inside it / on the
board. Evolve "Jobs" into **"Activity"**: one place for "what's running in the
background", with directives and standalone jobs as siblings at the same
altitude, workers tucked inside.

## 11. Phased build (value early, danger gated)

1. **Registry on skills + search-before-create** (local). Kills duplication. *Small.* — **built, 0.28.85**: roles are enriched skills (`kind: role` + purpose/tier/skills/tools in the header); `role_find` ranks what already covers a purpose, `role_save` warns on overlap.
2. **Assessor role #0 + per-role eval spec + golden-case A/B promotion** (metrics-first; the improvement gate). *Medium.*
3. **Director + glass box** (conversation-home + board + Activity), workers as jobs, **Tiers A+B only**. *Medium.*
4. **Fleet sharing via the store** (review + embedding-dedup, human-gated). *Medium.*
5. **Tier C disposable-desktop** capability, opt-in, strongly isolated. *Large, later.*
6. *Only if real need:* **teams of teams** (a sub-director is a role; runs nest; the glass box already renders a tree; depth limit + budget-propagation-downward + the capability approval gate).

Metrics-first throughout; every new power through the existing app-style approval gate.

## 12. Open decisions

- Metric promotion: auto on positive signal, or always a one-click human approval for a draft?
- Map default: cluster by domain (legible) or pure embedding similarity (tighter for dedup)? Lean: domain with similarity overlaid.
- Naming for the library section: *Capabilities* / *Workshop* / *Studio* / *Crew*.
- Teams: sections inside the library, or their own composition page (team → roles → skills)?
- Status cadence + where status lives (in-thread quiet vs a collapsible activity tray). Lean: in-thread quiet.
- Voice: dictate-into-box default with a hands-free toggle (recommended), vs send-on-silence.
