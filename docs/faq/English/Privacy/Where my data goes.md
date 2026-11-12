# Where my data goes

The **Privacy** page shows:
- the overall level: **Local** (nothing leaves), **Business** (only services under business terms,
  with no training on your data) or **Personal**;
- each service in use, with what it receives, what it may do with it, and a button to fix it when possible;
- security: HTTPS, two-step sign-in, backups;
- the **journal** of every request to the outside (service, purpose, size, space; never the content),
  exportable as CSV, kept for 180 days;
- what the assistant did in the conversation (its tools and their results, in *Activity*) is kept 90 days
  (Settings → Budget, *Keep the log of the assistant's actions*); messages exchanged with other assistants and
  between agents are removed some months after they were delivered; costs stay, summed per day after 90 days;
- a pre-filled **Record of processing (GDPR)**, to download and complete.

To reach the Business level, you usually need:
- a Gemini key on a paid plan (or local transcription);
- notifications without content if you use Telegram;
- a password on share links.

To keep everything inside Europe: see *Staying in Europe*.

On a shared server (Docker), each person has their own data; the server administrator
can technically access it.

## Saved secrets are encrypted
Your API keys, passwords (email, calendar), sign-in tokens (Google, Microsoft, Spotify, connectors), the two-step
sign-in secret and the secrets of AI accounts and paired assistants are kept **encrypted** in the data folder.
The key is not in that folder: on a simple install it is `/etc/talixio/secret.key` (made by the installer); on a
shared server (Docker) each assistant gets its own, given by the server. A copy of the data folder alone (a backup,
a disk) does not give your secrets.
- **Back up the key apart from the data** (a password manager, a USB key). Without it, a backup restored on
  another server comes back without its secrets: you enter them again (the rest is all there).
- *Settings → Run checks* says whether secrets are encrypted, and how many cannot be opened with this key.
- If the key is missing or wrong, nothing is erased: put the right key back and everything opens again.
- Not encrypted: what other parts keep themselves (Claude Code's own sign-in, the multi-user admin page's own
  files), and the password, kept only as a hash anyway.
