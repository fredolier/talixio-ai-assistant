# What a personal AI assistant is in October 2026

This is the target definition for Talixio. It is based on the products people compare against today:
Hermes Agent (Nous Research, released Feb 2026), OpenClaw, Meta Muse (launched Sept 2026), and the
hosted ChatGPT and Claude apps. Feature claims about those products come from their sites and
third-party guides, not from testing them.

## One-sentence definition

A personal AI assistant in 2026 is a long-running agent that you reach from the apps you already use.
It remembers you and gets better at your recurring tasks. It acts on the web and in your accounts
with tools, works on its own schedule, and asks before doing anything that is hard to undo.

A chatbot answers. An assistant acts, remembers, and checks in on its own.

## The ten capabilities

### 1. Always on, reachable everywhere
- Runs as a daemon on hardware you control (laptop, $5 VPS, home server).
- One "gateway" that connects many channels to the same brain and memory: Telegram, WhatsApp,
  Discord, Slack, Signal, email, web chat, terminal. OpenClaw and Hermes list 16 to 20+ channels.
- The same conversation and memory follow you across channels.

### 2. Model-agnostic, with routing
- Any provider: Anthropic, OpenAI, OpenRouter, local models (Ollama, vLLM).
- A cheap model for routine checks and a strong model for real work, chosen per task.
- Cost tracking and a monthly budget. OpenClaw's guides report heartbeats alone costing
  $30-100/month on flagship models, so this is a real concern.

### 3. Layered memory you can read and edit
- **Profile**: who you are, preferences, people, places. Plain Markdown files you can open and edit
  (OpenClaw and Hermes both store memory as files on disk).
- **Episodic**: past conversations, summarised and searchable, so it can answer "what did we decide
  about X last month".
- **Working**: the current task and its plan.
- You can see, correct, and delete anything it remembers.

### 4. Learning through skills
- The model does not retrain. It "learns" by writing **skills**: after finishing a non-trivial task,
  it saves the procedure as a Markdown file and reuses it next time. This is Hermes Agent's central
  idea and OpenClaw's extension model.
- Skills load on demand when relevant, so the prompt stays small.
- You can install, write, edit, or delete skills.

### 5. Tools to act
- **Web search** and **page reading**.
- **Headless browser** (Chromium) to navigate, click, fill forms, and read pages that need JavaScript.
- **Code and shell** in a sandbox (container or restricted user), for files, data, and scripts.
- **MCP client**: connect to standard MCP servers for email, calendar, drive, GitHub, home
  automation and more, instead of writing every integration by hand.
- Files and images in and out.

### 6. Proactive: schedules and heartbeat
- **Reminders and recurring jobs created by chat**: "remind me Friday at 9", "every Monday, summarise my week".
- **Heartbeat**: a periodic check (OpenClaw defaults to every 30 minutes) against a checklist file.
  It stays silent unless something needs you.
- Long tasks run in the background and report back when done.

### 7. Planning and delegation
- Breaks a goal into steps, keeps the plan in working memory, and updates it as it goes.
- Can spawn sub-agents for parallel or isolated work (Hermes lists this).
- Tasks survive restarts.

### 8. Safety by default
- **Approval before sensitive actions**: sending, buying, deleting, posting. Meta Muse asks before
  sending email or purchasing, and this is now the expected baseline.
- Tool policy per action: allowed, ask first, or forbidden.
- Sandboxed execution, a secrets store, allowlisted users, an audit log of every action, and
  protection against the agent reaching internal network addresses.
- Treat web pages, emails and documents as untrusted data (prompt injection is the main risk for an
  agent with a browser and your accounts).

### 9. Multimodal
- Voice notes in (transcription) and optionally voice out.
- Images and documents in (photos, PDFs, screenshots).

### 10. Observable and controllable
- Logs, costs, scheduled jobs, memory and skills visible in one place (a small web admin page).
- Easy to pause, stop, or roll back.

## Where Talixio is today (v0.1)

| # | Capability | Status |
|---|---|---|
| 1 | Always on, channels | Daemon via systemd. Telegram only. |
| 2 | Models | Anthropic, OpenAI, OpenAI-compatible. No routing, no cost tracking. |
| 3 | Memory | Flat facts list + last 20 turns. No files, no search, no summaries. |
| 4 | Skills | None. |
| 5 | Tools | `fetch_url` only (raw HTML, no internal-address protection). No search, browser, shell, MCP. |
| 6 | Proactive | Fixed daily jobs from config. No chat-created reminders, no heartbeat. |
| 7 | Planning | Up to 8 chained tool calls. Nothing persistent. |
| 8 | Safety | Telegram allowlist. No approvals, no audit log, no sandbox. |
| 9 | Multimodal | Text only. |
| 10 | Observability | journald logs only. |

## Build plan (PHP, shipped as the `talixio-ai-assistant` .deb)

Each milestone is usable on its own.

1. **Core runtime.** A workspace directory of Markdown files (`SOUL.md` persona, `USER.md` profile,
   `MEMORY.md`, `HEARTBEAT.md`, `skills/`). Job scheduler stored in SQLite with reminders and cron
   jobs created by chat. Heartbeat. Approval flow for sensitive tools (Telegram inline Yes/No buttons).
   Audit log and cost tracking.
2. **Web.** Search (SearxNG self-hosted, or Brave / Tavily with a key). Safe fetch that blocks
   internal addresses and returns readable text. Headless Chromium through `chrome-php/chrome`
   (drives Chromium over the DevTools protocol from PHP; the .deb recommends `chromium`).
3. **Learning.** Conversation summaries with SQLite FTS5 search. Skill writing and on-demand
   skill loading.
4. **Action.** Sandboxed shell (dedicated user + bubblewrap, or Docker if present). MCP client
   (stdio and HTTP). Background tasks and sub-agents.
5. **Reach.** More channels (Discord, Slack, email, WhatsApp via a bridge). Voice-note
   transcription. Images and PDFs.
6. **Admin.** A small local web page for memory, skills, jobs, approvals, logs and costs.

## Sources
- [Hermes Agent official site](https://hermes-agent.nousresearch.com/)
- [What Is Hermes Agent? (TrueFoundry)](https://www.truefoundry.com/blog/what-is-hermes-agent)
- [OpenClaw official site](https://openclaw.ai/) and [docs](https://docs.openclaw.ai/start/openclaw)
- [OpenClaw deploy cost guide](https://yu-wenhao.com/en/blog/2026-02-01-openclaw-deploy-cost-guide/)
- [14 Personal AI Agents in 2026 (DEV Community)](https://dev.to/amananandrai/14-personal-ai-agents-in-2026-a-technical-guide-to-architecture-memory-tools-autonomy-10h)
- [Meta Launches Muse AI Agent (Tech Insider)](https://tech-insider.org/?p=21283)
- [Muse AI Guide (aiera.blog)](https://aiera.blog/?p=1217)
