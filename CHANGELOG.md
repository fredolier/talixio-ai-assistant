# Changelog

The version number is in `VERSION`; the app shows it at the bottom of the menu, in Settings → Updates
and with `talixio version`. Each delivery raises it and adds a line here.

## 0.28.71 (2026-10-21)

- Apps can be shared. **Share** on an app copies it to another of your assistants, or downloads it as a `.talixio-app.json` file that anyone adds with **Add an app from a file**. The copy carries the code, what the app reads and how to install the connectors it uses (GitHub repository or online address, with header names but never their values), never its data, saved state or keys. Library files are found again by their place. A copied app waits for approval, and its page lists what is missing: connectors to install with one button, tools to let run without asking. Apps of a Secret space stay where they are.

## 0.28.70 (2026-10-21)

- Settings → Connectors: each connector is a card you unfold, with its own description (from the connector, or `description` in `mcp.json`). Inside, a table lists every tool with what it does, grouped by what it seems to do (only reads, not sure, can change or send things), with a filter for long lists and one box per group to let a whole group run without asking. The groups are only a guess shown to help; only your ticks skip the approval.

## 0.28.69 (2026-10-20)

- Tests: browser tests for the web app (`node tests/browser/run.mjs`, Playwright and Chromium). They sign in, open every view, and go through the main flows: chat and its search, quick capture of a task and a note, spaces, library folders, notes, search, sharing, the board in all its views with quick add and card editing, contacts, settings, notifications, the French interface and a phone-sized screen. Any error in the page fails the run; the run also reports how much of `app.js` it used (about two thirds).

## 0.28.68 (2026-10-20)

- Code quality: the web API router is split by area (system, chat, work, connectors, mind, library, board, apps) instead of one 250-line match, and the assistant's tools are registered by group instead of in one 460-line constructor. No change in behaviour; the tools keep their order.

## 0.28.67 (2026-10-20)
- Review fixes. Apps: an app of a Confidential space, or one reading a Confidential file or folder, never reaches the web or a connector; the approval card names every kind of source (folders, searches, connector tools, calendar, contacts); a folder source with `_` or `%` in its name lists its subfolders; script arguments may not start with `-`; cached answers older than a day are cleared. Moving a space: a link to a file that did not come along points nowhere (it no longer opens another file with the same number), and a file that would land in another space's folder goes to the moved space's "Linked files" folder instead. Library: changing a file keeps its creation date; Recent changes filters before counting, so a burst of your own changes no longer hides others'; the digest is sent once an hour (or day), never twice or skipped.

## 0.28.66 (2026-10-20)
- Recent changes: an *Acknowledge* button marks one file's change as seen; the folder button is now *Stop notifying* / *Notify again* (clearer than "Mute" in French).

## 0.28.65 (2026-10-20)
- Library: **Recent changes**. Each file keeps when it was last created, changed, moved, renamed or restored, and by whom (you, the assistant, a routine, the network drive). A *Recent changes* view (last hour, today, 2 days, 7 days) marks the files others changed that you have not opened since; a grouped in-app notification (at most hourly, daily or off; folders can be muted) never counts your own changes; the morning briefing gets a "since yesterday" line; new `kb_recent` tool; an app's folder source gives `changed`. Secret files stay in their space.

## 0.28.64 (2026-10-20)

- Apps: links now work (library files, Talixio pages; a web address asks first), a new list component, and a
  cleaner dashboard look (figure cards, section titles, links, empty states).

## 0.28.63 (2026-10-20)

- Apps: new sources (library folder and search, calendar, contacts, connector tools marked as only reading), looser
  source parameters, new visual components (pie, gauge, ranked bars, progress, heatmap, timeline, badges, tabs,
  trend lines, stacked bars) and downloads (CSV, PNG). New `docs/ROADMAP.md`.

## 0.28.62 (2026-10-19)

- Apps: making or changing an app takes the deep tier; an app whose code breaks now shows the error instead of a blank page, and a DOMContentLoaded listener in its code still runs.

## 0.28.61 (2026-10-19)
- Apps: an answer saying an app was created when no app_save succeeded goes once to a stronger model, which saves it for real or gives the error; app_save errors now say plainly that nothing was saved, a missing script source is refused up front, sources sent as JSON text and script names ending in .py are accepted; the example descriptions are translated.

