# Optional install modules

Add each one by running `sudo ./install.sh` again with the option (nothing is lost):

| Option | What it adds |
|---|---|
| `--with-ollama` | Local search by meaning (qwen3-embedding:0.6b model; for something lighter, see *Using Talixio / Search by meaning*): free, private, good in French |
| `--with-whisper` | Transcription on the server (whisper.cpp, large-v3-turbo model; `WHISPER_MODEL=small` for something lighter) |
| `--with-office` | LibreOffice: accurate pages for Word/Excel/PowerPoint files, old formats can be read |
| `--with-browser` | Headless browser: pages with JavaScript, clicks, screenshots |
| `--with-shell` | Linux sandbox with Python for calculations, analyses and charts |
| `--with-node` | Node.js, for MCP connectors started with `npx` |
| `--with-signal` | signal-cli, for the Signal channel |
| `--with-claude-code` | Claude Code, to use your Claude Pro/Max subscription |

Under Docker (several people), the image already includes all of this; the whisper model is shared
by all assistants.
