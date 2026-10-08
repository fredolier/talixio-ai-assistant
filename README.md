# Talixio AI Assistant

A personal AI assistant that runs 24/7 on your own server. You chat with it from a web app (desktop
or phone) or Telegram. It remembers you, searches the web, and can message you first: reminders,
recurring briefings, and periodic checks. The brain is Claude (API key **or your Claude Pro/Max
subscription** through Claude Code), OpenAI, or a local model.

See [docs/what-an-assistant-is-2026.md](docs/what-an-assistant-is-2026.md) for the target definition
and roadmap.

## Install on a VM (Debian 12+ / Ubuntu 24.04+)

```bash
git clone https://github.com/fredolier/talixio-ai-assistant.git
cd talixio-ai-assistant
sudo ./install.sh
```

It installs PHP, creates a `talixio` system user, copies the app to `/opt/talixio`, writes
`/etc/talixio/talixio.env` with a random web password, and starts two services:
`talixio-worker` (Telegram, schedules, heartbeat) and `talixio-web` (the web app on
`127.0.0.1:8080`). It starts in **echo mode** (no model) so you can check the install first.

Options:
- `sudo ./install.sh --domain bot.example.com` adds HTTPS with Caddy. Point the DNS at the VM and
  open ports 80 and 443 first. HTTPS is needed to install the web app on your phone's home screen.
- `sudo ./install.sh --with-claude-code` installs the Claude Code CLI for the `talixio` user.

**Update:** `git pull && sudo ./install.sh` (your config and data are kept).

### Open the web app
- With `--domain`: `https://bot.example.com`
- Without: tunnel from your computer, `ssh -L 8080:127.0.0.1:8080 you@your-vm`, then open
  http://localhost:8080

### Choose the brain
Edit `/etc/talixio/talixio.env`, then `sudo systemctl restart talixio-worker talixio-web` and run
`sudo talixio doctor` (it checks the config and makes one test call).

| Option | Settings |
|---|---|
| Claude API | `TALIXIO_PROVIDER=anthropic`, `ANTHROPIC_API_KEY=...` |
| Claude Pro/Max subscription | `./install.sh --with-claude-code`, then `sudo -u talixio HOME=/var/lib/talixio /var/lib/talixio/.local/bin/claude setup-token`, put the token in `CLAUDE_CODE_OAUTH_TOKEN=` and set `TALIXIO_PROVIDER=claude-code` |
| OpenAI | `TALIXIO_PROVIDER=openai`, `OPENAI_API_KEY=...` |
| Local model (Ollama...) | `TALIXIO_PROVIDER=openai`, `OPENAI_BASE_URL=http://localhost:11434/v1`, `TALIXIO_MODEL=llama3.1` |

With the subscription option, usage counts against your plan's limits. Anthropic's policy on this
has changed several times in 2026; see
[Use the Claude Agent SDK with your Claude plan](https://support.claude.com/en/articles/15036540-use-the-claude-agent-sdk-with-your-claude-plan).

### Notifications on your phone
Install the **ntfy** app, subscribe to a long random topic, and set
`TALIXIO_NTFY_URL=https://ntfy.sh/<your-random-topic>`. Scheduled tasks and heartbeat messages
arrive as push notifications. The web app can also show browser notifications while it's open.

### Telegram (optional)
Create a bot with @BotFather, set `TALIXIO_TELEGRAM_TOKEN`, restart, and message the bot. It replies
with your chat id; put it in `TALIXIO_TELEGRAM_ALLOWED_IDS`. Telegram and the web app share the same
conversation.

## What it can do (v0.2)
- **One conversation everywhere**: web app, Telegram and terminal (`sudo talixio chat`) share one thread.
- **Memory you can edit**: `USER.md` (about you), `MEMORY.md` (notes), `SOUL.md` (personality),
  `HEARTBEAT.md` (checklist), editable on the Memory page.
- **Reminders and recurring tasks by chat**: "remind me tomorrow at 9", "every weekday at 8:00 send
  me a briefing". Visible and pausable on the Tasks page.
- **Heartbeat** (off by default): every N minutes it reads `HEARTBEAT.md` and only messages you if
  something needs attention. Each check is a model call, so it costs tokens.
- **Web search** (Claude's built-in search, or Brave / Tavily / SearXNG) and **page reading** that
  blocks internal network addresses.
- **Activity page**: token usage per day and an audit log of every tool call.

Not yet: headless browser, skills (learning procedures), shell/code tools, email, WhatsApp, Signal,
Slack, Teams, voice. See the roadmap.

## Files
```
install.sh                 install / update
config/talixio.env.example all settings, documented
deploy/                    systemd units, `talixio` CLI wrapper
src/                       PHP app (no dependencies beyond php-curl, php-sqlite3, php-mbstring)
web/                       web app (router + static files, no build step)
tests/run.php              php tests/run.php
```
Data lives in `/var/lib/talixio` (SQLite database, memory files in `workspace/`).

## Develop locally
```bash
php tests/run.php
TALIXIO_PROVIDER=echo TALIXIO_WEB_PASSWORD=dev php -S 127.0.0.1:8080 -t web/public web/router.php
TALIXIO_PROVIDER=anthropic ANTHROPIC_API_KEY=... php bin/talixio chat
```
