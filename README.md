# Talixio

Self-hosted, always-on AI assistant written in PHP. Pluggable brain (**Claude** or **OpenAI**,
including any OpenAI-compatible local server such as Ollama), persistent memory (SQLite), tool use,
Telegram chat and proactive scheduled messages. Installs as a `.deb` and runs as a systemd service
(auto-restart, starts at boot).

## Install (Debian/Ubuntu)
```bash
./build-deb.sh                          # -> talixio_0.1.0_all.deb
sudo apt install ./talixio_0.1.0_all.deb   # pulls php-cli, php-curl, php-sqlite3, php-mbstring
sudoedit /etc/talixio/talixio.env       # API key, Telegram token (@BotFather), allowed chat ids
sudo systemctl restart talixio
journalctl -u talixio -f                # logs
```
**Set `TALIXIO_TELEGRAM_ALLOWED_IDS`**, otherwise anyone who finds the bot can spend your API credits.
Requires PHP >= 8.2.

## Dev
```bash
php tests/run.php
ANTHROPIC_API_KEY=... TALIXIO_DB_PATH=/tmp/t.db bin/talixio chat
```

## Layout
- `src/AnthropicProvider.php`, `OpenAIProvider.php` – tool-use loops behind `Provider`
- `src/Agent.php` – per-chat history; long-term facts injected into the system prompt
- `src/Tools.php` – `remember`, `forget`, `now`, `fetch_url`; add more with `register()`
- `src/Telegram.php` – long polling (no public URL) + daily UTC scheduler
- `packaging/` – systemd unit, default config, maintainer scripts

## Ideas
More channels (Discord, WhatsApp, email), voice, shell/file tools behind confirmation,
semantic memory, an apt repository for `apt install talixio` without a local file.
