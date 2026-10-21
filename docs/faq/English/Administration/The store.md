# The store (several people on one server)

When several people share one server, the admin page has a **Store** tab: apps and connectors that everyone on the
server can install. Each assistant sees the store in **Apps** (*From the store*) and in **Settings → Connectors**.

## Sending something to the store

- **An app**: open it and choose **Submit to the store** (also under **Share**), with a word for the admin if you like. What is
  sent is the same package as a shared app: its code, what it reads and how to install its connectors, never its data,
  saved state or keys.
- **A connector**: in Settings → Connectors, pick it under *From the store* and press **Send for review**. Only how to
  install it is sent (its GitHub repository, or its https address and header names), never your keys or header
  values. A connector installed from a `.zip` or a command cannot go to the store.

You see where it stands: waiting for the admin's review, in the store, or not accepted (with the admin's note).
Sending a changed app again sends a new version; once approved, it replaces the one in the store.

## Reviewing (admin)

In **Store**, each item waiting for review shows who sent it, their note and everything it contains (code, sources,
connectors) before you **Approve** or **Reject** it, with an optional note sent back. Nothing reaches the store before
you approve it. You can also publish an app from a `.talixio-app.json` file or a connector from its GitHub repository
or address yourself, and remove anything: people who installed it keep their copy.
**Add the connectors listed in the FAQ** fills the store with the whole *Connector list* in one click (only those
not already there); then remove the ones you do not want.

## Installing from the store

- **Apps → From the store**: **Install** adds the app to the current space. It still waits for your approval before it
  reads any data, like any other app. When a new version is published, **Update** brings it in (it waits for approval
  again); **Uninstall** removes it with its saved state.
- **Settings → Connectors → From the store**: **Install** adds the connector; you then sign in or fill in its settings
  with your own account. **Uninstall** removes it.

Apps of a Secret space never leave it, so they cannot be sent to the store.

## I don't see the store

The store needs a server run from the admin page. If the store section says it is not set up, the assistant was made by a version older than the store: update the server again, or choose **Rebuild the container** for that assistant on the admin page. From 0.28.73, the agent also repairs this by itself when it starts.
