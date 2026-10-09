# Talixio in Docker

One container per person: the web app and the worker, with that person's data in a volume
(`/data`: database, library, memory, settings and API keys). The code is the same for everyone;
updating means a new image, the volumes stay.

```bash
docker build -t talixio .                       # options: --build-arg WITH_BROWSER=0 WITH_OFFICE=0 WITH_SHELL=0 WITH_NODE=0 WITH_CLAUDE_CODE=0
docker run -d --name talixio-fred --restart unless-stopped \
  -v talixio-fred:/data \
  -e TALIXIO_WEB_PASSWORD='first password' \
  -e TALIXIO_BASE_PATH=fred \                     # served at https://your.domain/fred/ (empty = at the root)
  --memory 1g --cpus 1.5 \
  --read-only --tmpfs /tmp:rw,size=512m \
  --cap-drop ALL --security-opt no-new-privileges \
  --security-opt seccomp=deploy/docker/seccomp-sandbox.json \
  --security-opt systempaths=unconfined \
  -p 127.0.0.1:8081:8080 talixio
```

Behind a reverse proxy that strips the prefix (Caddy: `handle_path /fred/* { reverse_proxy 127.0.0.1:8081 }`,
plus `redir /fred /fred/`). Several people: the compose file and the admin page (coming next) do all this.

## Why these options

- `--read-only`, `--tmpfs /tmp`, `--cap-drop ALL`, `no-new-privileges`, a non-root user (uid 1000):
  the container can only write its volume and a temporary folder, and has no privileges.
- **`seccomp-sandbox.json`**: Docker's default seccomp profile plus `clone`, `clone3`, `unshare`,
  `mount`, `umount2`, `pivot_root`, `setns`. The shell sandbox (bubblewrap) and Chromium build
  their own namespaces inside the container; Docker's default profile forbids that. Without it the
  shell tool stays off and Chromium would need its sandbox disabled (not recommended).
- **`systempaths=unconfined`**: bubblewrap mounts a fresh `/proc` for each command, which the kernel
  refuses while Docker masks parts of `/proc`. The container still runs without privileges.
- **AppArmor (Ubuntu hosts)**: Docker's `docker-default` profile forbids `mount`, and Ubuntu 24.04
  restricts user namespaces. If the doctor says the sandbox cannot start, add
  `--security-opt apparmor=unconfined` (the seccomp profile and the missing privileges still apply).
- Tested: shell sandbox (Python, pandas) inside a locked-down container, LibreOffice previews with a
  read-only root, data kept when the container is recreated, ~30 MB of memory at rest.

`docker exec talixio-fred php /opt/talixio/bin/talixio doctor` checks everything.
Updates from the app are off in a container (`TALIXIO_MANAGED=1`): the administrator updates the image.
Claude Code (subscription provider): log in once with `docker exec -it talixio-fred claude setup-token`
and paste the token in Settings, or set `CLAUDE_CODE_OAUTH_TOKEN`.
