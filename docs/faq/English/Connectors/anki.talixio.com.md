# Connecting anki.talixio.com

To let the assistant read and manage your tasks in **anki.talixio.com** ("what tasks do I have open in
anki?", "add to anki: call the notary back"), you install anki's MCP server as a connector.
Everything is done from the app, without an administrator.

## What you need
- The **`mcp/`** folder of anki's MCP server (it contains `package.json` and `src/server.js`).
- An anki account. **An account dedicated to the assistant is better**: its password is kept in
  the assistant (see below).

## Installing
1. On your computer, compress the `mcp/` folder into a `.zip` (right-click → Compress / Send to →
   Compressed folder). The `.zip` can contain the `mcp/` folder itself or directly its contents.
2. In the assistant, **Library** → **/Connectors** folder (create it if needed) → *Upload* the `.zip`.
3. Open the file → **Install as a connector**.
4. Fill in:

   | Field | Value |
   |---|---|
   | Name | `anki` |
   | `TALIXIO_URL` | `https://anki.talixio.com` (**without** `/api` at the end) |
   | `TALIXIO_EMAIL` | the anki account's email address |
   | `TALIXIO_PASSWORD` | its password |

5. **Install**. After a minute at most (installing dependencies), the window shows
   "Ready: n tool(s) from “anki”". The tools appear in Settings → Connectors, under `anki`.

## Using it
- Just ask: "What tasks do I have open in anki?".
- The assistant also has **its own task board** (Tasks page). Say "in anki" when it is not
  obvious: otherwise it may use its own board.
- Its tools **ask for your approval** in the app until you mark the reading ones (list,
  search) in Settings → Connectors. Once you trust it, add `"trust": "all"` to the `anki` entry in
  Settings → Connectors → *Configuration*.
- It also works from the phone, Telegram or Signal: it is the assistant that talks to anki.

## If it does not work
The error message shows in the install window and in the `anki` card in Settings → Connectors.

| Message | What to do |
|---|---|
| connection refused, 401, credentials | check the email address and the password; reinstall (a password left empty keeps the old one) |
| 404, page not found | `TALIXIO_URL` must be `https://anki.talixio.com`, with no `/api` and no trailing `/` |
| `installing dependencies` failed | the assistant could not reach npm: try again later, or tell the administrator (the server's Internet access) |
| "No server found" | the `.zip` does not contain the right folder: it must be the one where `package.json` is |

*Check again* (Settings → Connectors) restarts the server and reloads its tools.

## Updating, removing
- **New version** of the MCP server: upload the new `.zip` and reinstall under the name `anki`.
  Leave the password empty to keep the current one.
- **Change the password**: reinstall with the new one, or edit `TALIXIO_PASSWORD` in
  *Configuration*.
- **Remove**: delete the `anki` entry in *Configuration*, then *Save and connect*.

## Good to know
- The address, account and password are kept in `data/mcp.json`, in your assistant: they are
  part of its backups and of any transfer to another server.
- anki's MCP server runs inside your assistant; it only talks to anki.talixio.com.

See also: *Connectors / Installing a connector*.
