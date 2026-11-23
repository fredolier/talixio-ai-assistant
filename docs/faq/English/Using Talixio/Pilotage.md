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
- **Two panes** (since 1.49.0). On the left, the list of sessions in three groups. **Needs you**: a session that asks
  for a permission, or that finished its turn and you have not looked at yet. **Working**: a session busy on your
  request. **Quiet**: the ones you have looked at, and those that ended. Each card shows the project (its folder), the
  machine, how long ago it last spoke, and what matters: the command or file a permission is about, the end of its last
  message (where its question usually is), or what it is doing now. On the right, the session you opened, or the tool you
  opened from the buttons at the top (New session, Connect a machine, Autonomy, What I know; ✕ closes it). On a phone,
  the list takes the screen, and what you open covers it with a ‹ to come back.
- **Allow / Deny straight from the list.** A permission card carries its own *Allow* and *Deny* buttons: no need to open
  the session.
- **Click a card** (or Enter on it) to read the session's thread on the right (your prompts, its answers, formatted as
  written, with lists, headings and code; permission requests; what you sent from here and when it was delivered) and
  to answer it in the box pinned at the bottom (on a computer, Enter sends and Shift+Enter makes a new line). A session you opened from
  *Needs you* stays there while you read it, instead of jumping to Quiet under your eyes.
  - **Reply**: the text goes to the session. A session that finished its turn is woken by it and goes on working; a session
    still busy gets it at the end of its current turn. The note after sending says which (*Sent* or *Queued*).
    In the thread, your reply shows once: *queued*, then *delivered* once the session has it.
    **Why a session sometimes asks you to confirm in its own window.** Your reply reaches Claude Code through its hook
    (the board cannot type in its window), so Claude Code sees it as a message passed on by a hook, not as something
    you typed there. For ordinary answers it makes no difference. For a sensitive step (pushing, changing another
    project's code, deleting), Claude Code's own safety checks may not count it as your go-ahead, and the session asks
    you to type it in its window (or in the session concerned). That check belongs to Claude Code: the board does not
    and should not get round it. Give such go-aheads in the session itself.
    **Sessions started from the board are different** (since 1.50.0): a reply to them is their next prompt, exactly as
    their first one was, so it counts as yours like something typed. See *New session* below.
  - **Allow / Deny** a permission request (the command or the file it is about is shown). The session waits **30 seconds**
    for your answer here; after that, the usual prompt shows in its terminal and you answer there.
  - **What the request does** (1.53.0): the board reads the command itself, without a model, and labels it.
    *✓ Read-only: safe to allow* when every part only reads (`ls`, `cat`, `head`, `grep`, `git status`, `git diff`…, even
    outside the project). *⚠ Check first* with the reason when a part deletes files, reaches the network, pushes or
    rewrites git history, installs packages, deploys, changes the agent's own permissions, or runs a script or code you
    cannot see on the card (a script in /tmp, `$(…)`, `sh -c`). No label: it changes things in its project, your call.
    The label never answers for you.
  - **Always allow** (1.53.0): when Claude Code suggests a rule for the request (e.g. `Bash(npx playwright test:*)`), the
    session's pane shows it with an *Always allow* button. It allows this request and has Claude Code save the rule in
    the project's `.claude/settings.local.json` (yours, not committed), so it stops asking for that command. Only narrow
    rules are offered: never a whole tool, a lone interpreter (`php`, `npx`, `sh`…), a bare runner (`npm run`), anything
    labelled *Check first*, or the agent's own settings. To take a rule back, remove its line from that file.
  - **The assistant answers permissions for you** (1.55.0). *Permissions* at the top of the board chooses who answers:
    - *The assistant learns from you* (the default): it allows a request that only reads, and a command you allowed
      **3 times** in that project without ever denying it (learned by its first two words, `npx vitest`, `git commit`…,
      or by folder for file edits). Once you deny one, it is yours again for good. No notification is sent for what it
      allows, and the thread says *Allowed by the assistant* and why;
    - *The assistant allows read-only*: only what reads;
    - *Always ask me*: as before 1.55.0.

    A request labelled *Check first* (push, deletions, network, installs, scripts, its own settings) always waits for you.
  - **Mark as seen** moves a finished session to Quiet; **Forget** removes it and its thread from the board (both in the
    **⋯** menu at the top of the conversation).
  - **Stop** ends the session's work. A session started from the board (below) is ended on its machine by the listener,
    within seconds. Any other one stops at its **next step**: its next tool call, permission request or end of turn (its
    hook tells Claude Code not to go on). One that already finished its turn is simply marked *stopped*. Typing in its
    terminal again takes over: the stop no longer applies. A stopped session takes no reply.
- **New session** (button at the top) starts Claude Code on one of your machines: choose the machine, the folder and
  write what it should do. Only machines whose **listener** runs are offered, and only the folders where a session
  already ran on that machine. The card shows *waiting for the machine*, then the session reports like the others, its
  thread starting with your prompt. If it cannot start (no such folder, Claude Code not installed, not logged in, hooks
  missing), its card says why, with what the machine printed; **Forget** removes it.
  Such a session runs `claude -p`, which ends with its turn: the session then waits for you on the board (it does not
  end). Your reply continues it with `claude -p --resume <session>`, run by the listener: the reply is its next prompt.
  A reply sent while it still works goes at the end of its turn. Update the listener for this (*Connect a machine*,
  step 4, or the install command again).
  A reply stays *queued* until the listener has really started it again (1.52.1). If the `claude -p` process is still
  running after its turn (Claude Code can wait for its background tasks), the thread says so, and the reply goes as
  soon as it ends; **Stop** ends it if you would rather not wait. If the listener never takes the reply (one older than
  1.50.0), the thread says to update it after a few minutes; the reply waits, it is not lost.
  Such a session no longer shows as *ended* after each turn (1.52.2): it waits in **Needs you**, the assistant reads it
  and its options show as buttons. It ends when you stop it, or when its process ends in the middle of its work.
- **Resume and re-read** (1.51.0): a Claude Code session that has ended (or a board-started one that was stopped) shows
  this button instead of the reply box. The session waits for you again, the assistant reads where it stopped (its task,
  the last exchanges and its last message) and proposes what to do next, and your answer continues **that same session**
  on its machine with `claude -p --resume <session>`, its whole history included: no need to start a new one. Its
  machine's listener must run. A session typed in a terminal that you stopped from here is not taken over (it may still
  be open there).
