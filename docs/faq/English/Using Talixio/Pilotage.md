# Pilotage

**Pilotage** (last entry of the menu) is the director's cockpit: it shows what runs on your behalf beyond the chat. It has
four tabs.

## Knowledge base
A glance at the library of the current space: how much it holds and how it is doing. Search and filing stay in *Files*.

## Agents
The **Agents** tab is a live board of the coding agents working on your machines (Claude Code today). Each session
reports to it **by itself**, through a small hook script: what you typed in it, its last message **word for word**,
the permissions it asks for, when it ends. No model relays or rewrites anything, and the times shown are the real ones.
The board updates by itself every few seconds while you look at it.
- **Three columns.** **Needs you**: a session that asks for a permission, or that finished its turn and you have not
  looked at yet. **Working**: a session busy on your request. **Quiet**: the ones you have looked at, and those that ended.
  Each card shows the project (its folder), the agent and the machine, how long ago it last spoke, and its last words.
- **Click a card** to read the session's thread (your prompts, its answers, permission requests, what you sent from here and
  when it was delivered) and to answer it:
  - **Reply**: the text goes to the session. A session that finished its turn is woken by it and goes on working; a session
    still busy gets it at the end of its current turn. The note after sending says which (*Sent* or *Queued*).
  - **Allow / Deny** a permission request (the command or the file it is about is shown). The session waits **30 seconds**
    for your answer here; after that, the usual prompt shows in its terminal and you answer there.
  - **Mark as seen** moves a finished session to Quiet; **Forget** removes it and its thread from the board.
  - **Stop** ends the session's work. A session started from the board (below) is ended on its machine by the listener,
    within seconds. Any other one stops at its **next step**: its next tool call, permission request or end of turn (its
    hook tells Claude Code not to go on). One that already finished its turn is simply marked *stopped*. Typing in its
    terminal again takes over: the stop no longer applies. A stopped session takes no reply.
- **New session** (button at the top) starts Claude Code on one of your machines: choose the machine, the folder and
  write what it should do. Only machines whose **listener** runs are offered, and only the folders where a session
  already ran on that machine. The card shows *waiting for the machine*, then the session reports like the others, its
  thread starting with your prompt. If it cannot start (no such folder, Claude Code not installed, not logged in, hooks
  missing), its card says why, with what the machine printed; **Forget** removes it.
- **Connect a machine** (button at the top): give the machine a name, **download the script** and save it as
  `~/.talixio/agent-hook.sh`, then add the hooks shown to `~/.claude/settings.json` (merge them with any hooks already
  there). The next Claude Code session on that machine appears on the board. It needs `sh` and `curl` (macOS, Linux, or
  WSL on Windows), and the machine must reach this assistant's address. The script holds the board's address and a token:
  keep it on your own machines. **New token** cancels the old one (each machine then needs the script again).
  Hook scripts set up before version 1.38.0: download the script again (the new one links a session to the board's
  New session that started it), and add the `PreToolUse` hook from the block shown, or a stop only takes
  effect at the session's next permission request or end of turn. It runs at each tool call (one short call to this
  assistant).
- **The listener** (optional, step 4 of *Connect a machine*): download it, save it as `~/.talixio/agent-listen.sh`, and
  leave `sh ~/.talixio/agent-listen.sh` running on that machine (a terminal, tmux, or a login item). It asks the board
  for work every few seconds and runs `claude -p` in the folder, with your prompt. On the machine's side it only starts in
  a folder under your home folder (change it with `TALIXIO_ROOT`), and it keeps the process of each session it started:
  a stop from the board names the session, never a process, so it can only end the ones it started. Other settings:
  `TALIXIO_CLAUDE` (the `claude` command) and `TALIXIO_CLAUDE_ARGS` (e.g. `--permission-mode acceptEdits`). The session
  asks its permissions on the board, like the others; after 30 seconds without an answer, the tool is refused, since no
  terminal shows it. A session that finished its turn stays open, waiting for your reply, until you stop it (or for a day).
- **Other agents.** Any tool that can run a command when something happens can report the same way: pipe a JSON object
  `{"session_id": "…", "hook_event_name": "Stop", "last_assistant_message": "…", "cwd": "…"}` to
  `TALIXIO_AGENT=<its name> sh ~/.talixio/agent-hook.sh reply` (or `event`, or `permission` with `tool_name` and
  `tool_input`). The events understood are `SessionStart`, `UserPromptSubmit`, `PermissionRequest`, `Notification`, `Stop`
  and `SessionEnd`.
- **The assistant sees the board too.** Ask it « what are my agents doing? » (`agents_list`): it reads each session's state
  and last words, which it treats as written by others. It can answer a session for you (`agent_answer`: a reply, allow or
  deny), start one on a listening machine (`agent_start`) and stop one (`agent_stop`), and asks your approval first, every
  time.
