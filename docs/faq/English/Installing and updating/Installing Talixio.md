# Installing Talixio (one person, one server)

On a Debian 12+ or Ubuntu 24.04+ VM:
```bash
git clone https://github.com/fredolier/talixio-ai-assistant.git
cd talixio-ai-assistant
sudo ./install.sh
```
The script installs PHP, creates the `talixio` system user, copies the app to `/opt/talixio`, writes
`/etc/talixio/talixio.env` (with a random password) and starts two services:
`talixio-web` (the app on 127.0.0.1:8080) and `talixio-worker` (Telegram, routines, background tasks).
It starts in "echo" mode (no model) so you can check the install.

## The HTTPS address
- `--domain assistant.example.com` installs Caddy on the VM (DNS pointing to the VM, ports 80 and 443 open).
- `--bind 10.0.0.5:8080` when your proxy runs on another machine; in its Caddyfile:
  `assistant.example.com { reverse_proxy 10.0.0.5:8080 }`. Open port 8080 to the proxy only.

## Next steps
- Open the app, sign in with the password from `talixio.env`, then set everything up in **Settings**.
- *Run checks* tests the connections and each model tier (like `sudo talixio doctor`).
- Turn on **two-step sign-in** (Settings).
- Forgotten password: `sudo talixio password`. Lost phone: `sudo talixio totp off`.

Data is stored in `/var/lib/talixio`. See also *Optional modules* and *Choosing the model*.