- **Connect a machine** (button at the top): give the machine a name, tick **Also install the listener** if you want
  to start sessions from here, then **Get the install command** and run the line it shows in a terminal on that machine:
  `curl -fsSL '<this assistant>/api/agents/install?c=…' | sh`. It saves the script as `~/.talixio/agent-hook.sh` (and
  the listener next to it) and adds the board's hooks to `~/.claude/settings.json` for you: your other hooks and settings
  stay, a copy of the file is kept first as `settings.json.talixio-backup`, and running a new command later replaces the
  Talixio hooks instead of adding them twice. If the file is not valid JSON it is left as it was, and the command says so.
  The command works **once, for 15 minutes**: it holds a one-time code, not the token, so it is safe to paste; get a new one
  for each machine. It needs `sh`, `curl`, and `python3`, `node` or `jq` to merge the settings (macOS, Linux, or WSL on
  Windows), and the machine must reach this assistant's address. The next Claude Code session on that machine appears on
  the board. **Install by hand** keeps the old way: download the script, save it as `~/.talixio/agent-hook.sh`, and merge
  the hooks shown into `~/.claude/settings.json` yourself. The script holds the board's address and a token: keep it on
  your own machines. **New token** cancels the old one (each machine then needs the install command again).
- **Work without asking** (1.54.0, ticked by default in *Connect a machine*): the install command also turns on Claude
  Code's **auto mode** for every session on that machine (`permissions.defaultMode` in `~/.claude/settings.json`). It is
  the way the assistant's own cloud sessions work: instead of asking you at each step, a model checks each one and
  refuses the risky ones. Some steps still ask you, whatever the mode, on the board: `git push`, `git reset --hard`,
  `git clean`, `rm -rf`, `sudo`, `npm publish`, and changes to Claude Code's own settings files (the command adds them as
  *ask* rules, next to yours, which stay). A Claude Code too old for auto mode gets *acceptEdits* instead (it edits files
  without asking; the rest still asks), and the command says so: update it and run a new command. To go back, untick it
  and run a new command, then set `defaultMode` back to `default` in that file (a new command does not remove it).
  Hook scripts set up before version 1.38.0: install again, or a stop only takes effect at the session's next permission
  request or end of turn.