## 0.28.60 (2026-10-19)
- Apps menu: small apps (charts, tables, trackers) the assistant builds from a vetted kit and runs in a sealed frame with no network; each reads only the sources it declares (a fixed web address, a library file, the space's tasks, a saved script) and gets no data until you approve it. Two examples (Stock prices, Tasks at a glance); ask “With what you know about me, could you suggest several apps…?” for ideas. FAQ: Apps.

## 0.28.59 (2026-10-19)
- Optional knowledge graph of the library (Settings → Web search and memory, off by default): the people, companies, projects and places each document mentions are noted in the background by the fast model (capped per day, never for a folder the model may not read), and the new `kb_related` tool gathers everything about a name or what is linked to a file.

## 0.28.58 (2026-10-19)

- Connectors: a long result (over 12,000 characters, e.g. a converted HTML page) is saved whole as a library file and
  the assistant moves it where you want, instead of retyping it and cutting it. Approved actions now show their raw
  result folded in the chat ("show the result"). Jobs done without trouble are archived after 2 days (failed or
  tagged ones after 30).

## 0.28.57 (2026-10-18)

- Trusted websites: sites in Settings → Browser and approvals open without asking (subdomains included), also from
  Confidential spaces, routines and after outside content; an "Always allow" button on approvals adds the site (or
  allows web search from Confidential spaces). Secret spaces stay offline. Approvals now send one notice per batch
  instead of one email each.

## 0.28.56 (2026-10-18)

- Routing benchmark on outside cases: 2,100 documents and questions from three public datasets, labelled by their
  own PII annotations, with a locked test split, a cross-validated naive Bayes baseline and the router.

## 0.28.55 (2026-10-18)

- Routing benchmark: the rules now treat offer letters, pay rises and bonuses as confidential, and the router
  prints its first error instead of failing silently.

## 0.28.54 (2026-10-18)

- New `research/routing-bench`: a small benchmark that tests how requests would get their trust label, tier and
  split into steps, before that routing goes into the app. It has 32 synthetic cases and scores leaks (label too
  low), labels set too high, tier and split. It runs with pattern rules only, or with any OpenAI-compatible router model.

## 0.28.53 (2026-10-17)

- Installed app: on a server where a person's assistants share one address (`/alex-work/`, `/alex-private/`),
  switching to another assistant now stays in the app's window. Before, the app showed it as an outside page,
  with an address bar and a close button taking up space.

## 0.28.52 (2026-10-17)

- Server update: once the new version of the admin agent runs, it restarts every assistant whose settings that version
  writes differently. Before, the previous version restarted them, so a fix in what each assistant is given (like
  0.28.51's full list of linked assistants) only showed after a later update.

## 0.28.51 (2026-10-17)

- Assistant switcher: an assistant now lists all of the person's assistants, also the ones linked only through
  another (Work and PIA each linked to Private: Work now shows PIA too). Update the server so they restart with it.

## 0.28.50 (2026-10-17)

- Microsoft sign-in: when the Microsoft app accepts only your organisation (Microsoft's error AADSTS50194),
  the message now says to put its Directory (tenant) ID in Settings → Calendar → Microsoft directory.

## 0.28.49 (2026-10-17)

- Microsoft sign-in (Outlook calendar, the assistant's email, your mailbox): when it fails, the reason now
  stays under the *Connect with Microsoft* button until a sign-in works. Before, it only showed for a few
  seconds after Microsoft sent you back, so the page looked as if nothing had happened.

## 0.28.48 (2026-10-17)

- Channels: a channel's last error shows its time and message on separate lines, so the message is
  translated; the test notification lists one line per channel. More French for the email channel's
  details and the "Set (…). Type to replace." placeholder.
- The assistant's name at the top of the menu was cut after its first word ("Frédéric" for
  "Frédéric OLIER - PIA"): a long name now wraps over two lines in a smaller size, and its full text shows on hover.

## 0.28.47 (2026-10-17)

- Admin page: a new **Name and email…** action renames an assistant. The name is the one shown in the
  assistant switcher (it comes from the admin page, not from the assistant's own Settings) and was only
  set at creation until now. The assistant and the ones linked to it are made again to carry the new name;
  changing only the email restarts nothing.
- One name per assistant: on a shared server, the name set on the admin page is also the assistant's own
  name (top left, emails, notifications, two-step sign-in label). Its *Name* in Settings shows it read-only,
  with where to change it.

## 0.28.46 (2026-10-17)

- Microsoft sign-in for email: choosing *Microsoft 365 account (OAuth)* now also sets the servers to Microsoft's
  (smtp.office365.com port 587 STARTTLS, outlook.office365.com port 993). Before, the old servers were kept,
  so the mail still went to the previous server. The account's token is now only ever sent to Microsoft's
  servers: with another server, sending stops with a message saying which server to change.

## 0.28.45 (2026-10-17)

- Outlook / Microsoft 365 email: the assistant's address and your mailbox can sign in with the Microsoft
  account (OAuth, XOAUTH2 for SMTP and IMAP) instead of a password, which Microsoft is turning off. Email
  settings → *Sign-in* → *Microsoft 365 account (OAuth)*, save, then *Connect with Microsoft*. Uses the
  app set up for the calendar, with the extra permissions *SMTP.Send* and *IMAP.AccessAsUser.All*; each
  mailbox has its own connection, separate from the calendar's.
- Assistant switcher: each assistant has a coloured badge with its initials (from its id), shown at the
  top of the menu. With the menu folded, the badge opens the switcher. The list shows the current
  assistant first, with each one's name and id.

## 0.28.44 (2026-10-17)

- Fix: "Sign in with Claude" hung after pasting the code, then said Claude did not answer in time. Claude Code
  takes a long burst of typed input as a paste, so the Enter sent with the code was pasted instead of pressed and
  the code was never submitted. The code is now typed first and Enter pressed on its own a moment later (and once
  more if nothing happens). A test fake Claude Code now behaves the same way.

## 0.28.43 (2026-10-16)

- **Move a space to another assistant**: space card → *Move…*, pick one of your linked assistants. The browser
  carries the whole space there (conversation and summaries, instructions, notes, settings, tasks with their
  dependencies, history and time, routines and jobs, what the assistant learned, the library folder and the
  documents it points to), then removes it here: the folder goes to the trash, calendar entries are withdrawn, chat
  apps go back to *General*. Name, task code or folder already taken there get a free one; renumbered tasks keep
  their old codes. The AI account and its keys never travel. Without a link (or to another server): *Download the
  package*, *Import a space…* from the space menu on the other side, then *Remove it here*. The transfer uses a
  one-time ticket valid 10 minutes, accepted only from a linked assistant's page; a sign-in ticket cannot be used for it.

## 0.28.42 (2026-10-15)

- **Task filters, reworked**: the endless row of tags is replaced by one filter bar shared by the Today, Board,
  List, Week and Month views: a text search (title, description, code, tags; accents ignored), a tag picker (type
  to choose among all tags, the five most used as chips, the chosen one shown with ✕), a priority floor, and on the
  Board a sort by priority, due date or title. A count shows how many tasks match, and *Clear filters* resets them.
  The List's own priority filter moved into this bar. Agenda events are hidden while a tag or priority is chosen.

## 0.28.41 (2026-10-15)

- **Real costs from the provider** (Anthropic, OpenAI): with an Admin key (Settings → AI model → Budget, or *Real
  costs* in an AI account), the assistant reads what the provider really billed each day, every 6 hours, optionally
  for one workspace or project. Billed days replace the estimates in the budget, today's estimate is corrected from
  the last 30 days, and no bill is asked for. Activity lists each source with what it billed this month and a *Sync
  now* button; a refused key says so and keeps the days already read. Usage now records which settings made it, so
  each source is compared with its own estimates. Admin keys stay on the server, are never sent back to the browser
  and are removed from Claude Code's environment. Mistral and Gemini keep the estimates and the bills you enter.

## 0.28.40 (2026-10-15)

- **One sign-in across a person's assistants** (several people on one server): on the admin page, link a person's
  assistants (work and private, say) with *Their other assistants (one sign-in)…*. The assistant's name in the app
  then lists the others and opens them in the same tab, already signed in. The browser carries a ticket signed for
  that assistant only, valid once for a minute; the assistants never call each other. Links work both ways, and
  removing one changes the keys so earlier tickets stop working.

## 0.28.39 (2026-10-15)

- **Spaces are walled off more strictly** (Confidential and Secret):
  - a model reads a level's files, notes and memory only if it may take that level, also from General and inside
    the space itself (your own searches still find everything);
  - nothing goes down a level: notes, moves and job reports from a stricter space stay in a folder at least as
    protected, and the shared memory, contacts and skills refuse it (the space's notes take it instead);
  - the assistant's learning (Mind) consolidates each Confidential or Secret space on its own, with that space's
    AI account, and keeps what it learns there;
  - routines and jobs of a space the model may not read are not listed and cannot be cancelled, continued or
    archived from elsewhere; a Secret space's folder and activity are not shown outside it;
  - the provider's own web search is off once the work is above Internal; the heartbeat starts each check fresh.

## 0.28.38 (2026-10-15)

- **Mistral batch API**: with a Mistral account (main settings or a space's AI account), summaries and the assistant's
  reflection go through Mistral's batch API at half the price; the worker collects the answers. *Compact now* and
  *Think it over now* still answer at once; a failed batch, or a budget running low, falls back to a direct call.
  Setting: *Mistral: background work at half price* (`TALIXIO_MISTRAL_BATCH`, on by default).
- Spaces are kept apart better: merging old summaries now runs in each summary's own space (its AI account and its
  privacy level), meeting summaries are filed in the meeting's space, and a job's report goes to its space's folder
  instead of the Inbox.

## 0.28.37 (2026-10-15)

- **AI accounts per space**: Settings → *AI model* → *AI accounts* holds named accounts (Claude API, Claude subscription
  with a `claude setup-token` token, OpenAI-compatible, Gemini, European provider). Each space, General included,
  picks one in its settings (General: in the space menu); the others keep the main settings. Two Claude
  subscriptions can serve different spaces, and several European providers can be used at once.
- The privacy checks follow the space's account: a space on a European account may hold confidential work even
  when the main model is a subscription. *Europe only*, when locked, still blocks non-European accounts.
- Keys and tokens stay on the server (the app only sees whether one is set); a field left empty keeps the saved one.
  *Test* tries an account before it is saved. Deleting an account puts its spaces back on the main settings.
  A change of account is noted in the space's history.

## 0.28.36 (2026-10-15)

- New **List** view on the Tasks page: a sortable table (code, title, space, priority, due, load, status) grouped
  by due date (Overdue, Today, Next 7 days, Later, No date), status, space or priority. Quick views (*This week*,
  *Critical & high*, *Waiting*, *No date*), status and priority filters, and a quick-add field. Change a status
  right in the row; keyboard: arrows or j/k to move, x or space to select, Enter to open.
- **Bulk actions**: tick several tasks to set their status, reschedule them, change their priority, move them to
  another space, add a tag or move them to the trash (with *Undo*). Esc clears the selection.
- The task editor opens as a **side panel** on wide screens and as a full-screen sheet on phones, with its title
  and Save button always in view.
- Board with *All spaces*: one **row per space**, each with its open count and hours left; click a space's name to
  fold it (remembered on this device). Dragging a card into another space's row moves it there.

## 0.28.35 (2026-10-15)

- New **Notifications** page: every notification the assistant sends (reminders, scheduled tasks, job reports,
  approvals, alerts) is kept in the app with its full text, newest first. A red number in the menu (on phones, on
  *More*) shows the unread ones; tap one to open its conversation and mark it read, or *Mark all read*, or *Clear*.
  The last 200 are kept, for 60 days; a Secret space's notifications only say where to look.
- The number on the app icon now counts unread notifications plus approvals waiting, and goes away once you
  have read them; opening the page also removes them from the lock screen.

## 0.28.34 (2026-10-15)

- Tasks: new **Week** view to plan time. An hour grid shows the week with your timed tasks and calendar events;
  beside it, "Not planned yet" lists the tasks due this week that have no time. Drag one onto the grid to book a
  slot (snapped to 30 minutes, sized from its estimate), drag a block to move it, or drop it back on the list to
  remove the time. Every change can be undone from the message that confirms it.
- Tasks: the Week view counts booked hours against "Hours a day" (7 by default) and says which days are full.
- Tasks: on a phone the Week view shows one day or three, with a strip of the week's booked hours. Tap a task,
  then tap a free slot to place it.
- Tasks: the Calendar tab is now called **Month**. On a phone the view tabs scroll instead of wrapping.

## 0.28.33 (2026-10-15)

- Tasks: new **Today** view, now the first tab. It shows overdue tasks (with one-click "Today" and "Tomorrow"),
  today's tasks with a checkbox to mark them done, and the next tasks from your overall order. On a computer an
  agenda beside it shows today's timed tasks and calendar events, with a line for the current time.
- Tasks: add a task in one line. "Call Alex tomorrow 9am #sales 30m p2" sets the date, time, space, estimate and
  priority; chips under the field show what was understood, and a click on a chip keeps that part as text.
  English and French words both work. The same field replaces "+ Add a task" at the bottom of each board column.
- Tasks: a **⋯** button on each task (always visible on phones), a long press or a right-click opens a menu to
  change its status, reschedule it (Today, Tomorrow, Next week, a date, no date), open, duplicate or trash it.
  On a phone the menu slides up from the bottom. Trashing shows a "Restore" button in the confirmation.
- Tasks: on touch screens, cards are no longer draggable, so scrolling the board no longer drags a card by mistake.

## 0.28.32 (2026-10-14)

- Linux drive script: with davfs2 1.7.0 and a recent kernel (WSL2 6.x among them), the drive mounted but every `ls`
  failed with "Invalid argument": davfs2 reads folders through a 16 KiB buffer and the kernel asks for 32 KiB.
  The script now sets `buf_size 64` for the mount point in `/etc/davfs2/davfs2.conf`.

## 0.28.31 (2026-10-14)

- Linux drive script: inside a Docker container, where the kernel has FUSE but `/dev/fuse` is not handed over,
  it now says so and gives the options to start the container with (`--device /dev/fuse --cap-add SYS_ADMIN`),
  or to mount the drive on the host and share it with `-v`. Before, it took the WSL path for Windows.

## 0.28.30 (2026-10-14)

- Linux drive script: on WSL without FUSE ("can't open fuse device"), `mount-webdav-linux.sh` no longer stops
  with advice. Windows connects the drive (`net.exe use`, kept at sign-in), and WSL mounts that letter with
  drvfs, plus an fstab line for `sudo mount /mnt/talixio`. A rootless FUSE client would not help, because it
  needs `/dev/fuse` too.

## 0.28.29 (2026-10-14)

- One name per folder, with versions: saving a file under a name already used in its folder (an upload in the library, the assistant saving a note again, the network drive, the shell) now replaces that file in place, with the same id, links and shares. What it held is kept as a version for 30 days, at most 20 per file.
- The open file has a *Versions* section: the date and size of each earlier content, *Download*, and *Restore* (the current content becomes a version in turn). The assistant can list and restore versions too (`kb_versions`).
- *Rename* and *Move* (also by drag and drop) onto a name already used there ask: *Replace* (that file gets this content; its old content becomes a version) or *Keep both* (named "name (2)"). The assistant's `kb_move` keeps both unless asked to replace. Chat attachments keep both.
- Files that already had the same name in a folder are merged once at startup: the newest is kept and the older ones become its versions. Their old links lead to the file that was kept.
- A file restored from the trash onto a name taken meanwhile gets a number instead of a duplicate name.

## 0.28.28 (2026-10-14)

- Notes in other formats: the assistant's `kb_save_note` keeps a `.html`, `.csv`, `.json`, `.xml` or `.txt` name and saves that format, instead of adding `.md` (`page.html.md`). An HTML newsletter now shows as HTML in the library and opens as a web page.
- Renaming a text file to another extension changes its type: a note renamed `page.html` stops being a note (and is no longer shown as Markdown source); renamed back to `.md`, it is a note again.
- Notes already renamed to `.html` (or another non-note extension) are repaired once at startup.

## 0.28.27 (2026-10-14)
Test coverage release: the test suite now runs 97% of the code's lines and 88% of its branches (was 81% / 69%).
- **New tests** in `tests/more/`, run by `tests/run.php` after the main suite: `content.php` (previews, privacy,
  files, Markdown, 123 checks), `fleet.php` (fleet, admin, Claude sign-in, 129), `tools.php` (tools, agent, mind,
  search, Telegram, 248), `web1.php` and `web2.php` (web API, WebDAV, shares, 154 + 235), `misc.php` (worker, CLI,
  Spotify, board, European provider, transcription, doctor, browser proxy, config, connector packages, updates, 247).
  Shared helpers (`check`, `web_app`, `FakeModel`, ports, temp folders) moved to `tests/helpers.php`.
- **WebDAV on Linux**: `deploy/mount-webdav-linux.sh` checks for FUSE first and explains what to do on WSL.
- **Fixes found by the new tests**:
  - Email previews drop the text inside `<script>`, `<style>`, `<head>` and `<title>`, not only the tags.
  - OpenDocument list items with several paragraphs keep them all.
  - Front matter with `#` comment lines is read correctly.
  - The privacy log names a call "Ollama" only when both host and port match.
  - Fleet transfer progress names the instance and the source server; an update records the previous image only
    when there was one.
  - Invalid settings and European provider keys answer 422, so the page shows the field errors.
  - Connector package inspection blanks secret values by variable name (`*PASS*`, `*SECRET*`, `*TOKEN*`, `*KEY*`).

## 0.28.26 (2026-10-14)
Security release, second part: the Low findings of the code review, and some tidying.
- **Shared to the app** (Android share sheet): a share belongs to the browser that sent it (its own cookie), so a
  link to someone else's share opens nothing; each browser keeps its last 5, and at most 50 wait in all.
- **Approvals**: a bare "yes" / "no" answers only a request raised in the same channel (or by a schedule or job);
  "yes 12" still works from anywhere.
- **Two-step sign-in**: turning it on asks for your password.
- **Web app**: cannot be framed by another site (`frame-ancestors`, `X-Frame-Options`), sign-out is POST only,
  a conversation is marked read only by the app itself, and cookies are `Secure` whenever the public address is
  https. Server errors show a reference (the details stay in the log); Telegram does the same.
- **Files shown in the page** only when their content matches their type (images, PDF, audio, plain text);
  anything else downloads. PDFs keep the browser's viewer, which cannot run sandboxed.
- **Links in answers**: `intent://` opens an Android app by its package only, nothing else.
- **Connector sign-in (OAuth)**: the addresses a server hands out stay on its own host or on a public one.
- **Claude Code (subscription)**: reads only the images of the turn; web pages are read with Talixio's
  `fetch_url`, which refuses local addresses, instead of Claude Code's WebFetch.
- **Google, Microsoft and Spotify sign-in** use `TALIXIO_PUBLIC_URL` for the return address when it is set.
- **File names**: one rule everywhere (uploads, renames, WebDAV): "Facture (Mars) & co.pdf" stays as is. New files
  are stored on disk by number.
- Deployment notes on the fleet agent's Docker socket, AppArmor and image tags (deploy/docker/README.md).
- Tidying: exceptions in their own files, unused code removed.

## 0.28.25 (2026-10-14)
Security release: the High and Medium findings of the code review.
- **Shares**: a folder holding a Secret space can no longer be shared, and every opening checks each file again
  (a file moved into a Secret space disappears from links; Confidential needs a password and an end date).
- **Chat by email**: only mail your provider verified is trusted (DMARC pass or your domain's DKIM in the topmost
  `Authentication-Results`); `TALIXIO_EMAIL_AUTHSERV_ID` names the only server to believe.
- **Behind a proxy**: `X-Forwarded-For` / `X-Forwarded-Proto` count only from `TALIXIO_TRUSTED_PROXIES` (default
  `loopback`), and a global lockout stops password guessing spread over many addresses.
- **Updates**: the root script keeps its state in `/var/lib/talixio-update`, never in the app's data folder.
- **Sandbox with internet**: shell commands and scripts count as going outside (approval in Confidential spaces,
  never in Secret ones).
- **Chat links**: link text and addresses in Markdown are escaped, so a crafted link in a reply can no longer
  inject markup or scripts into the page.
- **Approvals** show the whole email (with cc) or event description, not the first lines.
- **Address checks** (web fetches, webhooks, connectors) block IPv4-mapped and NAT64 forms of private addresses.
- **Browser**: all its traffic goes through a small proxy that resolves each host itself and refuses private
  addresses, so DNS rebinding and WebSockets no longer reach the local network.
- **After reading untrusted content** (an email, a web page, a connector's result), lasting changes (memory,
  skills, routines, filing) and fetches of addresses not found in that content wait for your approval. Known limit:
  the page's own links still open without asking, so a form on it submitted by GET is not stopped.
- **Connectors**: every tool asks for approval until you mark it read-only (click it in Settings → Connectors; a ?
  shows what the connector claims, which is only a hint). Local connectors run in a sandbox (bubblewrap) that
  sees only their own folder; `"sandbox": false` lets one out, except on a shared server.
- **Transfers**: a code is good for one copy (a test copy and the final one take two codes), and the copy goes
  over https only (`--allow-http` for a private address on a local network).
- **Shared servers**: the service worker no longer asks for the whole site, and the admin settings warn that the
  one-address mode puts everyone on one web origin (subdomains are recommended unless everyone trusts each other).

## 0.28.24 (2026-10-13)
- Memory page redesigned like Settings: a side menu (chips on a phone) with one section at a time (Character and
  values, Skills, Scripts, Conversation digest, Memory files), each showing what it holds (beliefs established,
  skills, scripts to approve, digest depth, unsaved changes). Search moves to the header and replaces the sections
  while it runs; links such as `#memory/scripts` open a section directly. Memory files gain "Undo changes".

## 0.28.23 (2026-10-13)
- Linux drive script: checks for FUSE before mounting and says what to do when it is missing (on WSL, map the drive
  in Windows and mount it with drvfs) instead of blaming the address or password; no more locale warning.

## 0.28.22 (2026-10-13)
- Network drive on Linux: `deploy/mount-webdav-linux.sh` installs davfs2 and sets up the mount (address with spaces
  and accents encoded, files owned by the user, mount without sudo, run again to change the address or password).
  The FAQ explains it, and how to open a subfolder directly.

## 0.28.21 (2026-10-13)
- Network drive: a device retrying an old saved password no longer locks the drive. Each different wrong password
  counts once, the right password always gets in (it is long and random), Settings › Network drive shows when an
  address is blocked with **Unblock now**, and a new drive password unblocks every address.

## 0.28.20 (2026-10-12)
- Library: the Markdown editor is now Milkdown Crepe (MIT), self-hosted in `web/public/vendor/` and loaded only
  when you edit. Type `/` for headings, lists, quotes, code, tables or a divider; select text for the formatting
  toolbar; edit tables from their handles. **Markdown** switches to the source. A file saved without changes is
  kept byte for byte. The build recipe is in `web/vendor-src/milkdown`.
- Library: Esc closes the editor's menus first and only then the viewer.
- Library: **Use as a script** now confirms in the viewer where the script went (Memory › Scripts), with a
  **Show the script** button that opens it.

## 0.28.19 (2026-10-12)

- Library: Markdown files are edited in the visual editor (headings, lists, checklists, tables, links), with the **MD**
  button for the source; files with front matter, HTML or fenced code with a language open on the source. A file
  saved without changes is kept byte for byte.
- The library editor has a *Full screen* button (Esc leaves full screen before closing the viewer).
- The visual editor keeps `####` headings instead of turning them into `###`.

## 0.28.18 (2026-10-12)

- Updates and restarts no longer silently lose running jobs: a job killed by a restart is started again once (a
  research job carries on from the steps it had done; a job that was already such a restart is not retried again),
  and the user gets one message, in the thread and on their notification channels, listing the interrupted jobs.
- The update waits up to 10 minutes for running jobs to finish before restarting, and starts no new job meanwhile
  (`talixio drain`, released by the restart or `talixio drain off` if the install fails).
- Prompt: Python tools the assistant writes for itself go through `script_save` (stored in /tools with their header,
  shown on the Memory page), never as `.py` files in the library; "outil", "tool" and "simulat…" now bring the
  sandbox tools.
- Prompt: the assistant never says it created, saved or scheduled something unless a tool did it and returned success
  (it had announced three scripts in a library folder that were never written).
- Prompt: for a study or long project, ask the user a numbered list of questions first, keep the report in the folder
  the user named and update it there, and offer a weekly routine to move it forward.

## 0.28.17 (2026-10-12)

- New browser tool `browser_save_login`: after logging in to a site in Talixio's browser (the user gives the code they
  receive), the assistant saves that login's cookies to the file a connector reads (e.g. the `cookiesPath` an Uber Eats
  connector reports), so connectors that ask for a login "in your browser" work on a headless server. Playwright cookie
  format (or storage state if the file already has that shape), only a `.json` file under the server's home or data
  folder, never over a file that isn't a cookie file, and it asks for approval first.
- Prompt: when a connector says it isn't logged in, the assistant does that login in its browser and saves it.

## 0.28.16 (2026-10-12)

- Asked to "look on Uber Eats" for dinner, the assistant asked "shall I proceed?" before checking the connector's
  status. Looking things up (a site, a connector's status, the web, the documents) is now done straight away;
  when a tool fails, the assistant gives its actual error. Without browser tools on the server, it uses a connector
  made for the site if there is one, otherwise says so in one sentence. "regarde sur…", "check on…", a site's
  address and common sites (Uber, Deliveroo, Amazon, Doctolib, SNCF…) bring the browser tools to the first answer.
- It no longer guesses what a postal code means (13006 is Marseille 6e, not Paris 13e): when unsure, it looks it up.

## 0.28.15 (2026-10-12)

- Your own scripts: **New script** and **Upload a .py** on the Memory page, and **Use as a script** on a `.py` of
  the library (under More), which copies it into the assistant's scripts (using it again updates the copy). A
  script you add counts as approved. Without a header, it is named after the file and described by its first
  comment.

## 0.28.14 (2026-10-12)

- Cost estimates that learn from your real bills. Once a month (from the 3rd), the assistant asks at the end of
  an answer what your AI provider actually billed for last month, and records it (`usage_bill`). Talixio
  compares it with its own estimate for the same days, and corrects the budget by that ratio, averaged over the
  last three bills: the estimates get closer to the bill each month. You can also enter a bill under Activity
  (the line below the meters). Bills in euros are converted with Settings → Budget → Euro to dollar rate.
- Reusable scripts. With the sandbox on, the assistant can save the Python code of a mechanical task it does
  again (converting a bank export, tidying a table, a recurring calculation) as a script, and run it next time
  with its arguments instead of rewriting the code. The scripts are listed in its instructions with what they
  do, mounted read-only in the sandbox, and shown on the Memory page (open, edit, delete). With
  `TALIXIO_SHELL=ask`, you approve a script's code once; it then runs without asking until it changes.

## 0.28.13 (2026-10-12)

- Lower model bills through the prompt cache. The time, budget and recalled memories of each turn now come in front
  of the latest user message instead of after the instructions, so the instructions and the earlier conversation
  stay identical from one call to the next, and the provider bills them as cached input (10% of the price with
  Mistral, OpenAI and Claude). With Claude the conversation itself is now cached too, at every tool step.
- With focused tools (the default for European and OpenAI-compatible providers), a tool group given to one message
  stays for the next 30 minutes in that space, so the list of tools doesn't change from message to message and
  break the cache.

## 0.28.12 (2026-10-12)

- Routines are set in plain words: `schedule_task` and `schedule_job` take `when`, like "every weekday at 8:00",
  "every monday and thursday at 18:30", "on the 1st of every month at 9:00", "tomorrow at 14:30", "in 20 minutes",
  or in French "tous les jours à 7h", "du lundi au vendredi à 8h", "demain à 9h". Talixio turns it into the repeat
  itself, so small models no longer get cron fields wrong. The reply lists the next three runs and the prompt that will
  run, so the model can check them; a schedule it cannot read comes back with examples. `cron` and `at` still work.
- Mistral costs much less: Small now does the everyday work (Standard), and Medium the hard turns (Deep), instead of
  Medium and Large. Small costs a tenth of Medium.
- A stronger model when needed (Settings → AI model → advanced, "Stronger model when needed"): with a European provider,
  a message about routines and reminders, a retry ("ça ne marche pas", "try again"), or the turn after a tool failed
  goes to the Deep model, with "Using a stronger model…" shown while it works.

## 0.28.11 (2026-10-11)

- A connector driving a web browser that was installed before its browser was (Uber Eats before 0.28.9) no longer fails
  with "Executable doesn't exist": the first time one of its tools is used, Talixio downloads the browser (about
  100 MB, once), then runs the tool. If the download fails, the reason is shown, and it is tried again an hour later.

## 0.28.10 (2026-10-11)

- Connector list: a "Hosting and cloud" section (OVHcloud, Hostinger; what exists for Scaleway, Infomaniak, o2switch).

## 0.28.9 (2026-10-11)

- Connectors that drive a web browser (Playwright, Patchright, such as Uber Eats) work: installing one also downloads the
  Chromium it was built for, once for all of them, into the data folder. Before, it failed with "Executable doesn't
  exist … npx playwright install". Reinstall such a connector to get its browser.
- Mistral: background jobs no longer fail with "reasoning_effort='low' is not supported for this model". The effort of
  a job is only sent where one is set for the model, and a model that refuses it is asked again without it.

## 0.28.8 (2026-10-11)

- FAQ: a list of connectors that work with Talixio, by topic: French and European public data (data.gouv.fr, company directory, Sirene, addresses, BODACC, public procurement, property sales, SNCF, Eurostat, EUR-Lex…), cities and regions (Paris and its events, Île-de-France, Toulouse…), maps, routes, bike sharing and trains from OpenStreetMap and open data, weather, energy and fuel prices, RSS feeds, films, series, TV schedules and cinema showtimes (where to watch on Netflix or Prime Video, Allociné), Qonto, online services (Notion, Linear, monday.com, Airtable, Todoist, Miro, Canva, Gamma, Calendly, Trello, Stripe, PayPal, Wix, Hugging Face, Wolfram, and how to add Zapier or Make), ferries and activities (Ferryhopper, Peek), jobs (France Travail, Arbeitnow, Remotive), and experimental grocery and delivery ones (Uber Eats, E.Leclerc Drive). It also says what does not exist or cannot be installed yet (Carrefour, Fnac, Darty, EDF, SeLoger, RATP, Google Maps, travel agencies, other banks, Google Drive, Dropbox, LinkedIn, Indeed, APEC, temp agencies…). *Connectors / Connector list* in /FAQ.
- One click installs: **▶ Install** in that list (or in an answer of the assistant) opens the install window and fetches the GitHub repository; for an online service, Talixio asks you to confirm, then adds it and connects to it.
- Asking which connectors exist, or to install one by its name, brings the list to the assistant, which offers the same install links.

## 0.28.7 (2026-10-11)

- Connectors: install one from the address of its GitHub repository. Settings → Connectors → *Install a connector…*, paste `https://github.com/owner/repo` (or `…/tree/main/folder` for a server in a sub-folder) → *Fetch*: it is downloaded into /Connecteurs and installed like a `.zip`. The assistant can do it too ("install the connector https://github.com/…"), after your approval; when the connector needs settings, it gives you the link to fill them in yourself.
- Questions about connectors: the assistant now answers from the FAQ page, which comes with the question, instead of from general knowledge (some models described `git clone`, `npm start`, a `localhost` address and a restart, none of which Talixio needs). The FAQ page says what not to do.

## 0.28.6 (2026-10-11)

- Settings → European provider: a table shows the model behind each tier (fast, standard, deep) with its price per million tokens in and out, and what a typical message and a month of use cost. Prices added for Mistral's current models, Qwen 3.5, DeepSeek and GLM.
- General can now have its own model tier, like the other spaces (space menu → "Model for General"). It was always standard.
- Fewer tokens per message with the European and OpenAI-compatible providers: only the tools a message seems to need are sent (memory, files and web always; mail, calendar, tasks… when mentioned), and the model can ask for the others with `tools_more`. `TALIXIO_TOOLS` (Settings → AI model, advanced): auto, all or focused.

## 0.28.5 (2026-10-11)

- Jobs: a finished job's card no longer squeezes its title into a narrow column; the buttons wrap on the right instead.

## 0.28.4 (2026-10-11)

- Mistral: replies (and job results) no longer show "Array". Its newer models send their answer as a list of chunks, thinking included; only the text is kept now.

## 0.28.3 (2026-10-11)

- A meeting is no longer held back for a missing email address: the assistant creates it without that guest
  and tells you who still needs inviting ("fais sans" and "tant pis" now count as a go-ahead too).
- An answer that reworded the previous one without doing anything is now caught too, and handed to a
  stronger model.
- The assistant can no longer call the same tool with the same details again and again in one answer, and
  when it runs out of steps it now says what is done and what is missing instead of "(stopped after too
  many tool steps)".

## 0.28.2 (2026-10-11)

- The quick model could repeat the same plan after "yes" instead of doing it. A short "yes" / "go ahead"
  now gets at least the standard model, and an answer that only repeats the previous one, with no action
  taken, is handed once to a stronger model, which acts or says plainly what it cannot do.
- The progress messages in the chat ("Reading the attachments…", "Asking a stronger model…") are now
  translated.

## 0.28.1 (2026-10-11)

- Recurring meetings: when asked for a meeting "every Monday until Christmas", the assistant could make one
  task running from the first Monday to Christmas (busy for weeks in your calendar). It now makes the
  series in the calendar (with the guests and the days left out), and a task with a time can no longer
  span more than a week when the assistant makes it.

## 0.28.0 (2026-10-11)

- FAQ: one folder per language in the library, /FAQ/Français and /FAQ/English (every page now also in
  English). The release notes and the reference documents are in /FAQ/English. The assistant cites the page
  in your language. The old folders are replaced on the first start.
- README: screenshots, and what makes Talixio European and GDPR-friendly (your server, AI from Europe only
  with a lock, a log of every outside request, a confidentiality level per space).

## 0.27.0 (2026-10-11)

- Calendar: the assistant can now create recurring meetings (every day, every weekday, every week or
  every month, until a date), leave out some days or the French public holidays, and invite people by
  email. Google Calendar and Outlook send the invitations; with CalDAV the guests are in the event.
  The approval request shows the series and the guests.
- When you answer "yes" to something the assistant proposed, it now does it with its tools instead of
  repeating its plan, and it says plainly what it cannot do.

## 0.26.0 (2026-10-11)

- Outlook / Microsoft 365 calendar: connect it in Settings → Calendar with your own app registered in
  Microsoft Entra (the steps are shown there). The assistant reads your Outlook calendar, adds events to
  it after your approval, and the tasks marked "show in my calendar" go there and are kept up to date
  (all day, or busy at their hours for a meeting), like with Google Calendar. Both can be connected.
- European provider: when it refuses its key (expired or deleted, HTTP 401/403), the chat now says so
  and where to make a new one, instead of the raw error.

## 0.25.0 (2026-10-11)

- Meeting invitations: a task can have guests (email addresses) and a place. "Send the invitations"
  emails them a standard calendar invitation that Outlook, Gmail, Apple Calendar and Thunderbird show
  with Accept / Decline; after a change, "Send the update" sends the new time or place, guests removed
  get a cancellation, and "Cancel the meeting" (or moving the task to the trash) tells everyone. It is
  sent from your own mailbox when it is connected (a copy goes to Sent, the answers come back to you),
  otherwise from the assistant's address on your behalf. Only the title, time and place are sent, never
  the description. The assistant can do it too (board_invite), after your approval. The place also goes
  to Google Calendar and the iCal link.
- European provider: "Test the key" now makes a real call, since some providers (OVHcloud) list their
  models without checking the key: a refused key is reported instead of "it works". The embeddings no
  longer use a local (Ollama) model name with the European provider. Missing French texts added.

## 0.24.0 (2026-10-11)

- Tasks can have hours ("From" / "To" next to the dates): a timed task is a meeting slot. It shows with
  its time in the calendar view and on the board, and in Google Calendar and the iCal link it is a timed
  event marked busy (one hour by default). Entering a start time ticks "Show in my calendar". The
  assistant can set the hours too (start_time / end_time).

## 0.23.5 (2026-10-11)

- Library: "Ask about this document" now attaches the document and leaves the message box empty, with
  "Ask about <name>…" as its hint, instead of a long cut-off line of text.
- Markdown files that start with a YAML header (`---` type, id, title… `---`) show it as a small table of
  properties instead of a huge bold heading.
- Wide screens: the library uses up to 1400px, and the document reader is wider and sits beside the list
  instead of covering it.

## 0.23.4 (2026-10-11)
- Mail: links to a document of the library open it in the app, at its page, on the app's own address. They used
  to point to the server root (without /fred), and some models made up an address like assistant.example.com:
  such made-up addresses are now replaced with the app's. The assistant is also told to keep these links as given.

## 0.23.3 (2026-10-11)
- Europe only: a new option moves search by meaning (Ollama) and transcription from this server to the European
  provider, so a smaller server is enough. Changing the search model re-indexes the library in the background.
- European providers: the fast model is a Mistral model when the provider offers one (gpt-oss, the usual
  alternative, tends to answer in English). The assistant is also told explicitly to answer in the language of
  the user's last message, even when tools and documents are in English.

## 0.23.2 (2026-10-11)
- Europe only: "Run checks" no longer reports "sovereign credentials" as failing with a European provider (the
  check did not know it; nothing was wrong). It now names the provider's key and adds an "EU · GDPR" step that says
  everything stays in Europe, or lists what still leaves it.
- Settings: a "100% Europe · GDPR" stamp next to "Run checks" while nothing leaves Europe (orange, with a count,
  when something still does). With a European provider, the keys and sign-in for providers outside Europe are kept
  but hidden, with a link to show them.
- Connectors running on this server or the local network are no longer counted as leaving Europe.

## 0.23.1 (2026-10-11)
- FAQ: no more copies of the same page ("Page (#12).md" over WebDAV). A sync cut short (or run twice at once) lost
  track of the pages it had made and made them all again; pages are now found again by folder and name, only one
  sync runs at a time, and the copies already there are removed at the next start.

## 0.23.0 (2026-10-11)
- Europe only: a card in Settings → Model and budget switches the conversation model, transcription and memory
  search to one European provider (OVHcloud AI Endpoints by default, Mistral AI, Scaleway, Infomaniak), with a
  Test button, links to get a key and the provider's data terms, and a lock that never sends anything to an AI
  or search service outside Europe. It lists what still leaves Europe (web search, Telegram, Google Calendar,
  Spotify, remote connectors…) with a link to change each one.
- The European provider is also in the regular settings (Provider, transcription, memory search).
- Multi-user server: the admin page can give every assistant one European provider account (key never sent
  back to the page; the Google client secret isn't either anymore).
- List prices for the European providers' models (Mistral, gpt-oss, Qwen, Llama), approximate.

## 0.22.0 (2026-10-11)
- Settings redesigned. Seven sections (Essentials, Model and budget, Channels, Connected accounts, Library
  and memory, Security, System) in a side menu, shown as chips at the top on a phone. A status band at the
  top lists what needs attention, each section shows a dot (ok / to review), advanced settings are folded,
  a search box filters every field, and one Save bar applies all changes at once (with Cancel).
- Old links (#settings/google, #settings/budget…) open the matching new section.
- Network drive (WebDAV): the address shown is now the one the page is open on, instead of the stored
  public address (which could be a placeholder such as assistant.example.com).

## 0.21.4 (2026-10-11)
- Document sheet on a phone: the buttons no longer take half the screen. Only "Ask about it" and a "More"
  button show; "More" unfolds Download, Open in a new tab, Share, Rename, Move, Review and Delete. Margins
  are tighter and the preview uses the full width, with smaller headings on narrow screens.
- Multi-user server: an update no longer breaks the admin page. Moving the admin and agent containers to
  the new version sent Docker empty lists where it expects empty objects (`ExposedPorts`, `Volumes`…),
  which Docker refused (HTTP 400). The update now also reloads Caddy, so its new routes apply right away:
  this is what kept the WebDAV fixes of 0.21.2 from reaching servers updated from the admin page.
  To recover a server where the update failed: `git pull` then `sudo deploy/docker/install-stack.sh`.

## 0.21.3 (2026-10-11)
- Emails look better. The messages the assistant sends by email (scheduled jobs, morning briefing, reports,
  notifications, answers to emails) now come in a proper HTML layout: a card with the title, the space and
  its color, a button that opens the app, and the message's full Markdown (headings, nested lists, tables,
  quotes, code, task lists, italics), all styled inline so Gmail and Outlook show it as intended. Links to
  pages of the app are made absolute so they work from a mail app. Mail sent on your behalf from your
  mailbox gets the same formatting without the Talixio frame.

## 0.21.2 (2026-10-11)
- Network drive (WebDAV) on Windows: Windows could not map `https://…/<id>/dav/` on a multi-user server
  because it first probes the folders above (`/` and `/<id>`), which answered "not found" or redirected.
  Caddy now answers those probes like a WebDAV server, `/<id>` reaches the assistant without a redirect for
  WebDAV requests, and the assistant answers OPTIONS before sign-in. Run the update so Caddy picks up the
  new configuration. In Settings, a new attempt to create a drive password hides the previous one, so an
  "incorrect password" message is no longer shown next to an old drive password.

## 0.21.1 (2026-10-11)
- Admin page (multi-user server): an update cut short, e.g. because `install-stack.sh` was run from the
  command line while it was building and replaced the agent, no longer stays "in progress" forever and blocks
  the Update buttons. The agent now records the job's process and shows a job whose process is gone as
  interrupted, so a new update can start. `install-stack.sh` also records the image it built, so "Running…"
  in the Update tab shows it rather than the last update made from the admin page.

## 0.21.0 (2026-10-11)
- Network drive (WebDAV): the library's folders open as a drive in Windows Explorer, macOS Finder, the Files
  app on iPhone/iPad and WebDAV apps on Android, at `<address>/dav/`. Settings → Network drive: off, read only
  or read and write, and its own password (shown once, needs the app password). Files added or changed there
  are read and indexed again like uploads; moves and renames keep the index; deletions go to the library's
  trash. Secret spaces never show, the FAQ stays read only, system clutter (.DS_Store, ._ files, Thumbs.db…)
  is kept out of the library, wrong passwords are throttled per address.
- File names: renaming keeps any character but slashes, control characters and `<>:"|?*`.

## 0.20.0 (2026-10-10)
- Quick capture: a chat message starting with `t:` (or `task:`, `todo:`, `tâche:`) adds a task to the space's
  board, `n:` (or `note:`) saves a note in the space's folder or the Inbox, at once and without calling the
  model. `!1`..`!5` sets the priority; the first line is the title or the note's name. A **+** button in the
  top bar and a "Capture" shortcut of the installed app open the same capture as a dialog.
- Routines learn from 👍/👎: each line of a message sent by a routine (shown as cards) can be rated; the votes
  stay on the message, are listed (and removable) per routine on the Routines page, and are given to the
  routine's next runs. New assistant tool `routine_feedback` for feedback said in words.
- Morning briefing: 5 lines at most, most important first, one emoji per kind. Existing briefings are updated
  (what you added to them is kept).
- Weekly review, ready-made on the Routines page (Sunday 18:00 by default): done, slipped, the week ahead,
  waiting emails and 3 priorities; the full review is saved as a note in /Notes/Weekly reviews.
- Watch and draft: a ready-made routine that follows topics (sources, audience, format, daily/weekly/monthly)
  and has a background job write the draft of the next issue in /Drafts/<name> (default name "Fil IA").
  Several watches can be set up and edited.
- "Where is…?": the library search lists the folders and files whose **name** matches first (accents and case
  ignored), then the content matches. New assistant tool `kb_find`; folder links in the chat open the folder.

## 0.19.9 (2026-10-10)
- Library: moving a file (and choosing a folder for a new folder, a renamed folder or a space) shows the whole
  folder tree under the field, indented, filtered as you type, with the keyboard or a click. The old list
  was empty because it only kept the folders matching the current path. A path that does not exist yet
  is still accepted and created on save.

## 0.19.8 (2026-10-10)
- Jobs page: search (title, instructions, report), Active / Archived switch, and per finished job: the link
  to its report note (found by id, so it follows the note when it is moved; says so when it was deleted),
  *Archive* / *Unarchive*, *Delete* (the report note and the usage history stay) and *Schedule…*, which
  makes a routine that runs the job again once or on a repeat, without going through the model.
  *Archive finished* puts away every finished job of the view; finished jobs older than 30 days are
  archived on their own. New assistant tools `archive_job` and `schedule_job`; `list_jobs` can search and
  show archived jobs and gives the report links.

## 0.19.7 (2026-10-10)
- Jobs: the full report is saved as a note in the library when the job finishes, so nothing is lost when the
  chat relays it in short: in the Inbox ("Job - title - date"), in the folder the user named when asking
  (new `folder` option of `delegate`, kept by "run again" and "continue"), or next to the recording for a
  meeting summary. The assistant gives the link and can file it elsewhere.

## 0.19.6 (2026-10-10)
- Docker: updating from the admin page now also moves the admin page and its agent to the new version (they
  stayed on the image of the installation, so new admin features never showed). The agent replaces itself
  last; if the new one does not answer, the old one stays and the job says so.

## 0.19.5 (2026-10-10)
- Admin page, Updates: says whether the branch has a newer version than the one running (its number, date
  and last change) and lists the changelog entries in between; checked when the tab opens and with
  *Check now*. Only the branch's last commit is fetched (and its files only when it changed).
- FAQ (Audio and meetings): the transcription models available for Gemini and whisper.cpp, and which to pick.

## 0.19.4 (2026-10-10)
- Admin page: **Log** in each assistant's Actions menu shows the end of its log (errors, warnings and failed
  requests; every line on demand), without a terminal on the server. Reading it is in the audit trail.
- A file that cannot be read (transcription, image reading, PDF…) now writes why in the log.
- Transcription with Gemini: a whisper.cpp model name left in "Transcription model" is no longer sent to
  Gemini (it was rejected); the Gemini model is then chosen automatically.

## 0.19.3 (2026-10-10)
- Docker: updating from the admin page works with a private repository: put a read-only token in
  `GIT_TOKEN` in `deploy/docker/.env` and run `install-stack.sh` once; the agent sends it as a header when it
  clones (not in the address or the command line). Without it, the error now says what to do instead of
  "could not read Username".

## 0.19.2 (2026-10-10)
- Searching the conversation (the magnifier in the chat) and the Memory page: the messages with the words
  show at once, with a spinner while search by meaning completes the list; a new search cancels the
  previous one (late answers are dropped). Before, nothing showed until the slow part was done.
- Connectors: tools named like reads (`list_…`, `get_…`, `search_…`) run without asking for approval when the
  server does not say otherwise; the assistant then answers from the result directly.
- An approved action's result is kept whole in the conversation (up to 20 000 characters, JSON indented in a
  scrolling block), so the assistant can read all of it; before, it was cut at 600 characters.

## 0.19.1 (2026-10-10)
- Docker: faster updates. The dependencies (system packages, Chromium, LibreOffice, whisper.cpp,
  signal-cli…) are an image of their own, `talixio-base:<hash of deploy/docker/base.Dockerfile>`, built only
  when that file changes; the application image starts from it and only copies the code. Before, an update
  from the admin page used another builder than `install-stack.sh` (no shared cache) and pulled Debian
  again, so everything was often rebuilt. The admin page now builds from a copy of the branch, with the
  right version shown. `install-stack.sh --rebuild-base` rebuilds the dependencies on purpose.

## 0.19.0 (2026-10-10)
- A trash for the library: deleting a file or a folder moves it to the trash (bottom of the folder tree;
  a *Trash* button on phones) for 30 days. It leaves searches, the assistant and shared links meanwhile.
  *Restore* puts it back where it was (a folder comes back whole); *Empty the trash* deletes for good.
  The assistant can list and restore it too (`kb_trash`).

## 0.18.0 (2026-10-10)
- Install your own connector without an administrator: put the MCP server's folder as a .zip in the library
  (e.g. /Connecteurs), then *Install as a connector* on the file (or Settings → Connectors → *Install from
  the library…*). Talixio finds how to start it and the settings it reads (address, account, password),
  unpacks it in the assistant, installs its Node dependencies (builds it if needed), declares it and lists
  its tools. Reinstalling the same name updates it and keeps a password left empty.
- Connectors may give a working folder relative to the data folder: they keep working after a move.

## 0.17.0 (2026-10-10)
- Settings → Web search and memory: a choice for search by meaning. On this server (Ollama): very light
  (multilingual-e5-small, ≈ 0.3 GB), light (EmbeddingGemma, ≈ 0.7 GB) or best quality (Qwen3-Embedding,
  ≈ 1.5 GB); online: Gemini, OpenAI, Voyage; or off. Each says what it needs; a local model is downloaded
  when chosen; switching re-indexes in the background, with the progress shown.
- *Measure on this server*: the time of a search, of indexing a passage, and of comparing every vector.
- sqlite-vec (Docker image, and `install.sh`): SQLite compares the vectors in C, about 6 times faster than
  PHP (20 000 passages: 0.15 s instead of 0.95 s on a test machine). Without it, PHP still does it.
- Docker: Ollama is limited to half the processors (`OLLAMA_CPUS`); turned off in the admin page, it is
  stopped and its memory goes to the assistants. The admin page picks the shared model among the three and
  lists those downloaded.
- e5 models get their `query:` / `passage:` prefixes.

## 0.16.3 (2026-10-10)
- Library search in two passes: documents with the words show at once, then the search by meaning (the
  local model, slower) completes the list. A spinner says it is running; *Cancel* (or Esc) stops it and keeps
  what was found.
- One search at a time: opening another folder or starting another search stops the running one, and an
  answer that comes late is dropped. Results and the folder's list are never shown together any more (a slow
  search used to land on top of the folder opened meanwhile). *Close the search* goes back to the folder.

## 0.16.2 (2026-10-10)
- Admin page, *Update everyone*: the branch field shows the branch running now (or the one the stack was
  installed from) instead of always `main`. Running `install-stack.sh` again records the branch the clone is on.

## 0.16.1 (2026-10-10)
- Archiving a space asks for a second click: it was one click, next to *Delete*, and easy to hit by mistake.
- An archived space says so above its conversation, with *Restore*.
- Each space keeps a history (created, renamed, archived, restored, confidentiality or folder changed: when,
  and whether from the app or by the assistant), shown in its settings and in the log.
- The assistant can list the spaces (archived ones included), tell what happened to one, and restore one
  (`spaces` tool). The FAQ page on spaces explains archiving.

## 0.16.0 (2026-10-10)
- Sign in with Claude: Settings → AI model (or the card in the conversation) runs Claude Code's own sign-in
  for a Pro/Max plan from the web app: open Claude's page, paste the code back, done. No terminal needed.
- No model set up: the conversation says so and how to fix it (a card with *Sign in with Claude* and
  *Use an API key*), instead of an API error.
- Documents found again after the data folder moves (install.sh → Docker, a transfer): their paths are
  updated at start. A migrated library was listed but its files could not be opened.
- Library: the folder tree folds and unfolds (remembered per device; the current folder's path stays open).
- Docker image: signal-cli falls back to its Java build when the native one cannot run on the processor
  (virtual machines with an older processor model); the build no longer fails on it.
- Background actions (admin page jobs, Claude sign-in) are fully detached from the web server.

## 0.15.1 (2026-10-10)
- Admin page: a Server panel with memory, processors (load, model) and disk (images, data, build cache), whether
  the server is well sized for the people on it (limits promised against the machine, load, swap, free disk) and
  how many more people fit; the shared services (Caddy, Ollama, agent, admin page): answering or not, in how long,
  restarts, memory. Each person shows whether their assistant answers, its last check time, restarts and
  out-of-memory stops.

## 0.15.0 (2026-10-10)
- FAQ in the library (/FAQ, read-only, in French): using the assistant, privacy, installing and updating,
  administration, and one page per version (release notes). Kept up to date with each version and indexed:
  ask the assistant about itself and it answers from these pages, citing them.
- Moving an assistant to another server, whole (conversations, memory, skills, library, tasks, settings and
  keys, password): Settings → Move to another server makes a transfer code; on the new server, admin page →
  From another server (or `talixio transfer-pull`). Test copies stay paused (no channels, no routines) and
  can be repeated; the final one pauses the old server and points it to the new address.

## 0.14.1 (2026-10-10)
- Moving an installation into the Docker stack: the settings of `/etc/talixio/talixio.env` were not imported
  (the file is readable by root only).

## 0.14.0 (2026-10-10)
- The Docker image includes whisper.cpp (transcription on the server, the model shared by every assistant
  in one volume) and signal-cli (Signal channel). Moving an install made with `--with-whisper` reuses its model.
- Local transcription uses only the processors the container is given, and says when the memory is too small.

## 0.13.1 (2026-10-10)
- Docker stack behind a reverse proxy you already have (`install-stack.sh --behind-proxy 8080`): plain HTTP on
  one port, HTTPS stays on your proxy.

## 0.13.0 (2026-10-10)
- Privacy: a page showing where your data goes (worked out from your settings), what each service may do with
  it and how to fix it; a journal of every request to the outside (never the content), CSV export, and a record
  of processing (GDPR) to start from.
- A confidentiality level per space: Internal, Confidential (only a business-terms model reads the content, the
  web needs your approval each time, local transcription and indexing, silent notifications, protected links),
  Secret (nothing leaves the server). Enforced on every outside request.
- Under each answer: where it went, which documents were given to the model, what stayed here or was blocked;
  live while it answers.
- Settings: "My Gemini key is on a paid plan", "Notifications without content".

## 0.12.1 (2026-10-10)
- Fix: an installed app stuck on an older version (frozen in French by the 0.11 translation loop) now picks up
  the fixed scripts as soon as the server is updated: their address changes with each version.

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
