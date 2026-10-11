# Connectors (MCP)

A connector gives the assistant the tools of another service: GitHub, Notion, your smart home, another
application (for example a task manager)… Settings → **Connectors (MCP)**.

## An online service
Paste its configuration in the *Configuration* field (the usual `mcpServers` format):
a `url`, with a token in `headers`, or the **Sign in** button for services that use OAuth
(Notion, Linear, Atlassian…).

## Your own connector, without an administrator
A "local" MCP server is a small program (Node.js or Python). To install it yourself:

1. Compress **its folder** into a `.zip` (the one that contains `package.json`, or `src/server.js`, `server.js`,
   `index.js`, `server.py`…).
2. Upload the `.zip` to the library, for example into **/Connectors**.
3. Open the file → **Install as a connector** (or Settings → Connectors → *Install from the
   library…*).
4. Give it a short name (`anki`) and fill in the settings it reads (address, account, password…):
   Talixio finds them in its code and its README.
5. *Install*: it is unpacked into your assistant, its Node dependencies installed (`npm install`),
   then started. Its tools appear (`mcp_anki_…`) and the assistant can use them from the next message.

- **Update**: upload the new `.zip` and reinstall under the same name. A password left
  empty keeps the old one.
- **Remove**: delete its entry in *Configuration*, then *Save and connect*.
- Tools that change something **ask for your approval**, unless you add
  `"trust": "all"` to its entry.
- The settings (password included) are kept in `data/mcp.json`, in your assistant: they are
  part of backups and transfers. A dedicated account is better.
- The connector runs inside your assistant (under Docker, in your container) with the same rights as
  the assistant: only install code whose origin you know.
- A TypeScript project that needs building is built (`npm run build`) if the package has no ready-made file.
- Python: only servers with no dependencies to install are supported for now.

Step-by-step examples: *Connectors / anki.talixio.com* (a connector to install) and *Connectors / Home Assistant* (a remote service, by its address).
