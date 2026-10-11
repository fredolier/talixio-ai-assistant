# The Docker stack behind an existing proxy

When another reverse proxy (for example Caddy on another machine) already handles HTTPS:
```bash
sudo deploy/docker/install-stack.sh --domain assistant.example.com --behind-proxy 8080
```
The stack then serves plain HTTP on port 8080 (no certificate on this server). On the proxy:
```
assistant.example.com {
    reverse_proxy <ip of this server>:8080
}
```
- With subdomains (`https://<person>.<domain>/`), also add `*.assistant.example.com` on the proxy.
- Open port 8080 to the proxy only: `ufw allow from <proxy-ip> to any port 8080`.
- `--behind-proxy 10.0.0.5:8080` listens on that address only.
- The setting is kept in `deploy/docker/.env`: no need to give it again on later runs.
