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

## Several assistants for one person, one sign-in
A person can have more than one assistant: a work one and a private one, for example, each with its
own data, keys and provider. Talixio then has two levels: the **assistant** (a separate container,
nothing shared) and, inside it, its **spaces** (one memory and library, with the rules of their
confidentiality level).
- On the admin page: the person's assistant → Actions → *Their other assistants (one sign-in)…*,
  then tick the assistants it opens. A link works both ways, and the affected assistants restart.
- In the app, the assistant's name (top left, or in *More* on a phone) lists the others. Choosing
  one opens it in the same tab, already signed in. With the menu folded, the round badge at the top
  opens the same list.
- Each assistant has a coloured badge with its initials, taken from its id (`alex-acme` → AA): the
  same colour and letters on every assistant, so you can tell at a glance which one you are in.
  The list shows each assistant's name and id, the current one first.
- The names in that list come from the admin page, and are also each assistant's own name (in
  emails and notifications): its *Name* in Settings shows it, read-only. To change one, choose **Name and email…** in its *Actions* menu on the admin page
  (`Alex — Work`, `Alex — Private`): that assistant and the ones linked to it restart, a few
  seconds each, to show the new name.
- The browser carries a ticket signed for that assistant only, valid once and for one minute. The
  assistants never talk to each other.
- The sign-in to the first assistant counts: the second one does not ask again for its password or
  its authenticator code. Each assistant keeps its own password, which still works on its own.
- Removing a link changes the keys of the assistants concerned: tickets made before no longer work.

## Good to know
- Each assistant is limited (1 GB and 1.5 processors by default); Ollama takes up to 1.5 GB and half the processors; unticked in the admin settings, it is stopped.
- Local transcription (large model) needs 2 GB for the person.
- The administrator can technically access the data volumes.
