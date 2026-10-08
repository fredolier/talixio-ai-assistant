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
- `sudo ./install.sh --domain assistant.example.com` installs Caddy **on this VM** for HTTPS. Point the
  DNS at the VM and open ports 80 and 443 first.
- `sudo ./install.sh --bind 10.0.0.5:8080` when your reverse proxy runs **on another machine**: the
  app listens on the VM's private address, and you add to your Caddyfile:
  ```
  assistant.example.com {
      reverse_proxy 10.0.0.5:8080
  }
  ```
  Allow port 8080 only from the proxy (`ufw allow from <proxy-ip> to any port 8080`).
- HTTPS (either way) is needed to add the web app to your phone's home screen.
- `sudo ./install.sh --with-claude-code` installs the Claude Code CLI for the `talixio` user.

**Update:** `git pull && sudo ./install.sh` (your config and data are kept).

### Open the web app
- With `--domain` or your own proxy: `https://assistant.example.com`
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

### Security
The web app is password protected (`TALIXIO_WEB_PASSWORD`, generated at install; sessions last 30
days; wrong passwords are slowed down). Chat channels only answer allowlisted senders.
If your reverse proxy is on another machine, the hop between it and the VM is plain HTTP: bind to
the VM's private IP (or `0.0.0.0:8080`) and firewall port 8080 to the proxy only.

### Push notifications from the web app
Open the app over HTTPS, go to **Channels** and press **Turn on for this device** (on iPhone/iPad:
first Share → Add to Home Screen, then open it from there). Reminders, briefings and job results
then arrive as notifications, like a native app. Works per device; no extra service needed.
**ntfy** is an alternative: set `TALIXIO_NTFY_URL=https://ntfy.sh/<long-random-topic>`.

### Email
Give the assistant its own mailbox (e.g. `assistant@yourdomain`), then set in the config:
```
TALIXIO_SMTP_HOST=smtp.yourprovider.com    # Gmail: smtp.gmail.com with an app password
TALIXIO_SMTP_PORT=587
TALIXIO_SMTP_USER=assistant@yourdomain
TALIXIO_SMTP_PASSWORD=...
TALIXIO_EMAIL_FROM=Talixio <assistant@yourdomain>
TALIXIO_EMAIL_TO=you@yourdomain            # notifications go here
TALIXIO_IMAP_HOST=imap.yourprovider.com    # optional: to chat by email
```
- **Notifications** need only SMTP and `TALIXIO_EMAIL_TO`.
- **Chat by email**: with IMAP set, it checks the mailbox every minute and answers emails from
  `TALIXIO_EMAIL_ALLOWED` (default: your address) in the same thread as the web app. It only trusts
  mail your provider verified (SPF/DKIM/DMARC in `Authentication-Results`), so forged senders are
  ignored; set `TALIXIO_EMAIL_REQUIRE_AUTH=0` only if your provider doesn't add that header.
- Microsoft 365 mostly disables password login for SMTP/IMAP; it would need OAuth (not supported yet).
- `sudo talixio doctor` tests the SMTP and IMAP logins.

### Signal
Needs a phone number dedicated to the assistant (a cheap prepaid SIM or eSIM that can receive one SMS).
```bash
sudo ./install.sh --with-signal                  # installs signal-cli (native build, x86_64)
sudo nano /etc/talixio/talixio.env               # TALIXIO_SIGNAL_NUMBER=+33..., TALIXIO_SIGNAL_ALLOWED=<your number>
sudo talixio signal register                     # if asked for a captcha: open https://signalcaptchas.org/registration/generate.html,
                                                 # solve it, copy the signalcaptcha:// link, then:
sudo talixio signal register --captcha 'signalcaptcha://...'
sudo talixio signal verify 123456                # the code received by SMS
sudo talixio signal updateProfile --given-name Talixio
sudo systemctl restart talixio-worker
```
Then message the assistant's number from your Signal app.

### Telegram (optional)
Create a bot with @BotFather, set `TALIXIO_TELEGRAM_TOKEN`, restart, and message the bot. It replies
with your chat id; put it in `TALIXIO_TELEGRAM_ALLOWED_IDS`. Telegram and the web app share the same
conversation.

## What it can do (v0.4)
- **One conversation everywhere**: web app, email, Signal, Telegram and terminal (`sudo talixio chat`)
  share one thread. Messages it starts go to push notifications, email, Signal, Telegram or ntfy
  (`TALIXIO_NOTIFY`); see and test them on the **Channels** page.
- **Memory you can edit**: `USER.md` (about you), `MEMORY.md` (notes), `SOUL.md` (personality),
  `HEARTBEAT.md` (checklist), editable on the Memory page.
- **Reminders and recurring tasks by chat**: "remind me tomorrow at 9", "every weekday at 8:00 send
  me a briefing". Visible and pausable on the Tasks page.
- **Heartbeat** (off by default): every N minutes it reads `HEARTBEAT.md` and only messages you if
  something needs attention. Each check is a model call, so it costs tokens.
- **Web search** (Claude's built-in search, or Brave / Tavily / SearXNG) and **page reading** that
  blocks internal network addresses.
- **Searchable history (RAG in SQLite)**: every message is indexed with FTS5 keyword search; with an
  embeddings backend (Ollama, OpenAI or Voyage) meaning-based search is added. Finished stretches of
  conversation are summarised. Relevant past snippets are added to the prompt automatically, and the
  assistant can call `search_memory`. You can search too, on the Memory page.
- **Model tiers**: fast (heartbeat, summaries, simple lookups), standard (chat), deep (hard reasoning,
  with extended thinking on Claude). Start a message with `/deep` or `/fast` to choose. Each reply
  shows the model, tokens and estimated cost.
- **Budget**: daily and monthly limits in USD. Over 80% it switches to the fast tier and stops
  starting sub-agents; at 100% it stops calling models. The assistant sees its spending and has a
  `usage_report` tool.
- **Sub-agents**: for research and multi-step work the assistant starts background jobs (`delegate`),
  up to 3 in parallel, each with its own tier and a cost cap ($0.50 by default). It replies right
  away; the report arrives in the chat and as a push notification. Sub-agents can search and read
  but cannot change memory, schedules or start more jobs. See and cancel them on the Jobs page.
- **Prompt caching** on Claude: the stable part of the instructions is cached (re-reads cost 10%).
- **Activity page**: spending against your budget, cost by tier and by source, tokens per day, and
  an audit log of every tool call.

Costs are estimates from list prices built into `src/Pricing.php` (Claude: Haiku $1/$5, Sonnet
$3/$15, Opus $5/$25 per million tokens). Check your provider's pricing and override with
`TALIXIO_PRICES` if they differ.

Not yet: headless browser, skills (learning procedures), shell/code tools, managing your own
mailbox, WhatsApp, Slack, Teams, voice. See the roadmap.

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
