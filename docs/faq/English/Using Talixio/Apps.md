# Apps

**Apps** (in the menu) holds small apps the assistant builds for you: a chart of a share price, a dashboard of your
tasks, a tracker for your expenses or habits, a table of a CSV file in the library…

## How to get one

- Ask in the chat: *“Build me an app that charts the price of Apple shares over the last month.”*
- No idea yet? Ask: **“With what you know about me, could you suggest several apps you could build and add to
  the Apps menu?”** The assistant looks at your memory, library, tasks and routines and proposes a few, each with
  what it would show and where its data comes from. Pick one and it builds it.
- Or start from an example on the Apps page (*Stock prices*, *Tasks at a glance*).
- To change an app: open it → *Change in the chat*, and say what you want different. If it breaks, the app shows the
  error at the top: copy it into the chat.
- Making or changing an app is code: the assistant uses your **deep** model for it (if you set one), as small models
  often get it wrong.

## What an app can show

Key figures (with a change arrow and a small trend line), line and bar charts (stacked too), pies and donuts,
gauges, ranked bars, progress toward goals, a calendar heatmap, a timeline, badges, tabs and tables. Tables can be
downloaded as CSV and charts as PNG. Links in an app open in Talixio (a library file, the tasks…); a web address
asks you first.

Sources an app can declare:

| Source | What it reads |
|---|---|
| Web | a fixed address (only a few words of it may change, like a share symbol) |
| File / Folder | a library file, or the files of a folder (handy with a routine that saves results there) |
| Search | a search in the library |
| Tasks, Calendar, Contacts | those of Talixio |
| Script | one of your saved scripts |
| Connector | one tool of a connector, **only if you marked it *only reading*** in Settings → Connectors |

## Why it is safe

An app is not free code. The assistant writes it with a small kit (charts, tables, figures, its own small store), and:

- it runs in a sealed frame: no network, no cookies, no access to the rest of the app or your session;
- it gets data only from the **sources it declares**: a fixed web address, a library file or folder, a library
  search, the tasks of its space, your calendar, your contacts, one of your saved scripts, or a connector tool you
  marked *only reading*. Nothing else;
- **every app the assistant makes or changes waits for your approval.** Open it: you see the sources it reads (and
  the code if you like), then *Approve*. Until then it gets no data;
- an app belongs to a space and follows its privacy level: apps of a Secret space are not shown elsewhere and never
  read the web. An app of a Confidential space, or one that reads a Confidential file or folder, never reaches the
  web or a connector either: what it reads stays in the app. Script arguments may not start with `-`.

A connector tool can do things (send, delete, pay), and what the connector says about itself is not trusted: an app
may only call a tool *you* marked as only reading, with the arguments fixed when you approved it.

## What an app cannot do

Write anything (tasks, files, mail, connectors), use the network freely, run code on the server, see your keys or
settings, read another space, or be shared publicly. These are deliberate. The plans are in the project's roadmap.

Web and connector data is cached (30 minutes by default) and limited to 60 fetches an hour per app. Delete an app from its page.
