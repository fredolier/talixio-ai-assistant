# Connectors (MCP)

A connector gives the assistant the tools of another service: GitHub, Notion, your smart home, another
application (for example a task manager)… Settings → **Connectors (MCP)**.

Connectors ready to install in one click: *Connectors / Connector list*.

## An online service
Paste its configuration in the *Configuration* field (the usual `mcpServers` format):
a `url`, with a token in `headers`, or the **Sign in** button for services that use OAuth
(Notion, Linear, Atlassian…).

## A connector published on GitHub
A "local" MCP server is a small program (Node.js or Python). If it is on GitHub, its address is enough:

1. Settings → Connectors → **Install a connector…**
2. Paste the repository's address (`https://github.com/owner/repo`, or
   `https://github.com/owner/repo/tree/main/folder` if the server is in a sub-folder) → **Fetch**.
   Talixio downloads the repository and keeps it as a `.zip` in **/Connecteurs**.
3. Check what it found (start file, settings it reads), fill in the settings, then *Install*.

You can also ask the assistant ("install the connector https://github.com/…"): it asks for your
approval, then installs it; if it reads settings (address, account, key…), it gives you the link to the
file to fill them in yourself (a password is not typed in the conversation).

Only **public** repositories can be fetched this way; for a private one, download it as a `.zip` and
follow the method below.

## Your own connector, from a .zip
For a server that is not on GitHub (or in a private repository):

1. Compress **its folder** into a `.zip` (the one that contains `package.json`, or `src/server.js`, `server.js`,
   `index.js`, `server.py`…).
2. Upload the `.zip` to the library, for example into **/Connectors**.
3. Open the file → **Install as a connector** (or Settings → Connectors → *Install a
   connector…*).
4. Give it a short name (`anki`) and fill in the settings it reads (address, account, password…):
   Talixio finds them in its code and its README.
5. *Install*: it is unpacked into your assistant, its Node dependencies installed (`npm install`),
   then started. Its tools appear (`mcp_anki_…`) and the assistant can use them from the next message.

- **Update**: upload the new `.zip` and reinstall under the same name. A password left
  empty keeps the old one.
- **Remove**: delete its entry in *Configuration*, then *Save and connect*.
- Every tool **asks for your approval** until you say otherwise. In Settings → Connectors, each
  connector is a card you unfold: it shows what the connector does, then a table of its tools with
  what each one does. The tools are grouped by what they seem to do (*seem to only read*, *not sure*,
  *can change or send things*); this guess comes from the connector's hints and the tool's name, so
  check the description. Tick a tool to let it run without asking, also in background tasks; the
  box on a group row ticks the whole group (only the rows the filter shows). Each tick is saved at
  once. Or add `"trust": "all"` to its entry to trust all its tools.
- The settings (password included) are kept in `data/mcp.json`, in your assistant: they are
  part of backups and transfers. A dedicated account is better.
- The connector runs in a sandbox when bubblewrap is installed (always under Docker): it sees its own
  folder, the system programs and the network, not your documents, mail or keys. Still, it can reach
  what its settings give it: only install code whose origin you know.
- A TypeScript project that needs building is built (`npm run build`) if the package has no ready-made file.
- Python: only servers with no dependencies to install are supported for now.

## What not to do
- No `git clone`, `npm install` or `npm start` to run yourself, and no `localhost` address to declare:
  Talixio installs the connector and starts it itself, each time it is used.
- No need to restart the assistant: the new tools are there from the next message.
- The *Configuration* is strict JSON: no comments (`//`).
- An ordinary web API (REST) is not an MCP server: it needs an MCP server that uses it.
- A long result (a converted page, a big export) is saved whole as a file in your library, in the space's
  *Notes*; ask the assistant to move or rename it where you want. The chat only shows the start, folded.

Step-by-step examples: *Connectors / anki.talixio.com* (a connector to install) and *Connectors / Home Assistant* (a remote service, by its address).

On a server shared by several people, connectors the admin approved are in Settings → Connectors → *From the store*,
to install with one button (see *Administration / The store*).