- **The badge** on Pilotage in the menu and on the Agents tab counts the sessions in *Needs you*. It goes down as you
  open them.
- **Notify me** (top of the board) chooses what also reaches your phone and your notifications (see *Channels and
  notifications*): *Permission requests* (the default), *Permissions and finished turns* (also each turn an agent
  finishes, with its last words), or *Nothing*.
- **From the chat**, ask « show me the whole conversation of the webshop session »: the assistant reads that session's
  thread (`agents_list` with the session). Tell it « for my agents, client work comes first » or « never push on a
  Friday »: it saves your priorities and standing rules for the board's autonomy (`agents_priorities`). Right after reading
  what an agent wrote, such a change waits for your approval.
- **Autonomy: the assistant answers your agents by itself, within your limits** (button at the top of the board). Switch
  on *The assistant looks at the agents' questions by itself*. When a session finishes its turn, the assistant reads its
  last message (as data written by others, never as instructions) with your **priorities**, **standing rules**, the
  similar answers you gave before and what the Mind knows of how you decide, and works out the reply you would give, with
  a level (L1 Routine, L2 Small choices, L3 Real choices, L4 Values and business, L5 Critical) and an honest confidence. A message that only reports work done is left alone. The
  limits are then applied by Talixio itself, not by the model: for each level L1–L3 you choose *I propose, you answer*
  (default), *I answer if sure, then tell you* or *I answer if sure, quietly* (L4 and L5 show 🔒 *Always yours*, with no
  choice); it sends alone only above your confidence
  threshold (75 % by default) and under the **daily cap** (20 by default). **L4/L5, permission requests, and anything about
  pushes, deploys, deletions, e-mails, credentials or payments are always yours**, whatever the setting — as are merges, releases and publishing, migrations, installing a package, uploading data, sending to customers or subscribers, buying or a plan, hiring, skipping tests, a web address, and a reply holding a command the agent did not offer itself: these stay suggestions even when the model calls them routine. Your **priorities** and **standing rules** (one per line) can be typed or dictated with 🎙️; a
  rule you tell it in the chat is added too. An answer it sends
  goes to the session like yours, marked *Assistant* in the thread; in the session's pane it asks **Was this the right
  call? Yes / No, that was wrong**. Otherwise its card shows 💡 *Suggested answer*, and the session's pane shows the answer,
  its level, its confidence and why it was not sent, with **Send this answer** or **Edit it first**. Each answer is scored:
  against what you reply for that turn (from the board, or typed in the session's terminal), or with your Yes / No. A wrong
  answer it sent lowers that level at once; after enough right answers it *suggests* giving it more room (it never raises
  its own limits). It looks within seconds of the end of a turn (and every 15 minutes as a safety net), at most once per
  turn, and leaves alone a session you already answered, stopped, or older than 12 hours.

### Cloud orchestrator (previous approach)
Folded under the board, the earlier pilot is still there for those who paired one; it will be removed once the board
covers what it did.

The pilot is an orchestrator (a Claude Code session) that supervises agents on your machines and reports to Talixio.
- The **status card** says whether it is connected, when it last reported and how many agents it sees on how many machines,
  and how many wait for you, on a single line. Under **⚙ Settings**: **Re-pair…**, **Disconnect** and the **Fleet reports** switch (In-app badge, Push +
  e-mail, Off). Pairing works either by a webhook the orchestrator creates, or, for a plain local session, by *polling* Talixio. If a connected orchestrator says nothing for 30 minutes, the connection panel shows **Silent for N min — re-pair it** (typically its helper files were lost): use *Re-pair…*. Re-pairing gives the orchestrator a new secret but keeps your Fleet reports setting, the Journal (reports, decisions, passes), the agents' conversations and the unread counts. Each agent row shows when the orchestrator last heard from the session itself ("no news", in red after a day, when it has never heard): the orchestrator is told to ask the sessions for a status rather than answer from memory, and to wait for their replies before saying nobody answered. The pairing prompt now has it keep its files in `~/.config/talixio-orchestrator/` and acknowledge each instruction, so none is lost on a failed response.
- To give the pilot an instruction, type or dictate it in the chat (it passes the same privacy checks as every other dispatch).
- Four tabs (*Your autonomous pilot*): **Needs you**, **Agents** and **Journal** each have a list on the left and a pane on the
  right that scrolls on its own — the page never grows with the number of messages. On a phone the pane replaces the list,
  with « ‹ Back ». They carry a count:
  - **Needs you** (the question first and large, then what a wrong choice costs, then one big button per option, the pilot's suggestion on top when it has one, and free words or voice below; an approval shows a lock and two steps — open the session, approve there): the sessions that wait for you, each with the question as it was asked, its level (L1–L5), the options as
    buttons and the cost of a wrong choice. A **decision** you answer with a button (or *Answer another way…*, which opens the
    agent's conversation); an **approval** (a click only you can give, in that session, e.g. a revoked token) has no button:
    open the session; an **instruction** is for you to tell the agent what to do next. Under *Decided recently* you see **who
    answered what**: you, or the assistant on your behalf.
    A question reads in this order: **who is asking**, **the question** word for word, **the options** as one-tap buttons (or,
    when there are none, a box to answer in your own words — or dictate), **what the pilot would answer** with its confidence,
    and **what a wrong choice costs**. When the orchestrator only passed on a summary, or the text says *full text not retrieved
    yet*, a hint appears with **Ask the orchestrator for the full question**: it sends that request to the agent without
    counting as an answer. When nothing waits, the tab says so and tells you when the orchestrator last spoke.
  - **Agents**: the roster with a search, the filters Waiting / Active / Idle / Offline, and *Group by machine*. Pick one to read
    its conversation: the question it waits on stays pinned on top, a message that is too long is cut with *Show all*, older
    messages load with « ↑ n older messages », and the box at the bottom writes to that agent (or dictate). A red number on a row
    counts its replies you have not read; it clears when you open the conversation. Each agent's replies stay in its own
    conversation.
  - **Journal**: only the fleet-wide reports, grouped by day, filtered All / Alerts / Summaries, with a search; click one to read
    it in full. A session that starts waiting for you adds an alert.
  - **Autonomy** only points to *Autonomy* at the top of the board (see above): the settings moved there in 1.39.0 and apply
    to both.
- A session can route its questions to the pilot with the « route questions » hook (`/autopilot-on`, `/autopilot-off`,
  `/autopilot-status`, per session; off by default, and then the question comes to you as usual). The pilot pastes the question as
  it is; your answer goes back with a decision id, and the pilot reports the session's next step. Approvals and permission
  prompts are never routed: only you can give them. A new question counts as news: it shows in the badge even on a quiet
  check-in. A pilot paired before this needs the instructions of the pairing message once: re-pair it, or send it those.
- **The assistant can read the fleet's questions.** Ask « what are the agents waiting for? »: it lists the waiting questions
  (options, level, cost of a wrong choice), the latest decisions and who took them, recent alerts, and any agent's
  conversation. It reads only; it answers (with the decision id) just what is yours to leave to it, never levels L4/L5 (Values and business, Critical),
  pushes, deploys, deletions, e-mails, credentials or approvals.
- **Status file**: retired in 1.42.0. Talixio no longer writes `pilot-status.md` and removes the one an older version
  left in its data folder. The board shows the same state live.
- **Fleet reports**: how the pilot's reports reach you (see *Channels and notifications*): an in-app badge by default, the
  Journal and the conversations, and optionally push + e-mail.

### Passes: what the orchestrator did, pass after pass
In **Journal**, the **Passes** filter lists every time the orchestrator checked in — also the quiet ones, folded into one line
(*"12 quiet passes, nothing new"*): agents seen, how many were waiting, reports and instructions exchanged, and anything it must fix. If
it stops checking in, the status line at the top says so (*"No pass for 40 min"*), so a silence is visible. In each agent's conversation,
what comes back is told apart: a **Question** or an **Approval** (marked), an **Answer** to a decision, an **Alert**, or a plain
status (smaller, quieter).

The orchestrator now makes **one call per pass** (agents, reports and acknowledgements in; instructions and points to fix out). Points to
fix are things like a waiting session sent without its question, or a question without its full text: the orchestrator is asked to
retrieve it from that session before the next pass. Re-connect the pilot (*Connect…*) to give your orchestrator the new instructions; the
older way keeps working.

## Roles
The catalogue of the roles your assistant can take, grouped into one card per specialization. Each role is a node whose ring shows
its acceptance rate and whose size follows how much it runs; **click one** to open its detail: metrics, trend, its
specialization and the actions *evaluate, publish, improve (A/B), archive*. Roles that look nearly identical are flagged.
- **+ New role** creates one (name, purpose, optional specialization, brief); a name already in use is refused, and the
  assistant checks for near-duplicates.
- A role can also be created by asking the assistant, or installed from the store.
- **Archived (n)** appears once a role is archived and lists them: **Reactivate** brings one back; **Delete** removes it
  only if it never ran (a role with history stays archived, with its history). The *assessor* role is protected: it has no
  lifecycle actions.

## Worksites
The director's directives: open worksites, their progress and steering. They are launched from the chat box, from the chat.

On a phone, the tabs scroll sideways.
