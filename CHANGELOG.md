# Changelog

The version number is in `VERSION`; the app shows it at the bottom of the menu, in Settings → Updates
and with `talixio version`. Each delivery raises it and adds a line here.

## 0.12.0 (2026-10-10)
- Local transcription with whisper.cpp (`sudo ./install.sh --with-whisper`, then Settings → Audio → Transcription →
  Local): voice notes and meetings are transcribed on your server, nothing is sent out. Slower, no speaker names.
- Gemini transcription no longer pays for thinking tokens.

## 0.11.2 (2026-10-10)
- Fix: in French, the page could freeze when switching spaces (the message field's hint was translated again and
  again). The interface never translates its own translations any more.

## 0.11.1 (2026-10-10)
- Fix: after an update, the installed app could show the new page with the previous version's scripts until
  "Reload" was pressed, so new buttons (Share) did nothing. A page now always gets the scripts of its own version.

## 0.11.0 (2026-10-10)
- Share library documents with people outside: a link to a file or a folder (with its subfolders), for a
  set time or without end, optional password, view only or download, a message for them. Library →
  Share / Shares: copy, email, extend, revoke, and who opened what. You are told the first time a link is
  opened. The assistant can make links too (`share_create`), always with your approval.

## 0.10.0 (2026-10-10)
- Interface in French (Settings → Assistant → Interface language; automatic = the browser's).
- Character and values learned by itself from the history, a character per space (Memory page).
- Jobs: cost cap and effort per job, warned at 80%, continue a job after its cap.
- Connectors: sign in to remote MCP servers with OAuth.
- Several people on one server: Docker stack with an admin page; the app can run under a prefix.
- Tasks: a board per space (codes, priorities, workload, dependencies, overall order), calendar view, reminders.
- Spotify and phone buttons; mail to handle, people, morning briefing.
- Library: knowledge base with folders, real previews, citations with pages, filing of the inbox.
- Spaces, sandboxed shell, Google Calendar, chat comforts, compaction with progressive forgetting,
  two-step sign-in, installable app (PWA), local embeddings with Ollama.

## 0.9 (2026-10-09)
- Full configuration from the web app, self-update from the git repository.

## 0.8 and before (2026-10-08)
- Skills and nightly backups (0.8), headless browser (0.7), approvals, calendar and mailbox (0.6), live answers,
  attachments, voice notes and meeting summaries (0.5), email, web push and Signal (0.4), memory search, model
  tiers, budgets and sub-agents (0.3), web app, scheduler and install.sh (0.2).
