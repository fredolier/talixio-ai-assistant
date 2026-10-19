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

## Why it is safe

An app is not free code. The assistant writes it with a small kit (charts, tables, figures, its own small store), and:

- it runs in a sealed frame: no network, no cookies, no access to the rest of the app or your session;
- it gets data only from the **sources it declares**: a fixed web address, a library file, the tasks of its space,
  or one of your saved scripts. Nothing else;
- **every app the assistant makes or changes waits for your approval.** Open it: you see the sources it reads (and
  the code if you like), then *Approve*. Until then it gets no data;
- an app belongs to a space and follows its privacy level: apps of a Secret space are not shown elsewhere and never
  read the web.

Web data is cached (30 minutes by default) and limited to 60 fetches an hour per app. Delete an app from its page.
