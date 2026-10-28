# Talixio AI assistant — working notes for Claude

A personal/organisational AI assistant. **PHP backend** (`src/*.php`) with a
**vanilla-JS single-page frontend** (`web/public/app.{html,js,css}`). No build
step for the frontend — the files are served as-is. UI language is **French**
(English source strings translated at runtime).

## Layout

- `src/*.php` — backend. `Web.php` is the HTTP router (big `match` on
  `$path`/`$m` returning arrays that become JSON). `App.php` wires services
  (`skills()`, `assessor()`, `director()`, `jobs()`, `library()`, `tools`, …).
- `web/public/app.js` — the whole SPA. `api(path,opts)` / `post(path,body)`
  helpers; `api()` throws `Error` with the server's `{error}` message on non-2xx.
  Views are wired through a `VIEWS` map; each view has a loader function.
- `web/public/app.css` — all styles. Tokenised colours; light/dark.
- `web/public/i18n/fr.js` — the French dictionary (`"English": "Français"`).
- `docs/design/*.md` — design specs (e.g. `pilotage.md`). Read the relevant one
  before changing a feature; it records deliberate divergences from mockups.
- `tests/` — see below.

## Running tests (always before you push)

- **PHP suite:** `php tests/run.php` — prints `all passed`. **Slow (minutes)**;
  run it in the background and wait. Covers routes, version/changelog
  consistency (`run.php` ~L397/400), and i18n dictionary validity (~L413).
- **Browser suite:** `node tests/browser/run.mjs` — prints `all passed (N)`.
  Flows live in `tests/browser/flows.mjs`. Harness boots `php -S` with
  `TALIXIO_PROVIDER=echo`, `TALIXIO_WEB_PASSWORD=pw`; logs in with password `pw`.
- Ad-hoc Playwright: `require('/opt/node-tools/node_modules/playwright')`,
  `chromium.launch({ executablePath:'/opt/pw-browsers/chromium' })`. Put scripts
  in the scratchpad.
- Lint quickly: `php -l <file>`, `node --check <file.js>`.

## Conventions / gotchas

- **Versioning:** `VERSION` = `^\d+\.\d+\.\d+$` with a trailing `\n`. Each
  delivery bumps it and adds a `CHANGELOG.md` entry `## <version> (<date>)` —
  the space after the version is required by the test. History is forward-only
  (new patch/minor versions, never amend past ones). Feature → minor bump,
  fix → patch.
- **i18n:** every user-facing string goes through `t('English')`. When you add
  one, add `"English": "Français"` to `web/public/i18n/fr.js` with the **exact**
  English key; `{placeholders}` must match on both sides (a test checks this).
  Use `class="no-i18n"` on dynamic/proper-noun text.
- **CSS specificity trap (seen repeatedly):** menus carry a generic class
  (`.space-menu`) plus a specific positioning class (`.account-menu`/`.more-menu`).
  The generic base rule sits later in the file and overrides the specific one at
  equal specificity. Fix by raising the specific rule's specificity (prefix with
  `.rail`, etc.), not by reordering.
- Reuse existing class names carefully — `.prole-head` is the role *card's* top
  row; don't reuse it for unrelated containers.

## Pilotage (`docs/design/pilotage.md`) — the director's cockpit

`#view-pilotage`, 4 tabs (`.ptab[data-ptab]` → `.pilot-panel[data-ppanel]`):
**kb, agents (pilot), roles, chantiers**. Loaders: `loadPilotage`, `pilotKb`,
`pilotAgents`, `pilotRoles`, `pilotChantiers`, `pilotBadges`.

- **Deliberate divergences from the concept mockup** (`scratchpad/fleet-ui-mockups.html`;
  not in the repo): KB tab is a *library-stats glance*, not a hub-resource list;
  Rôles is a *grouped card grid* (`.pspec` headers + `.prole-grid`, each
  `proleCard` with ring/metrics/sparkline/specialization editor/actions), not the
  mockup's SVG ring-cluster; Chantiers launch from the chat composer.
- **Roles API** (`Web.php`): `GET /api/roles` (roles + overlaps + archived);
  `POST /api/roles` (create → `role_save`, added 1.6.0); and per-role POSTs
  `evaluate / publish / improve / specialization / archive / delete`. Protected
  roles (the `assessor`, role #0) expose no lifecycle actions.
- **How roles are created:** conversationally via the `role_save` tool
  (`src/Tools.php`, runs a duplicate check), installed from the store
  (`role_install`), seeded by the director/fleet, or from the Rôles tab's
  **+ Nouveau rôle** form (`roleNew()` → `POST /api/roles`, since 1.6.0).

## Workflow rules for this repo

- Work on the branch the task assigns; **never** push elsewhere without
  permission. **Do not open a PR unless explicitly asked.**
- End commit messages with the attribution footer the session specifies.
- The cloud container is ephemeral — only committed+pushed work survives.
