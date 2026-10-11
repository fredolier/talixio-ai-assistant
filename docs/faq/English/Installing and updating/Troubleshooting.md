# Troubleshooting

## First step
Settings → *Run checks* (or `sudo talixio doctor`, under Docker
`docker exec talixio-<you> talixio doctor`): each line in red tells you what is wrong.

## "No AI model is set up yet"
The assistant has no API key and no Claude sign-in. A box in the chat offers
*Sign in with Claude* or *Use an API key*. After a move to Docker, the import of the settings
may have failed: see *Administration / Switching to multi-user*.

## My documents no longer show up or no longer open after a move
Since 0.16, file paths are updated automatically at startup when the data folder
has changed (simple install → Docker, transfer). If a document still cannot be found, check
that it is in the volume (`docker exec talixio-<you> ls /data/files`).

## "Worker offline"
Routines, Telegram and background tasks are stopped. Simple install:
`systemctl status talixio-worker` and `journalctl -u talixio-worker -n 50`. Docker: `docker logs talixio-<you>`.

## The app no longer responds or stays on an old version
Reload, or close and reopen the app. If nothing helps: in the browser, site settings → clear data.

## I can no longer sign in
- Forgotten password: `sudo talixio password` (Docker: admin page → *New password*).
- Lost phone (two-step sign-in): `sudo talixio totp off` (Docker: *Turn off two-step sign-in (lost phone)*).
- After 5 failed attempts in 15 minutes, the address is blocked for a while.

## Documents stay on "reading…"
Reading happens in the background, in the worker: check that it is running. For an "unreadable" file:
open it and choose *Read again*.

## Local transcription fails
- "not enough memory": under Docker, give the person 2 GB (admin page → limits),
  or choose the `small` model.
- "model file not found": run `sudo ./install.sh --with-whisper` again (or `install-stack.sh` under Docker).

## An email or Signal channel does not answer
**Channels** page: the last error of each channel is shown there. Check that the sender is on the allowed list.
