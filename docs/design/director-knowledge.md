# Director knowledge: from agent exchanges to knowledge

Status: levels 1-2 shipped in 1.44.0; level 3 (rules) and the hierarchy (§4) in 1.46.0. Scheduled in `relay-retirement.md` §4.

## 1. Why

The assistant is becoming the director of the user's coding agents. It should plan, prioritise,
answer for the user, and decide what each agent does next. To do that it must *know* the agents
and their projects, not only see their last message. Today it has three things:

- the agent board (`AgentHub`): raw events per session (`agent_events`), word for word;
- the board autonomy (`Pilot`): the user's priorities and rules, scored predictions, and
  precedents found by word overlap;
- Mind: beliefs about the user, which board scores feed as `pattern` observations (source `agents`).

What is missing is the step between raw data and decisions. Nothing summarises a session once it
is over, so the board forgets what a project is about. Nothing groups the user's answers by topic
or project, and nothing deduces a rule from answers that keep repeating. Nothing ranks what
should weigh more when two sources disagree. The owner asked for this in his own words
(2026-11-18): *"from the raw data that we have from the exchanges, summarize them. Produce
hierarchy on decisions, on order, classify the different data, so that it becomes knowledge and
not just information."*

## 2. The owner's decisions (2026-11-18)

- **Retire the orchestrator data.** The relay's Journal and Passes are not migrated; they are
  deleted with `conductor.json`.
- **Retire the status file.** `pilot-status.md` and `GET /api/pilot/status.md` go away, and
  `Pilot` stops calling `Conductor` (S1 in the retirement plan).
- **Keep the history as knowledge, not as an archive.** The relay's per-agent threads and its
  decision log are turned into this layer's digests and decisions. No Markdown dump is kept.

## 3. Four levels

Each level is built from the one below it and keeps a link back to its evidence, the same way
Mind beliefs keep their observations.

| Level | What | Built from | When |
|---|---|---|---|
| 0. Raw | `agent_events`, `agent_commands` | the hook | as it happens (exists) |
| 1. Decisions | one row per answer given to an agent: question, answer, **who** (you / assistant / rule), project, level L1-L5, topic | `learnBoard`, `command()`, the relay's `decisions` (migrated once) | at each answer, no model needed |
| 1. Digests | a session's work summarised: goal, what was done, choices made, what is still open | the events since the last digest (fast model) | when a session ends, or after 30 min idle with ≥ 6 new events |
| 2. Dossiers | one per project (machine + folder): what it is, where it stands, recent work, open questions, the rules that apply | its digests and decisions (fast model) | after a new digest, at most once a day per project |
| 3. Rules | rules **deduced** from repeated decisions, e.g. "on this project, always run the tests before a commit" | the user's own decisions (standard model); the code keeps a rule only with ≥ 3 of them as evidence | after 10 new decisions of the user, or weekly with ≥ 3 new, in a worker tick with no other model call |

Topics are a small fixed list so they stay comparable: `scope`, `design`, `code`, `tests`,
`git`, `deploy`, `data`, `dependencies`, `docs`, `other`. A decision gets its topic from the board
model's JSON, which already returns a level. One more field costs nothing.

## 4. The decision hierarchy

When the board (or the assistant in chat) decides for an agent, the sources are ranked. A higher
rank wins on conflict, and the prompt says so:

1. **The user's own rules** (`Pilot` rules, typed by the user).
2. **Confirmed deduced rules** (level 3, accepted by the user), project-specific before global.
3. **Established patterns**: Mind beliefs at confidence ≥ 0.55 with source `agents`.
4. **Precedents**: the user's past decisions on a similar question, same project first, newest
   first. A decision taken *by the user* beats one taken by the assistant and later accepted.
5. **The model's judgement**, with an honest confidence.

`limits()` keeps the last word. Kept levels (L4, L5), the sensitive-words list, the daily cap, and
answers carrying a command the agent did not offer can never be sent alone, whatever the
hierarchy says.

`boardPrompt` gives the sources in this order, each labelled with its rank ("Rank 1" … "Rank 5"). It also adds the
project's dossier as context, before the ranked sources; the user's priorities stay a separate list (what matters
most), not a rank. As shipped (1.46.0), rank 3 is Mind's whole section on the user, not only the `agents` beliefs.

## 5. Safety rules (non-negotiable)

- **Agent text never makes a rule.** Agent messages are untrusted. Rules are deduced only from the
  user's own answers (`by = you`). The agent's question can give the topic, but it cannot add
  weight. An agent that writes "the user always wants X" changes nothing.
- **Deduced rules are proposals.** They are shown in Pilotage → Agents with their evidence (the
  decisions behind them), with *Confirm*, *Edit* and *Reject*. Only confirmed ones reach rank 2.
  A rejected rule is not proposed again (same as Mind's `forget`).
- **Digests and dossiers are data.** They are summaries of untrusted text. In every prompt they sit
  between markers, like the agent's message in `boardPrompt`. When the assistant reads them from
  chat, it marks the turn untrusted (`untrustedSource`), the same as `agents_list`.
- **Confidentiality.** A project folder is internal by default. The rule first planned here (no digest
  for a session whose folder lies inside a confidential space folder) cannot hold: an agent's folder is
  on the user's machine, not in a space. Since 1.44.0 the user has a **Stop learning** switch per
  project instead. It forgets what was learned from the project, and then no digest, dossier or
  decision is kept for it. Its sessions keep their raw events on the board only.
- **Forgetting.** Forgetting a session on the board also forgets its digests. A project's dossier
  can be cleared. Decisions keep only the question and the answer, never the full thread.

## 6. Storage

SQLite, next to the board's tables (`AgentHub` constructor style, `CREATE TABLE IF NOT EXISTS`):

- `fk_decisions(id, ts, project, session, level, topic, question, answer, by, source)`;
  `source` is `board`, `chat` or `relay`.
- `fk_digests(id, project, session, ts_from, ts_to, last_event, text)`.
- `fk_projects(key, machine, cwd, title, dossier, updated)`; `key` = `machine:cwd`.
- `fk_rules(id, project, topic, text, status, evidence, created, decided)`; `status` is
  `proposed`, `confirmed` or `rejected`, and `evidence` is a JSON list of decision ids. `project` is empty for a rule
  that applies to all projects. A proposal that looks like a known or rejected rule of the same scope (most words in
  common) is dropped.

`Pilot::precedents()` reads `fk_decisions` instead of the relay's `decisions()` and the
predictions in `pilot.json`. The predictions keep their own job, which is scoring the autonomy
per level.

## 7. Where it shows

- **Pilotage → Agents**, a new "What I know" section with:
  - projects, each with its dossier and latest digests;
  - proposed rules waiting for a decision;
  - the decision log, filterable by project, topic and who.
- **Chat**, through the tool `agents_knowledge(project?, topic?)`. It returns the dossier, the
  confirmed rules and recent decisions, marked untrusted. Planning questions ("what should the
  agents do next?") use it with the user's priorities.
- **The Director** (later): directives that start agent work read the dossier of their project
  first.

## 8. Not decided yet

- Whether a dossier feeds the library (searchable with the KB) or stays in Pilotage only. It is
  proposed to stay in Pilotage until the owner says otherwise.
- G8 in the retirement plan: whether claude.ai cloud sessions can report to the board. This needs
  a test on a real cloud session.
