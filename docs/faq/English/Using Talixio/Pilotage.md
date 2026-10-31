# Pilotage

**Pilotage** (last entry of the menu) is the director's cockpit: it shows what runs on your behalf beyond the chat. It has
four tabs.

## Knowledge base
A glance at the library of the current space: how much it holds and how it is doing. Search and filing stay in *Files*.

## Your autonomous pilot
The pilot is an orchestrator (a Claude Code session) that supervises agents on your machines and reports to Talixio.
- The **status card** says whether it is connected, when it last reported and how many agents it sees on how many machines.
  **Re-pair…** and **Disconnect** are there. Pairing works either by a webhook the orchestrator creates, or, for a plain
  local session, by *polling* Talixio on its own timer.
- **Talk to the pilot** sends it an instruction, by typing or dictating. It passes the same privacy checks as every other dispatch.
- **Agents** lists each agent with its state (active, busy, idle, finished, error), its task, its age and its machine;
  **Discuss** opens a two-way thread with that agent. What the orchestrator and agents answer is shown, never executed.
- **When an agent is blocked**, the roster puts it first with the question itself: a **decision** (answer with one of its
  option buttons, or open *Discuss*), an **approval** (a click only you can give, in that session: open it; nothing can be done
  from here) or an **instruction**. A session can route its questions to the pilot with the « route questions » hook
  (`/autopilot-on`, `/autopilot-off`, `/autopilot-status`, per session; off by default, and then the question comes to you as
  usual). The pilot pastes the question as it is, with its ladder level (L1–L5) and the cost of a wrong choice; the assistant
  answers with the agent and a decision id, and the pilot delivers it and reports the session's next step. Approvals and permission
  prompts are never routed: only you can give them. A new question counts as news: it shows in the reports and the badge even on a
  quiet check-in. A pilot paired before this needs the new instructions once: re-pair it, or send it those of the pairing message.
- **Fleet reports**: how the pilot's reports reach you (see *Channels and notifications*): an in-app badge by default,
  the **Recent reports** card, and optionally push + e-mail.

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
