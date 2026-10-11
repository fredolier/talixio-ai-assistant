# Updating Talixio

## Simple install
- **From the app**: Settings → *Updates* shows new versions (checked every 6 hours),
  with *Update now* (backup, `git pull`, reinstall, restart) and
  *Back to the previous version*. Automatic updates are turned on in Settings.
- **By hand**: in the repository folder, `git pull && sudo ./install.sh` (settings and data are kept;
  add the same `--with-…` options as the first time if you want to keep them up to date).
- Open apps show "A new version of Talixio is ready. – Reload".

## Server for several people (Docker)
- Admin page → **Updates**: the image is rebuilt from the repository, then each
  assistant is moved to it one after the other. If one does not start, it goes back to the previous version
  and the update stops. *Back to the previous version* undoes the last update.
- The tab shows whether a newer version exists on the branch, and what changes. At the end of the
  update, the admin page and its agent also move to the new version (since 0.19.6):
  reload the page a few seconds later.
- The image is built in two stages. The **dependencies** (system packages, Chromium, LibreOffice,
  whisper.cpp, signal-cli…) are a separate image, `talixio-base`, rebuilt only when
  `deploy/docker/base.Dockerfile` changes (several minutes). The **application** sits on top of it: an ordinary
  update only copies the code and takes a few seconds, plus the restart of the assistants.
- To rebuild the dependencies too (latest security updates for the packages, or a
  failed download): `sudo deploy/docker/install-stack.sh --rebuild-base`.
- From the command line: `git pull`, then `sudo deploy/docker/install-stack.sh` (no other option needed:
  the stack settings are kept in `deploy/docker/.env`).
- Private GitHub repository: on GitHub, create a read-only token limited to the repository (*fine-grained token*,
  permission *Contents: Read*), add the line `GIT_TOKEN=the_token` to `deploy/docker/.env`, then run
  `sudo deploy/docker/install-stack.sh` again. To cut off access, revoke the token on GitHub.

## What changed
The **/FAQ/English/What's new** folder has one page per version (taken from the
changelog). The installed version is shown at the bottom of the menu
and in Settings → *Updates*.

## The app is stuck after an update?
Reload the page (or close and reopen the app). Since 0.12.1, scripts are served from an address
that includes the version: a page can no longer run with scripts from another version.
