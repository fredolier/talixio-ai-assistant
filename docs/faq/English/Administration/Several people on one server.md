# Several people on one server (Docker)

One assistant per person, each in its own container, with its own data, keys (and costs) and
password; a shared Ollama for search by meaning; an admin page with no need for SSH.

## Installing
```bash
sudo deploy/docker/install-stack.sh --domain assistant.example.com --email you@example.com
```
The script installs Docker, builds the image (several minutes, ~2.5 GB), starts Caddy (HTTPS),
Ollama, the agent and the admin page, then shows a **setup code**.

## The admin page (`https://<domain>/admin/`)
- First visit: the code, then your admin password and the authenticator app.
- **People**: *+ New person* (the address and a first password to pass on), start,
  stop, restart, check, back up, limits (memory, processors), new password,
  reset two-step sign-in, remove (with or without their data).
- *From another server*: bring in an existing assistant (see *Moving an assistant to another server*).
- **Updates**, **Settings** (domain, addresses as /person or as subdomains, default
  limits, Ollama, Google client, shared European provider), **Security** (allowed addresses, password), **Activity** (log).

## Is the server the right size?
At the top of the People tab, the **Server** panel shows:
- memory, processors (load over 1, 5 and 15 minutes) and disk (images, data, build cache);
- the **shared services** (Caddy, Ollama, agent, admin): whether they answer, how fast,
  their restarts and their memory;
- a **diagnosis** (Well sized, Tight, Undersized). It compares the promised limits
  (people, shared services and system) with the real memory, and checks processor load,
  swap and free disk. It also says how many more people you can add.

For each person: whether their assistant answers, the time of its last check, its restarts, and
whether it was stopped for lack of memory. If so, raise its limit (Actions → Memory and CPU…).

## Addresses
`https://<domain>/<person>/` by default, or `https://<person>.<domain>/` (this needs a wildcard DNS record).
With one address, every assistant and the admin page share one web origin, so the browser does not
keep them apart: someone who can run code in their own assistant could reach another person's session.
Choose **subdomains** unless everyone on the server trusts each other. Local connectors always run in
the sandbox on a shared server.

## Good to know
- Each assistant is limited (1 GB and 1.5 processors by default); Ollama takes up to 1.5 GB and half the processors; unticked in the admin settings, it is stopped.
- Local transcription (large model) needs 2 GB for the person.
- The administrator can technically access the data volumes.
