# Moving from a simple install to Docker

Your install made with `install.sh` becomes one person's assistant in the Docker stack:
```bash
cd /opt/talixio-ai-assistant && git pull     # the folder of your clone
sudo deploy/docker/install-stack.sh --domain assistant.example.com --migrate fred --name "Frédéric"
```
- The data in `/var/lib/talixio` is **copied** into the `fred` assistant (the original stays in place),
  the settings in `/etc/talixio/talixio.env` are imported, and the password stays the same.
- The old services (talixio-web, talixio-worker, Caddy, Ollama) are stopped and disabled, not removed.
- The whisper model already installed is reused; the person gets 2 GB and 2 processors.
- The new address is `https://assistant.example.com/fred/`: open it and reinstall the app on the
  phone; set it in Settings → *Public address*. Share links already sent
  use the old address.

**Do not run** `--migrate` again once the stack is running: it would copy the old data back
over it. To rebuild or update, run `sudo deploy/docker/install-stack.sh` with no option.

## Going back
```bash
docker compose -f deploy/docker/compose.yml down
sudo systemctl enable --now talixio-web talixio-worker   # (and caddy, ollama if they were installed)
```
Anything done in Docker in the meantime stays in the `talixio-fred` volume.
