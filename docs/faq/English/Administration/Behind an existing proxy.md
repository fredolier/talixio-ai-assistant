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

## Which addresses are trusted
The assistant reads the visitor's address and https from the proxy's `X-Forwarded-For` and
`X-Forwarded-Proto` headers only when the request comes from a trusted proxy; otherwise anyone could
forge them to dodge the sign-in lockout. `TALIXIO_TRUSTED_PROXIES` lists them: `loopback` (the default,
a proxy on the same machine), `private` (any private address: the Docker stack sets it, since only its
Caddy reaches the assistants), or addresses and ranges such as `10.0.0.5, 192.168.1.0/24`.
For a simple install behind a proxy on another machine, add the proxy's address to
`/etc/talixio/talixio.env`, e.g. `TALIXIO_TRUSTED_PROXIES=10.0.0.5`, then `sudo systemctl restart talixio-web`.

## Chat by email
Only mail your provider verified is trusted (the topmost `Authentication-Results` header). Set
`TALIXIO_EMAIL_AUTHSERV_ID` to your provider's server name, the first word of that header
(e.g. `mx.example.com`), so that no other server's verdict is believed.