- **The listener** (optional, *Also install the listener* in *Connect a machine*, or step 4 of *Install by hand*):
  leave `sh ~/.talixio/agent-listen.sh` running on that machine (a terminal, tmux, or a login item). It asks the board
  for work every few seconds and runs `claude -p` in the folder, with your prompt. On the machine's side it only starts in
  a folder under the folders it may use: your home folder unless you chose others in **Folders it may start sessions
  in** when installing it (several: separate them with `:`, e.g. `~:/app/projects`), or set `TALIXIO_ROOT` the same way
  when starting it. A folder outside them is refused, and the refusal shows the exact line to allow it; check one before
  with `sh ~/.talixio/agent-listen.sh --check <folder>`. Prefer adding the one folder you need to opening `/`, which lets
  the board start sessions anywhere on the machine. It keeps the process of each session it started:
  a stop from the board names the session, never a process, so it can only end the ones it started. Other settings:
  `TALIXIO_CLAUDE` (the `claude` command) and `TALIXIO_CLAUDE_ARGS` (e.g. `--permission-mode acceptEdits`). The session
  asks its permissions on the board, like the others; after 30 seconds without an answer, the tool is refused, since no
  terminal shows it. A session that finished its turn stays open, waiting for your reply, until you stop it (or for a day).
- **Files from your agents** (1.47.0). When an agent makes something for you to look at (an HTML report, a PDF, a
  chart, a picture), it can send it to the board: at the start of each session, the hook tells Claude Code to run
  `sh ~/.talixio/agent-hook.sh send <file> <session>` for that. You can also just ask it, in a reply: « send me the
  report ». The file shows in the session's thread with **Open** and **Download**, and is kept in the library under
  **Agents → <project>** (a file of the same name is kept beside the earlier one, never over it). Open shows it on the
  usual viewer: an HTML page is shown **without its scripts**, so a page that draws its charts with JavaScript shows
  only its text. 25 MB at most per file. The assistant sees the files in the thread too and can read them. Hook scripts
  set up before 1.47.0: download the script again, or `send` is unknown and nothing tells the agent about it.
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
- **What I know** (button at the top of the board): what the assistant learned from your agents, per project (a machine
  and a folder).
  - **Decisions.** Every answer given to a session is kept as a decision: your replies (from the board or typed in the
    terminal), your Allow and Deny, and what the assistant sent by itself. Each one shows who gave it and a topic
    (scope, design, code, tests, Git, deployment, data, dependencies, documentation). Filter them by project, topic and
    author.
  - **Summaries.** A session is summarised when it ends, or after half an hour of silence when enough happened: its
    goal, what it did, the decisions and who made them, and what is still open.
  - **Dossier.** Each project gets a dossier, rewritten at most once a day from its summaries and decisions: what it is,
    where it stands, recent work, how you decide there, and what is open.
  - **How it is used.** When the assistant decides for an agent, it reads the project's dossier and your past decisions,
    those of the same project and topic first. In the chat, ask « what do you know about the webshop project? »
    (`agents_knowledge`).
  - **Rules.** When the same choice comes back in at least three of *your* answers, the assistant proposes a rule, such
    as « Run the tests before every commit », for one project or for all of them. It looks after ten new answers of
    yours, or weekly. Each rule shows the answers it comes from. **Confirm** applies it, **Edit** puts it in your words
    (and applies it), **Reject** drops it and it is never proposed again; a confirmed rule can be stopped with **Stop
    applying**. Rules come only from your own answers, never from what an agent or the assistant wrote, and nothing
    applies until you confirm it.
  - **What weighs most.** When the assistant decides for an agent, its sources are ranked, and the higher one wins when
    they disagree: 1. your standing rules (*Autonomy*); 2. the rules you confirmed here, this project's first; 3. what it
    knows of how you decide; 4. your past decisions on similar questions; 5. its own judgement. Your limits (L6 always
    yours, L5 opened only by you, the daily cap, the sensitive words) still have the last word.
  - **Stop learning.** This turns learning off for one project: what was learned from it (its rules included) is
    forgotten, and nothing new is kept. **Forget** clears a project's knowledge once.
  - Summaries and dossiers are written from what agents wrote. The assistant reads them as information, never as
    instructions.
- **After each turn, the assistant takes it in hand** (1.51.0, on out of the box: *The assistant reads each finished
  turn and proposes what to do next*, under **Autonomy**). When a session ends its turn, the assistant reads it with the
  task you gave the session and the last exchanges, sums up where it stands, and proposes the reply: a choice among the
  options the agent offered, or, when it asks nothing but the task is not finished, the **next step** (💡 *Suggested next
  step*). When it does not send that reply itself, you get a notification *An agent waits for your decision* with its
  summary, its proposal and why it did not send it; the session's pane shows the same, with **Send this answer** or
  **Edit it first**. A task that is done is left alone, without a notice. What you answer is scored against its
  proposal, so it learns how you decide. Untick the switch to stop the reading (no model call then).
