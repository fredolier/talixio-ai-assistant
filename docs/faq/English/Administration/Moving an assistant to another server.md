# Moving an assistant to another server

Everything goes: conversations, memory, skills, library, tasks, settings and keys, password,
two-step sign-in, Signal registration.

## 1. On the old server, in the assistant
Settings → **Move to another server** → *Create a transfer code*. Note the assistant's
address and the code (`tx-…`): it is shown only once and works for 24 hours (a new code
replaces the old one; *Cancel it* turns it off). The old server must run version 0.15 or later.

## 2. On the new server
- **Docker**: admin page → People → **From another server**: the address, the code,
  the identifier here (e.g. `fred`) and the name. Progress shows in the Updates tab.
- **Simple install**: `sudo talixio transfer-pull https://old.example.com/fred tx-… --url https://new.example.com`
  (the app is stopped during the copy, then restarted).

## Test copy, then final copy
- A **test copy** arrives **paused**: no Telegram, Signal, email, routines or reminders, so that
  the two assistants never answer the same messages. You can open it and check it. Copy again
  as many times as needed: each copy replaces the previous one.
- The **final copy** (*Final move* box, or `--final`): the new assistant is active, and
  the old one **pauses** itself and shows the new address.
- A test copy can also become the assistant with *Use this one* (pause the old one first).

## What is left to do afterwards
- Open the new address and reinstall the app on the phone; turn notifications back on.
- Share links already sent use the old address.
- Google Calendar and OAuth connectors: add the new redirect address at the provider if needed.
- Data that was previously in the destination assistant is kept in `.previous-<date>` (the latest one only).

## Security
The code gives access to all the data: only pass it on through a safe channel. After 5 wrong codes,
the address trying them is blocked for a while, and you are notified of every copy.
