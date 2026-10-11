# Connecting Home Assistant

With this connector, the assistant controls your smart home the way Home Assistant's voice assistant
(Assist) does: turn on, turn off, set a thermostat, read a temperature, add to a shopping list…
It only sees the devices you have **exposed** to Assist.

Nothing to install on the Talixio side: Home Assistant has its own MCP server, and Talixio connects
to it directly by its address.

## What you need
- Home Assistant **2025.2 or later**.
- The Talixio server must be able to reach Home Assistant: on the same network
  (`http://192.168.1.10:8123`), through a VPN, or by its public address (Nabu Casa, proxy).
- A Home Assistant account for the assistant. The safest choice: a **dedicated, non-admin** account
  (see below).

## On the Home Assistant side
1. **Turn on the MCP server** (required, it is off by default): **Settings → Devices &
   services → Add integration** → *Model Context Protocol Server* (not plain *Model Context Protocol*,
   which works the other way round), then choose the *Assist* API. Without this integration,
   `/api/mcp` answers 404 and the Home Assistant log says nothing.
2. **Expose the devices**: Settings → Voice assistants → *Expose* tab. Tick what the
   assistant may see and control, and nothing else (not the garage door if you would rather not).
3. **A token**: signed in with the assistant's account, *Profile → Security → Long-lived access
   tokens → Create token*. Copy it right away: it will not be shown again.
4. With a **non-admin** account: *Model Context Protocol Server* integration → *Configure*,
   untick *Require an administrator account*. (Or keep the option and use the address
   `/api/mcp/assist`, open to all accounts.)

## On the Talixio side
Settings → **Connectors (MCP)** → *Configuration* field. Add a `homeassistant` entry next to
the ones already there, **inside** `"mcpServers"` (an entry placed after the brace that
closes `mcpServers` is ignored without any message):

```json
{
  "mcpServers": {
    "homeassistant": {
      "url": "http://192.168.1.10:8123/api/mcp",
      "headers": { "Authorization": "Bearer PASTE_THE_TOKEN_HERE" }
    }
  }
}
```

| Field | What to put |
|---|---|
| `url` | the Home Assistant address **followed by `/api/mcp`** (or `/api/mcp/assist`) |
| `Authorization` | `Bearer ` (with the space) then the long-lived token |

Save: Talixio lists the Home Assistant tools (`mcp_homeassistant_…`, for example
`HassTurnOn`, `HassTurnOff`, `GetLiveContext`). The assistant can use them from the next message.

## Using it
- "Turn on the living room light", "What's the temperature in the bedroom?",
  "Add milk to the shopping list".
- Commands that act **ask for your approval** in the app. Once you trust it, add
  `"trust": "all"` to the `homeassistant` entry: the assistant then acts without asking.
- It also works from Telegram or Signal. Routines and background tasks, however, do not use
  connectors: control your smart home from a conversation.

## If it does not work
| What you see | Likely cause |
|---|---|
| `cannot reach …` | Home Assistant cannot be reached from the server: address, port 8123, firewall, VPN |
| HTTP 401 | token badly copied, or `Bearer ` missing in front |
| HTTP 403 | non-admin account while the "Require an administrator account" option is ticked |
| HTTP 400 `Bad Request`, with no other text | Home Assistant refuses a request that came through a proxy (Caddy, Nginx…) it does not know; its log says *"A request from a reverse proxy was received from …"*. Use the local address (`http://IP:8123/api/mcp`), or declare this proxy in Home Assistant (see below) |
| HTTP 404, nothing in the Home Assistant log | the *Model Context Protocol Server* integration has not been added (step 1); otherwise `/api/mcp` is missing at the end of the address, or Home Assistant is too old |
| `homeassistant` does not appear in the list after saving | the entry is outside `"mcpServers"`: move it inside, next to the others |
| No devices, "I can't find the light" | the device is not exposed to Assist |

To test from the server (under Docker):
`docker exec talixio-fred curl -s -o /dev/null -w '%{http_code}\n' http://192.168.1.10:8123/api/`:
`401` means "reachable" (only the token is missing), an error or `000` means "not reachable".

To keep a public address (`https://ha.example.com`) served by a proxy, Home Assistant must
know that proxy. In recent versions, this is set in the interface: **Settings → System →
Network**, reverse proxy section. Turn on the use of the proxy headers and add its address
(the one shown in the Home Assistant log, for example `192.168.0.31`) to the trusted proxies.
Keep access through the local address (`http://IP:8123`) while you set this up: an undeclared proxy is
refused.

On an older version, without this setting in the interface, it goes in `configuration.yaml`
(a single `http:` section), then restart Home Assistant:
```yaml
http:
  use_x_forwarded_for: true
  trusted_proxies:
    - 192.168.0.31   # the proxy address, as shown in the Home Assistant log
```

## Notes
- The token is kept in `data/mcp.json`, in your assistant. To cut access, delete the token
  in Home Assistant (Profile → Security) or the entry in Talixio.
- A local network address (`192.168.…`, `10.…`, `….local`) counts as a **local** service for
  privacy: confidential spaces can use it. A public address (Nabu Casa…) does not.
- The *Sign in* button (OAuth) is not the right way here: Home Assistant does not register a
  client automatically. The long-lived token is the simplest.
