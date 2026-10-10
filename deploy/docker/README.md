# Talixio for several people (Docker)

Each person gets their own assistant: their own container, data, settings, API keys (their own
costs) and password. They don't see each other. You manage the assistants from a web page
(`https://<domain>/admin/`) without SSH, and never see what is inside them.

```
Internet ──► Caddy (HTTPS) ── /admin/ ──► admin page ──► agent ──► Docker
                │  /alice/  ──► talixio-alice   (own network, own volume)
                │  /bob/    ──► talixio-bob     (own network, own volume)
                └─ :11434 (embeddings only) ──► Ollama, shared
```

- **Caddy** is the only door (ports 80/443, certificates). It also lets assistants reach Ollama,
  for embeddings only (Ollama has no password: nothing else gets through).
- **Each assistant has its own network**, shared only with Caddy: assistants can't reach each
  other, the admin page, the agent or Ollama.
- **The agent** is the only part with Docker access, and it only knows fixed actions (create,
  start, stop, remove an assistant, reset a password, update, reload Caddy). It builds every
  container from one locked template (`src/Fleet.php`): no privileges, read-only system, memory and
  CPU limits, its own volume and network. The admin page can't pass it any Docker option.
- **The admin page** talks to the agent only. Password and authenticator code are both required;
  optional list of allowed addresses; every action is logged (Activity tab).

## Install

On the server (Ubuntu 24.04 or Debian 12+, 4 GB of memory or more; 8 GB is comfortable for 4
people with Ollama), with a DNS record of the domain pointing to it and ports 80/443 open:

```bash
git clone <this repository> && cd talixio-ai-assistant
sudo deploy/docker/install-stack.sh --domain assistant.example.com --email you@example.com
```

It installs Docker if needed, writes `deploy/docker/.env` (private: it holds the agent's token),
builds the image (a few minutes; ~2.3 GB), starts the stack and prints the admin page's address
and a one-time **setup code**. Open the page, enter the code, choose the admin password and scan
the authenticator code. Then **People → New person**: an identifier (`alice` gives
`https://assistant.example.com/alice/`), a name; you get a first password to send them. They add
their own API keys in their Settings.

### Behind a reverse proxy you already have

When another machine already does HTTPS (a Caddy, nginx or Traefik in front of this server), the
stack doesn't take ports 80/443 nor get certificates: its Caddy serves plain HTTP on one port.

```bash
sudo deploy/docker/install-stack.sh --domain assistant.example.com --behind-proxy 8080
```

On the proxy in front: `assistant.example.com { reverse_proxy <this server>:8080 }` (for the
subdomain mode, also `*.assistant.example.com` with a wildcard certificate). Allow that port only
from the proxy (`ufw allow from <proxy-ip> to any port 8080`).

### Moving an existing installation (made with install.sh)

```bash
sudo deploy/docker/install-stack.sh --domain assistant.example.com --email you@example.com --migrate fred --name "Frédéric"
```

The data of `/var/lib/talixio` is copied into the assistant `fred` (the original stays where it
is), the settings of `/etc/talixio/talixio.env` are imported, the password stays the same. The old
services (talixio-web, talixio-worker, Caddy, Ollama) are stopped and disabled, not removed. The
address becomes `https://assistant.example.com/fred/`: open it once and install the app again on
your phone. A transcription model already installed by `install.sh --with-whisper` is reused, and the
person gets 2 GB and 2 processors so that it fits.

### Transcription on the server (whisper.cpp)

The image includes whisper.cpp. The model is in the volume `talixio-whisper`, shared read-only by every
assistant: `install-stack.sh` fills it (`--whisper-model large-v3-turbo-q5_0` by default, `small`, `medium`,
or `none`). With Transcription on "auto", an assistant without a Gemini key transcribes on the server; a
Confidential space always does. The large model needs about 1 GB while it runs: give the person 2 GB
(admin page → People → limits), or choose `small`. A one-hour meeting takes 30 to 60 minutes on 2 processors.

### Signal

signal-cli is in the image (x86_64 servers). Its registration lives in the person's volume
(`/data/signal`). Put the assistant's number and yours in Settings → Signal, then:
```bash
docker exec -it talixio-fred talixio signal register        # captcha asked: see the main README (Signal)
docker exec -it talixio-fred talixio signal verify 123456   # the code received by SMS
docker exec -it talixio-fred talixio signal updateProfile --given-name Talixio
docker restart talixio-fred
```

### From another server

Admin page → People → **From another server**: the old assistant's address, its transfer code (made in
that assistant: Settings → Move to another server), the identifier here. Test copies arrive paused and
can be repeated (an existing identifier gets the fresh copy); tick *Final move* for the last one, which
pauses the old assistant. Progress shows in the Updates tab.

## Updates

Admin page → **Updates**: the image is built from the git repository (`GIT_URL` in `.env`; for a
private repository, set a read-only token in `GIT_TOKEN` there and run `install-stack.sh` again),
then each assistant is moved to it one by one. If one doesn't start, it goes back to the previous
version and the update stops. **Back to the previous version** undoes the last update.
Running `install-stack.sh` again from an up-to-date clone does the same from the command line.

## Addresses

Settings → Addresses: `assistant.example.com/<person>/` (default, nothing to do in DNS) or
`<person>.assistant.example.com` (a DNS record `*.assistant.example.com` to this server; Caddy gets
a certificate for each person at their first visit, and only for people that exist).

## Good to know

- Memory: ~30-50 MB per assistant at rest, more while the browser, LibreOffice or a transcription
  runs (limit 1 GB each by default, changeable per person). Ollama up to 1.5 GB. The Ollama image
  takes ~9 GB of disk (it ships GPU libraries).
- The assistants keep their own sandboxes (shell, browser) inside the containers, thanks to
  `seccomp-sandbox.json` (Docker's default profile plus the namespace calls) and, on Ubuntu, no
  AppArmor profile for those containers (Settings → Sandboxes).
- Backups: each assistant backs itself up every night into its own volume (`talixio-<person>`);
  copy the Docker volumes elsewhere to survive losing the server. Admin → Actions → Back up now.
- Claude Code (subscription) in an assistant: the person runs `claude setup-token` on their own
  computer and pastes the token in their Settings.
- You, as administrator of the server, can technically read the volumes: tell the people.
- Commands: `docker compose -f deploy/docker/compose.yml ps`, `docker logs -f talixio-<person>`,
  `docker exec talixio-<person> php /opt/talixio/bin/talixio doctor`.

## One assistant without the stack

```bash
docker build -t talixio-base:current - < deploy/docker/base.Dockerfile   # the dependencies (long, once)
docker build -t talixio .
docker run -d --name talixio-fred --restart unless-stopped -v talixio-fred:/data \
  -e TALIXIO_WEB_PASSWORD='first password' -e TALIXIO_BASE_PATH=fred \
  --memory 1g --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
  --security-opt seccomp=deploy/docker/seccomp-sandbox.json --security-opt systempaths=unconfined \
  -p 127.0.0.1:8081:8080 talixio
```

Behind a proxy that strips the prefix (Caddy: `redir /fred /fred/` and
`handle_path /fred/* { reverse_proxy 127.0.0.1:8081 }`).