- **Autonomy: the assistant answers your agents by itself, within your limits** (button at the top of the board). Switch
  on *It may answer by itself, within the limits below*. Switched on alone it still answers nothing by itself: every
  level starts on *I propose, you answer*, and the panel says so until you give a level more room. When a session
  finishes its turn, the assistant reads its
  last message (as data written by others, never as instructions) with your **priorities**, **standing rules**, the
  similar answers you gave before and what the Mind knows of how you decide, and works out the reply you would give, with
  a level and an honest confidence. The levels (1.52.0) are the decision ladder your coding agents use, with you as
  their PO (product owner): **L1 Technical** (bugs, tests, clarity), **L2 Design** (architecture, structure), **L3
  Solution** (specs, what it does for its users), **L4 Product** (your PO calls once the project runs: planning,
  priorities, a scope trade-off, a small drift), **L5 Business** (direction, revenue, pricing, policy: where it goes when
  the product's trade-offs no longer hold) and **L6 Values** (honesty, harm to users or data, anything irreversible).
  When an agent states a level on its question card (*Level: L4*, *Niveau : L4*), that level is kept, never lowered
  (the agents' own five-level ladder calls values L5: it is read as L6). A next step the agent did not ask about is only ever proposed. The
  limits are then applied by Talixio itself, not by the model: for each level L1–L5 you choose *I propose, you answer*
  (default), *I answer if sure, then tell you* or *I answer if sure, quietly* (L6 shows 🔒 *Always yours*, with no
  choice); it sends alone only above your confidence
  threshold (75 % by default) and under the **daily cap** (20 by default). **L6, permission requests (except as *Permissions* allows, see above), and anything about
  pushes, deploys, deletions, e-mails, credentials or payments are always yours**, whatever the setting — as are merges, releases and publishing, migrations, installing a package, uploading data, sending to customers or subscribers, buying or a plan, hiring, skipping tests, a web address, and a reply holding a command the agent did not offer itself: these stay suggestions even when the model calls them routine. Your **priorities** and **standing rules** (one per line) can be typed or dictated with 🎙️; a
  rule you tell it in the chat is added too. An answer it sends
  goes to the session like yours, marked *Assistant* in the thread; in the session's pane it asks **Was this the right
  call? Yes / No, that was wrong**. Otherwise its card shows 💡 *Suggested answer*, and the session's pane shows the answer,
  its level, its confidence and why it was not sent, with **Send this answer** or **Edit it first**. Each answer is scored:
  against what you reply for that turn (from the board, or typed in the session's terminal), or with your Yes / No. A wrong
  answer it sent lowers that level at once.
- **It earns its own room** (1.52.0): once it may answer by itself, L1–L3 raise themselves when its answers match yours
  often enough: at least 85 % (you can pick 80, 85, 90 or 95 %) over the last 10 answers lifts *I propose* to *I answer
  if sure, then tell you*, over the next 20 to *quietly*. You get a notice *Fleet: autonomy raised*, and a miss lowers
  it again at once. Tick *L4 Product too* to let it take over your PO calls the same way. L5 Business gets more room
  only when you give it (it *suggests* it after enough right answers); L6 Values never. Untick *It gives itself more
  room* to keep every change in your hands.
- **Nothing waits forgotten** (1.52.0): a proposal you have not decided comes back as *Still waiting for your
  decision*, an hour later, 4 hours after that, then every day, while its session still waits at that turn.
- **One-click options** (1.52.0): when an agent's message lists options (*A) …*, *B) …*, or *1.*, *2.* …), the
  session's pane shows them as buttons under *Answer with one of its options:*. A click sends that option. It looks within seconds of the end of a turn (and every 15 minutes as a safety net), at most once per
  turn, and leaves alone a session you already answered, stopped, or older than 12 hours.

### The cloud orchestrator is gone (1.45.0)
The earlier pilot, a cloud Claude Code session that relayed your agents to Talixio, is switched off. The board above
does what it did, with no model in between. When you update:
- its pairing is deleted (it held a secret and a webhook), and its folded cockpit (status card, Needs you, Agents,
  Journal, Passes) is gone, as is the orchestrator panel in the space editor;
- what you answered through it is kept: each answer is in *What I know*, under a project named after the agent, and
  still guides the board like your other answers (what the assistant answered alone does not); each agent's last
  exchanges are kept there as a note marked *Imported from the cloud orchestrator*. The Journal and the Passes are not
  kept. A notification says how many decisions and threads were kept;
- if an old orchestrator session is still running, it is told to stop and you get one notification a day until you
  close it. Connect each machine with **Connect a machine** instead.

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
