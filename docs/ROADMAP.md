# Roadmap

Features of Talixio, what is being built and what comes next. Kept up to date with each release.

## Library

- Done (0.28.65): **Recent changes** — who changed which file and when (you, the assistant, a routine, the network
  drive), a view for the last hour / today / 2 days / 7 days with "not opened since" dots, a grouped in-app
  notification (hourly, daily or off, folders can be muted), a morning-briefing line, and `changed` in an app's
  folder source.

## Apps

The rule: **an app never gets more power than its declaration, you approve that declaration, and every new ability
goes through the Talixio page or the server, never straight from the app.**

### Done (0.28.63)

- **A. More read sources**: library folder and search, calendar, contacts.
- **B. Looser parameters**: a source's `{param}` may hold accents, dashes, `/`, `@` and the like (still no markup).
- **C. Built-in visual components**: figures with change and trend, stacked bars, pie and donut, gauge, ranked bars,
  progress, heatmap, timeline, badges, tabs. Written in-house, no third-party library.
- **D. Export**: tables as CSV, charts as PNG, any data through `talixio.download()`, handed to the Talixio page.
- **Connector (MCP) source**: read-only, only for tools you marked *only reading* in Settings → Connectors (the
  server's own hint is not enough), tool and arguments fixed at approval, refused in Secret spaces, cached and
  limited per hour.

### Next

- **E. Write actions with confirmation**: an app declares an action (add a task, save a file); each use asks you in
  the Talixio page.
- **F. `talixio.ask()`**: an app asks the assistant a question, within its space.
- **G. Background watch**: an app's sources checked on a schedule, with a notification when a threshold is crossed.
- **H. Read-only snapshot sharing**: share a picture of an app's current state, never the live app.

### Kept closed

- Free network access from an app.
- Code running on the server outside the script sandbox.
- Access to keys, tokens and settings.
- Reading another space.
- Public sharing of a live app.
- Writing through a connector.
