# Pilotage

**Pilotage** (last entry of the menu) is the director's cockpit: it shows what runs on your behalf beyond the chat. It has
four tabs.

## Knowledge base
A glance at the library of the current space: how much it holds and how it is doing. Search and filing stay in *Files*.

## Your autonomous pilot
The pilot is an orchestrator (a Claude Code session) that supervises agents on your machines and reports to Talixio.
- The **status card** says whether it is connected, when it last reported and how many agents it sees on how many machines,
  and how many wait for you. **Re-pair…** and **Disconnect** are there, with the **Fleet reports** switch (In-app badge, Push +
  e-mail, Off). Pairing works either by a webhook the orchestrator creates, or, for a plain local session, by *polling* Talixio.
- **Talk to the pilot** (below the tabs) sends it an instruction, typed or dictated. It passes the same privacy checks as every
  other dispatch.
- Three tabs, each with a list on the left and a pane on the right that scrolls on its own — the page never grows with the number
  of messages. On a phone the pane replaces the list, with « ‹ Back ». Each tab carries a count:
  - **Needs you**: the sessions that wait for you, each with the question as it was asked, its level (L1–L5), the options as
    buttons and the cost of a wrong choice. A **decision** you answer with a button (or *Answer another way…*, which opens the
    agent's conversation); an **approval** (a click only you can give, in that session, e.g. a revoked token) has no button:
    open the session; an **instruction** is for you to tell the agent what to do next. Under *Decided recently* you see **who
    answered what**: you, or the assistant on your behalf.
  - **Agents**: the roster with a search, the filters Waiting / Active / Idle / Offline, and *Group by machine*. Pick one to read
    its conversation: the question it waits on stays pinned on top, a message that is too long is cut with *Show all*, older
    messages load with « ↑ n older messages », and the box at the bottom writes to that agent (or dictate). A red number on a row
    counts its replies you have not read; it clears when you open the conversation. Each agent's replies stay in its own
    conversation.
  - **Journal**: only the fleet-wide reports, grouped by day, filtered All / Alerts / Summaries, with a search; click one to read
    it in full. A session that starts waiting for you adds an alert.
- A session can route its questions to the pilot with the « route questions » hook (`/autopilot-on`, `/autopilot-off`,
  `/autopilot-status`, per session; off by default, and then the question comes to you as usual). The pilot pastes the question as
  it is; your answer goes back with a decision id, and the pilot reports the session's next step. Approvals and permission
  prompts are never routed: only you can give them. A new question counts as news: it shows in the badge even on a quiet
  check-in. A pilot paired before this needs the instructions of the pairing message once: re-pair it, or send it those.
- **The assistant can read the fleet's questions.** Ask « what are the agents waiting for? »: it lists the waiting questions
  (options, level, cost of a wrong choice), the latest decisions and who took them, recent alerts, and any agent's
  conversation. It reads only; it answers (with the decision id) just what is yours to leave to it, never levels L4/L5,
  pushes, deploys, deletions, e-mails, credentials or approvals.
- **Fleet reports**: how the pilot's reports reach you (see *Channels and notifications*): an in-app badge by default, the
  Journal and the conversations, and optionally push + e-mail.

## Roles
The catalogue of the roles your assistant can take, grouped into one card per specialization. Each role is a node whose ring shows
its acceptance rate and whose size follows how much it runs; **click one** to open its detail: metrics, trend, its
specialization and the actions *evaluate, publish, improve (A/B), archive, delete*. Roles that look nearly identical are flagged.
- **+ New role** creates one (name, purpose, optional specialization, brief); a name already in use is refused, and the
  assistant checks for near-duplicates.
- A role can also be created by asking the assistant, or installed from the store.
- **Archived…** reopens archived roles. The *assessor* role is protected: it has no lifecycle actions.

## Worksites
The director's directives: open worksites, their progress and steering. They are launched from the chat box, with
*Talk to the pilot*.

On a phone, the tabs scroll sideways.
