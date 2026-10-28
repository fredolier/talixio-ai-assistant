# Changelog

The version number is in `VERSION`; the app shows it at the bottom of the menu, in Settings → Updates
and with `talixio version`. Each delivery raises it and adds a line here.

## 1.11.2 (2026-10-28)

- **The pilote autonome stops spamming you every ten minutes.** Its keep-warm pings are meant to be silent, but an orchestrator that re-sends the same status line each tick (without the silent flag) made Talixio notify — and e-mail — you every ~10 minutes. The inbox now drops a report whose text is a verbatim repeat of the last one it notified, so a repeated keep-warm line notifies once instead of forever; genuinely new text still comes through, and the explicit `tick`/`silent` flags still work as before.

## 1.11.1 (2026-10-28)

- **The AKI.IO catalogue now lists all of its models, not just four.** The Modèles table was hand-seeded with only four AKI rows; it now carries AKI's full documented catalogue — nine LLMs (GPT-OSS 120B, Deepseek V4.1 Flash, GLM 5.3 754B, Mistral 4 119B, Gemma 4 26B, Qwen 3.8 27B, Kolibri 1 TL5, and the end-of-life Qwen 3.6 35B A3B and Apertus 70B) and four euro-priced image models (Flux.2 [klein], Qwen Image, Qwen Image Edit, Z-Image Turbo). Each row now carries its exact API model string, so routing uses the real id and a live refresh folds in cleanly instead of duplicating a seed row.
- **No more misleading "mis à jour le" date on the base catalogue.** The Modèles header only shows an update date once a live refresh has actually run; before that it reads "Catalogue de base — …" and points at "Actualiser maintenant" to fold in any models a provider lists beyond the seed.

## 1.11.0 (2026-10-28)

- **Connect a provider right on its card, in one step.** The Fournisseurs tab of Modèle et budget is now a plain connect surface: each provider card shows **Connect** (or **Se connecter** when it needs no key, with a **Créer un compte** link to sign up), you paste the key inline on the card, and its models appear in the Modèles tab straight away. The old "Tout passer en Europe" one-click button, the separate "Comptes d'IA" card, and the brain/"Modèle d'IA" card are gone from this tab — connecting an account is the only thing you do here, and the key path is the same uniform account-creation one for every provider (sovereign, Claude, OpenAI, Gemini, and the exotic ones through the advanced form). Choosing which connected model answers each usage, and the Europe lock, now live in the Modèles tab.
- **The Europe lock moved to the Modèles tab.** The lock that keeps everything inside Europe is now the Modèles-table toggle: turning it on both filters out non-compliant models/providers in the table and sets the server-side guard (new `POST /api/sovereign/lock`), so Privacy refuses any non-European AI or search service. It is a standalone guard — it no longer forces which provider answers, so you can lock to Europe without switching your main model.

## 1.10.1 (2026-10-28)

- **The fleet orchestrator's keep-warm tick no longer notifies you.** The orchestrator pings back roughly every ten minutes to stay warm and refresh its roster; until now every one of those pings raised a notification, so the fleet spammed the notifications inbox even when nothing had happened. The inbox endpoint now notifies only on a report that actually carries text, and never on one flagged as a silent/liveness tick (`"tick":true`, `"silent":true`, or a `kind` of tick/liveness/heartbeat/keepalive) — the roster and liveness still refresh, you just stop getting pinged for no news. The pairing instructions sent to the orchestrator now spell this out: a routine keep-warm tick with no news must omit `text` or set `"tick":true`.

## 1.10.0 (2026-10-28)

- **The « Modèle et budget » → Fournisseurs tab is rebuilt as a provider-card grid, matching the concept mockup.** Until now the tab just stacked the existing Europe form and AI-accounts card; the mockup's centrepiece — an at-a-glance grid of every provider — was missing. The tab now leads with a card per catalogue provider: its flag and name, a status light (connected or not), its country and billing, and three chips — trust level, kind (sovereign, business, consumer, local…) and how many models it offers — plus a dashed **« + Ajouter un fournisseur sur mesure »** card. Each card's button jumps straight to the real place you connect it: a sovereign/European provider pre-selects itself in the Europe form and focuses the key field; any other opens the AI-account form with its kind pre-chosen. The Europe switch and AI-accounts cards stay below the grid under a **« Détails de connexion »** heading — they hold the actual credentials, so nothing about connecting changes, it just gets a proper overview on top. No new trust edge: a model is still only ever reached through a credential source you configured. Fully translated to French; a browser test checks the grid has one card per provider plus the add card, and that the add card opens the account form.

## 1.9.1 (2026-10-28)

- **aki.io is settled as a first-class German sovereign provider across the model cockpit, and the design is written down.** This closes the « Modèle et budget » series. aki.io was already reachable end-to-end (a sovereign AI account, service `aki`, keeping processing in Germany by serving only its TL5-tagged models), seeded in the catalogue with four EU-priced models (GPT-OSS 120B, MiniMax M2.5 230B, Apertus 70B and the per-image € model Flux.2 [klein]), and satisfying the Europe (RGPD) lock at trust level 5. The gaps were polish, not capability: its catalogue flag now reads 🇩🇪 (Germany) like every other provider's national flag, with the GDPR signal carried by the trust-5 badge and the « Sovereign, no retention » kind rather than a duplicate 🇪🇺 that no other sovereign provider shows; and the whole cockpit — the three tabs, the catalogue shape and canonical model id, the global + per-space routing, the seed-plus-live refresh, the filters and the Europe lock, and aki.io's German-residency rule — is now documented in `docs/design/modele-budget.md`. A backend test pins aki.io as a German TL5 provider with EU-priced seed models (one billed per image in euros); a browser test confirms it is offered as a sovereign provider and that the provider filter narrows the Modèles table to its TL5 models.

## 1.9.0 (2026-10-28)

- **The model catalogue now refreshes itself: once a week, and on demand from a new « Actualiser maintenant » button on the Modèles tab, the assistant folds the connected providers' live model lists into the hand-kept seed.** Until now the Modèles table was a fixed seed; a new provider model (or a renamed one) only appeared after an edit to the code. The catalogue now calls each connected provider's `GET /models` (every provider here is OpenAI-compatible except Anthropic, whose first-party seed carries accurate prices and uses a different API, so it is left as-is), and folds in any id the seed doesn't already cover — classified into the right type (voice, image, vision or LLM) by its name, with embeddings, moderation and reranker models dropped since no usage runs on them. A folded-in model is marked as an estimate (no confirmed price or rank yet) and shows as available, so it can be assigned straight away. Each provider is fetched independently, so one unreachable key can't blank the others; the fetch is tagged as a model call for the privacy journal. The refresh runs weekly in the worker and stamps the catalogue's « mis à jour le » date; the Modèles tab's button triggers it immediately and redraws the table. New `POST /api/catalogue/refresh`. Backend tests cover the seed shape, a connected provider making its models available, a live refresh against a fake `/models` endpoint (new ids folded and classified, non-chat ids dropped, a known id not duplicated, the update time stamped) and the API route; a browser test clicks « Actualiser maintenant ».

## 1.8.0 (2026-10-28)

- **The « Modèle et budget » page is rebuilt around a model catalogue: a sortable, filterable table of every provider and model, with a one-click way to send each usage (rapide, standard, approfondi, voix) to the model of your choice — mixing providers freely.** The page now opens on three tabs — **Modèles**, **Fournisseurs**, **Budget**. *Modèles* is the new heart: a catalogue of a dozen providers and their models (Claude, OpenAI, Gemini, Mistral, Scaleway, OVHcloud, Infomaniak, aki.io, ElevenLabs, DeepSeek, Hugging Face and local models), each row showing its **rang** (1 basique → 10 haut de gamme), its **prix** (entrée / sortie per million tokens, or per minute / per image for voice and image models, with a « ~ » when the price is an estimate), its **type** (LLM, vision, voix, texte → image) and a **niveau de confiance** from 1 (grand public) to 5 (souverain RGPD), plus « loc » for local. The table sorts on any column and filters by search, type, provider, trust level and availability. Above it, one card per usage shows what that usage currently resolves to; the row's small **F / S / A / V** buttons assign a model to a usage, and the usage cards update live — so quick work can stay on a small European model while deep reasoning goes to a flagship, each on a different provider. A **Routage** selector switches between the global default and a per-space override (overridden usages are marked; a reset returns them to the global default). A **Verrou Europe (RGPD)** raises the trust floor to level 5 and force-swaps any non-compliant selection to the best sovereign/local model, reporting the swaps. *Fournisseurs* surfaces the existing Europe-only switch and the AI-account cards (connect / sign-in), and *Budget* keeps the budget fields with a live « dépensé ce mois-ci » glance. The catalogue is served by a new `GET /api/catalogue` (a new `ModelCatalog` service) built from the connected providers plus a seed; a one-line gap in the routing engine is closed so a cross-provider binding reaches the resolved provider's own credentials, not the space's. Fully translated to French; a browser test drives the catalogue table, the type filter, the Europe lock and the three tabs. (The weekly catalogue refresh job and aki.io end-to-end polish land in the next slices.)

## 1.7.0 (2026-10-28)

- **Each usage (rapide, standard, approfondi, voix) can now run on a different provider — the engine foundation for the new Modèle et budget page.** Until now one configuration meant one provider: `App::provider()` switched on a single `$cfg->provider`, and the three tiers only varied the *model*, never the provider. An AI account could already carry its own three models, but a space picked *one* account for everything — you could not, say, keep quick work on a European small model while sending hard reasoning to Claude. This release adds a routing layer that binds each usage to a provider/model as a **global default plus per-space overrides**. A new `Routing` service stores the map (`model_routing`), and `App::tierFor($usage)` resolves, per call, the right account-`Config` and tier: a per-space override first, then — when the space has no AI account of its own — the global default, else today's path. Cost accounting and pricing now follow the *resolved* provider for each call, not the space's nominal one. The resolution is deliberately backward-compatible: with an empty map every usage falls back exactly as before, so nothing changes until a binding is set. New `GET/POST /api/routing` expose and edit the map (and report what each usage resolves to in a scope); deleting an AI account now prunes its bindings so they fall back to the main settings. The visible Fournisseurs/Modèles page that sits on this engine lands in the next slice. Covered by backend tests for the fallback, global and per-space resolution, model override, account/usage validation, the API round-trip, and prune-on-delete.

## 1.6.0 (2026-10-28)

- **You can now create a role straight from the Pilotage → Rôles tab, with a "+ Nouveau rôle" button.** Until now a role could only come into being conversationally (the assistant's `role_save` tool), by installing one from the store, or by being seeded by the director and the fleet — the catalogue was read-plus-lifecycle only, matching the mockup everywhere *except* its create affordance. The Rôles tab now carries a header **+ Nouveau rôle** button that opens an inline form (name, a one-line purpose, an optional specialization offered from the ones already in use, and the brief), mirroring the mockup's drawer. It posts to a new `POST /api/roles` route wired to the very same `role_save` tool, so creation runs the identical duplicate check ("le pilote cherche les doublons et prévient si un rôle proche existe déjà") and all the existing guardrails — a name that already belongs to a role is refused so an accidental overwrite can't happen. The new role lands immediately as a metric card under its specialization header. Two browser tests cover the form opening and a created role appearing grouped under its specialization; the design doc records that the create form is now shipped (it was the one genuine gap from the mockup comparison).

## 1.5.3 (2026-10-28)

- **On a phone, the "⋯ More" menu now opens fully on-screen instead of sliding off the right edge.** On the mobile bottom bar the shell is always in its `collapsed` state, and the collapsed desktop rule `.shell.collapsed .more-menu{left:calc(100% + 10px)}` (higher specificity than the mobile right-aligned rule) was still winning — so the menu was anchored just to the right of the ⋯ button and pushed almost entirely past the screen edge, leaving only a thin strip. Since the account chip is hidden on phones, that menu is the *only* way to reach **Settings, Privacy, Memory, Apps, Mail, People, Sign out** and the rest, so they were effectively unreachable. A same-specificity override inside the mobile block now neutralises the collapsed left offset, and the menu flies up from the button, right-aligned and on-screen. View-layer only; two browser tests now assert the mobile More menu is fully within the viewport and reaches the account surfaces.

## 1.5.2 (2026-10-28)

- **The account menu (Settings, Privacy, Memory, Sign out…) opens again when you click your name at the bottom of the rail.** Since the 1.5.0 rail restructure the chip's menu was built correctly but rendered invisibly: its element carries both `space-menu` and `account-menu` classes, and `.space-menu`'s "open downward" positioning (defined later in the stylesheet, so it won at equal specificity) overrode `.account-menu`'s "open upward". With the chip pinned to the bottom of the rail, the menu was pushed off the bottom of the screen and crushed to a ~14px sliver — the empty bar with a faint chevron that appeared just under the chip, with no Réglages/Settings reachable. The positioning rule is now scoped `.rail .account-menu` so it outranks `.space-menu`, and the menu opens upward above the chip as intended. Same fix pattern as the More flyout in 1.5.1. View-layer only; a browser test now asserts the menu is visible and above the chip after clicking it.

## 1.5.1 (2026-10-28)

- **The "⋯ More" menu now opens as a flyout next to the rail, and its stray notification badge is gone.** In 1.5.0 the More button reused the old bottom-of-screen sheet, so on desktop its menu appeared as a card detached at the bottom of the window instead of beside the button — disconnected from where you clicked. It is now a small popover anchored to the button and flying out to the right, right next to the navbar (on a phone it flies up from the bottom bar, right-aligned), built and dismissed like the account chip's menu (click outside or Escape to close). The desktop menu holds just **Automations** and **Activity and costs**; the phone keeps the fuller overflow. Separately, the red count that sat on the More button has been removed: it was the unread-notifications number, which no longer belongs there now that **Notifications** is its own rail entry carrying its own badge. View-layer only.

## 1.5.0 (2026-10-28)

- **The navigation rail now matches the agreed layout: eight entries, with the director's cockpit last.** The left rail had grown to a flat list of thirteen entries that mixed daily surfaces with administration. It is now the eight that belong there, in order: **Chat**, **Tasks**, **Files** (renamed from "Library"), **Apps**, **Notifications**, **Mail**, **People**, and **Pilotage** at the bottom as the destination the cockpit was always meant to be. The four personal/administration surfaces — **Settings**, **Privacy**, **Memory** and **Activity** — moved out of the rail and into the account chip's menu (where they already lived since 1.2.0), and **Automations** moved into the "⋯ More" menu alongside **Activity**. The "⋯ More" button is now shown on desktop too (it had been mobile-only), so Automations stays one click away without crowding the rail; on a phone the same menu keeps the overflow and the account surfaces reachable, since the account chip is hidden there. In French, the chat entry now reads **"Chat"** rather than "Discussion", matching the product's own name for it. View-layer only, no backend change. Browser tests cover the new rail shape (Pilotage last, "Files" label, the surfaces that left the rail) and reach Automations and its Scheduled/Background subtabs through the More menu.

## 1.4.0 (2026-10-28)

- **AKI.IO now keeps a German assistant in Germany by default.** 1.3.0 added AKI but only *warned* that non-`TL5` models can be served from Alibaba Cloud in Singapore (outside Europe); choosing a TL5 model was left to you. Now Talixio does it for you: when your key lists any `TL5`-tagged model, the tier picks (fast/standard/deep) are drawn **only** from the TL5 models, so an AKI assistant is processed in Germany without any manual step, and the baked-in fallback is TL5 too. You'd have to set a non-TL5 model by hand to opt out; the provider card says so. If a key happens to list no TL5 model at all, Talixio still picks from whatever it does list (unchanged behaviour). New PHP tests cover both the TL5-present and TL5-absent cases.
- **The fleet orchestrator can now pair over a poll transport, not only a webhook push.** The B-cloud bridge (0.28.97) assumed the orchestrator could mint its own inbound webhook with `watch_url` — true for a cloud / Remote-Control session, but a plain local Claude Code session has no such thing, so it could never pair. It now has a second, credential-light transport: it POSTs `{"type":"pair","mode":"poll",…}` to `/api/conductor/inbox`, gets the same steady Bearer secret, and then **GETs `/api/conductor/pull`** on its own timer to drain the instructions Talixio has queued for it (each handed out exactly once). `fleet_dispatch` and the per-agent `say` simply enqueue in poll mode instead of POSTing; `fleet_status` shows "(poll)", the time since the last poll, and how many instructions are waiting. Push mode is unchanged and the two coexist — the pairing instruction now offers both and the session picks whichever it supports. Queue read-modify-write (a report, a dispatch, a drain) is serialised under a file lock so concurrent updates can't clobber each other, the queue is capped at 100 (oldest dropped), and orchestrator replies stay **untrusted** — surfaced, never executed. New PHP tests cover poll pairing, the pull drain-once, the push-vs-poll 409, and the status line.

## 1.3.0 (2026-10-28)

- **AKI.IO (Germany) joins the European providers.** Settings → AI model now offers **AKI.IO** alongside OVHcloud, Mistral, Scaleway and Infomaniak, reached through its OpenAI-compatible endpoint (`https://aki.io/openai/v1`) with your own key — so the conversation model, search by meaning and the rest can run on a German provider not subject to the US CLOUD Act. As with the others, the tier models (fast/standard/deep) are picked from the models your key can actually list (checked live by the **Test** button and cached for a day); the baked-in fallback uses `gpt-oss-20b`/`gpt-oss-120b` only if that listing can't be reached. **One caveat, shown on the provider card:** only models tagged **TL5** are processed in Germany — AKI's other models can be served from Alibaba Cloud in Singapore, which leaves Europe, so pick a TL5 model for Confidential spaces. AKI offers no transcription endpoint, so the Test correctly reports transcription as unavailable for it. The "what still leaves Europe" checks, the sovereign-only guards and the Confidential-space rules all treat AKI like the other European providers.

## 1.2.0 (2026-10-28)

- **A Claude-style account menu at the bottom-left of the rail.** The rail foot was a bare "Sign out" link; it is now an **account chip** — your assistant's avatar and name — that opens an upward dropdown holding the settings that belong to *you* rather than to a space, the way Claude's own account menu works. The menu: **Réglages**, **Utilisation** (→ Activité et coûts), **Confidentialité**, **Mémoire**; then **Langue** and **Thème**; **Installer l'application** when the browser offers it; **Obtenir de l'aide** (→ the Library FAQ); and **Se déconnecter**. **Langue** opens a sub-panel — **Automatique / Français / English** — and the choice is now **remembered**: a tiny script restores it before the dictionary loads (kept per instance in this browser, like the theme), so the interface language no longer resets to the browser default on every visit. **Thème** cycles **Système → Clair → Sombre** live, with the current value shown inline. The old `#logout` button stays in the DOM (hidden) so the "More" sheet and the collapsed-rail tooltips keep working unchanged; the chip folds to just the avatar when the rail is collapsed, and the menu flies out to the side. The mockup's Claude-only entries (plans, guest passes, API keys) are omitted — they have no meaning on a self-hosted assistant. View-layer only, no backend change. New browser tests cover the chip, the menu's destinations, the language sub-panel and the theme cycle.

## 1.1.0 (2026-10-28)

- **Pilotage — the "Votre pilote autonome" tab now matches the agreed mockup: a live agents roster and a per-agent thread.** The agents tab was a single placeholder; it is now the three cards the mockup shows. (1) A **status card** — "● Pilote autonome — connecté/déconnecté" with one grounded line (session, time since last report, and "voit N agents sur M machines") and the **Re-jumeler/Déconnecter** controls. (2) The **"Parler au pilote · à l'échelle de l'assistant"** voice composer (unchanged, now with its subtitle). (3) **"Les agents · N"**, a real roster: each agent its state dot (actif/occupé/inactif/terminé/en erreur), `nom — « tâche »`, its state, age and machine, and a **Discuter** button that opens a **two-way thread** with that agent. The Pilotage tabs are reordered and renamed to match — **Base de connaissance · ‹espace› · Votre pilote autonome · Rôles ‹n› · Chantiers ‹n›**, with live count/source badges. Backend: the existing Conductor B-cloud bridge (slice 4b) is extended, **not** a new trust edge — the authenticated `POST /api/conductor/inbox` now also accepts a structured `agents` roster and a per-agent reply; two new reads/writes, `GET /api/conductor/agents/<id>/thread` and `POST …/say`, let you message one agent, the `say` path going through the same privacy `guard()` and space-dispatch rules as every other fleet dispatch. Rosters and threads are bounded (50 agents, 40 turns). Orchestrator replies stay **untrusted** — surfaced in the thread, never executed. French throughout; responsive; the roster and the thread dialog both carry voice dictation. New browser + PHP tests cover the roster render, the Discuter thread, and the sanitization bounds.

## 1.0.1 (2026-10-28)

- **Fix — the Pilotage tabs and the Automatisations subtabs were invisible on a phone.** On a narrow screen the four Pilotage tabs (**Agents · Chantiers · Rôles · Connaissances**) collapsed to nothing, so only the first panel showed with no way to reach the others. Cause: the tab row is a flex child of the scrolling page column, and the mobile `overflow-x:auto` that makes it swipeable also made its `min-height` resolve to `0`, so flexbox shrank the row to zero height whenever the page was taller than the viewport. The row now holds its height (`flex:none`), so all four tabs show and scroll sideways, and the `[Programmées · Arrière-plan]` strip stays visible too. A mobile-viewport browser test now asserts the tab row keeps its height and that tapping a tab switches panels, so this can't silently regress. View-layer only, no backend change.

## 1.0.0 (2026-10-27)

- **Pilotage is complete — the director's cockpit, 1.0.** The six-slice plan ([pilotage.md](docs/design/pilotage.md)) is shipped end to end: **Agents** across your machines with the Fleet orchestrator and a voice-first "talk to the pilot" composer, **Worksites** (the director's teams/directives), a **Rôles** catalogue with specialization grouping, an overlap/doublon signal, and the full lifecycle (archive, guarded delete, Améliorer A/B), the **space switch** lifted to the top of the rail, a read-only **Knowledge** glance, and — with slice 6 — the settled naming (**Automatisations** with `[Programmées · Arrière-plan]` subtabs) plus the reciprocal **Activity ↔ Pilotage** cross-links. Throughout, Pilotage stays a **view layer over already-shipped machinery** (Director/directives, Assessor/role-registry, Conductor/Fleet): it opens **no new backend trust edge**, every worker path keeps the existing app/connector approvals and space isolation, and every input carries voice dictation. The whole cockpit runs on the **same roles**, so it keeps improving as the roles do. This release only marks the milestone — the functional changes all landed across 0.29.1–0.29.5.

## 0.29.5 (2026-10-27)

- **Pilotage — the polish pass and the naming settle (slice 6).** The last view-layer slice of the plan ([pilotage.md §4 slice 6](docs/design/pilotage.md)), no new backend trust edge. **Naming settled:** the two scheduled-work boards stop being separate rail entries and become one, **Automatisations** (EN "Automations"), with **`[Programmées · Arrière-plan]`** subtabs — "Programmées" is the former Routines board, "Arrière-plan" the former "Jobs en arrière-plan", now the clearer **Travaux en arrière-plan**. Both boards keep their own hash (`#tasks`, `#jobs`) and loaders untouched; a shared `.subtabs` strip at the top of each switches between them, the router lights the single rail entry for either hash, and the mobile "More" sheet folds the two into the one entry. **Cross-links** make the two activity boards agree instead of competing: Pilotage's Worksites panel links to **"See full activity and costs"** (every agent and tool), and the Activity page links back to **"Steer agents, roles and worksites in Pilotage"** (the director's own slice) — Activity stays the whole picture, Pilotage the director's cut. **Polish:** a friendlier empty state on the Scheduled board that invites a chat example, and keyboard access — the rail/topbar space switches now close on **Escape** and return focus to their button, and the subtabs carry `role="tab"`/`aria-selected` and a visible focus ring. French throughout. This completes Pilotage's six slices; **1.0.0** is now in reach and will be cut on confirmation.

## 0.29.4 (2026-10-27)

- **Pilotage — the space switch lifted to the top of the rail, and a real KB glance (slice 5).** Two small view-layer moves, no new trust edge ([pilotage.md §4 slice 5](docs/design/pilotage.md)). The **space switch** now sits in a dedicated block at the **top of the rail**, under the assistant name — the current space's own colour drives its dot, and it is reachable from any view instead of only the chat topbar (the account/sign-out stays at the foot of the rail). Both switches — the new rail one and the chat-topbar one — share **one builder and one close path**, so they steer the same spaces through the same `switchSpace`; there is no second switch logic. On a phone the rail collapses to the bottom tab bar as before and the topbar switch carries on, so nothing is lost. The **KB** tab stops being a one-line pointer and becomes a read-only **glance** at the space's grounding: one new aggregate, **`GET /api/library/kb`**, reports the space's library root, the recursive **file count under that root**, its **subfolder** count, the whole library's size for context, and the active **hub pull** grant — all by reusing `Library::tree`/`stats` and `Spaces::hubGrants`, writing nothing. A **"Open the Library"** link deep-links straight to the space's root folder. French throughout; responsive (the three stat tiles stack on a narrow phone). Still on the 0.29.x line; 1.0.0 stays reserved for when Pilotage is complete (after slice 6).

## 0.29.3 (2026-10-27)

- **Pilotage — role lifecycle: archive, a guarded delete, and the Améliorer A/B (slice 4).** The Rôles catalogue gains the three lifecycle actions of the plan ([pilotage.md §4 slice 4](docs/design/pilotage.md)), each reusing the registry and the Assessor rather than opening a new path. **Archiver** sets a new optional `archived: true` header flag (via the same `Skills::setField` as the specialization write). `Skills::all()` now drops archived roles by default, so an archived role falls out of the catalogue map, the system-prompt index, the director's `role_find` and the overlap pass at once — while its file and **all its Assessor history stay on disk**. An **Archivés** disclosure at the foot of the catalogue lists them with one-click **Réactiver** (clears the flag). **Supprimer** is guarded: `POST /api/roles/<role>/delete` refuses with 409 when `Assessor::runCount` is above zero and steers to Archiver — history is never lost silently — and the archived row disables Delete for a role that has runs; a role that never ran deletes cleanly via `Skills::delete`. **Améliorer** opens the candidate brief in an editor and hands it to the Assessor's existing A/B (`role_improve` → the eval job): it runs the current and candidate briefs on the role's golden cases, scores both, and promotes the candidate only if it clearly wins — the same promotion decision the engine already makes, with the result reported in the chat. Protected roles (the assessor, role #0) expose none of these — their lifecycle is the user's to manage directly; a shared `roleWritable` guard returns 404 on a non-role and 403 on a protected one. No new backend trust edge: the registry entry stays inert until a worker runs it, and that path keeps the existing app/connector approval and space isolation. Still on the 0.29.x line.

## 0.29.2 (2026-10-27)

- **Pilotage — specialization grouping and the overlap/doublon signal (slice 3).** The Rôles catalogue gets the two genuinely new _concepts_ of the plan, kept deliberately orthogonal — grouping is not duplication ([pilotage.md §3, §4 slice 3](docs/design/pilotage.md)). **Specialization**: a new optional `specialization` header field (added to `Skills`' header order so `save`/`setField` preserve it), surfaced by `/api/roles` and used to group the catalogue under section headers; a role with none falls into a trailing **"Unspecialized"** group, and each card carries a one-field inline editor (`POST /api/roles/<role>/specialization`) so the map is actually usable — pure grouping by shared topic, with **no behavioural coupling** between roles that share a label. **Overlap** is the separate, finer signal: a new pairwise closeness pass, `Skills::overlaps()`, scores every pair of roles with a symmetric set-cosine over the same fields `find()` ranks on (name, description, skills, tools, when-to-use), and pairs at or above the threshold surface a top **banner** ("Overlap detected — researcher ≈ web-researcher, 87% close") alongside the per-card hint. It is **computed on demand, never stored as truth** — a suggestion, not a verdict — and reuses the registry's own ranking rather than a second similarity store. The **merge** affordance holds the invariant that merging is _never automatic_: **Compare** opens the two briefs side by side, and **Propose a merged role to the pilot** hands the director a drafted, human-approved instruction through the ordinary chat (keep the stronger brief, union the skills and tools, confirm before saving) — the director does the actual merge with its own tools, so a combined role still improves everywhere. No new backend trust edge; the overlap pass and grouping are read-only and the specialization write reuses the existing local skills-write path. Still on the 0.29.x line.

## 0.29.1 (2026-10-27)

- **Pilotage — the Rôles catalogue gets its first screen, read-only (slice 2).** The Roles tab stepped up from a flat name-and-description list to a real catalogue of metric cards, each drawing the **Assessor's own numbers** — no second metrics store, no numbers computed for display ([pilotage.md §2–3](docs/design/pilotage.md)). One new aggregate, **`GET /api/roles`**, joins each `kind: role` file's header (tier, scope, version, protected/published) to `Assessor::health` + `metrics` in a single call, so the view does no per-role round-trips. Each card shows the acceptance **ring** (the anchor metric — real-world accept rate), a **trend** arrow (rising / falling / steady / too-new), a **sparkline** of recent accepted vs. rejected runs, mean cost per run, run count and golden-case count, the protected/published/tier chips, and an **overlap** hint when the registry already holds a near-duplicate (reusing the Assessor's existing duplicate signal; the full specialization map and overlap panel land in slice 3). The two lifecycle actions that are already one shipped call wire now — **Evaluate** (`role_evaluate`, the Assessor's read) and **Publish** (`role_publish` to the store, guarded by a confirm and hidden for protected roles like the assessor) — while **Improve** and **Archive** are present but deferred to slice 4. Every card field is dictation-agnostic and the tab is responsive (cards reflow to one column on a phone). French throughout; **no new backend trust edge** — the aggregate is read-only and the actions reuse the existing privacy-gated tool path. Still on the 0.29.x line; 1.0.0 remains reserved for when Pilotage is complete.

## 0.29.0 (2026-10-27)

- **Pilotage — the director's cockpit, opened and voice-first (slices 1 + 1a).** The pieces a person uses to _direct_ their own assistant were scattered — roles were headless markdown files, directives were mixed into Activity, the fleet orchestrator was buried in a space's settings. **Pilotage** is the one surface that gathers them: a new top-of-rail entry with four tabs — **Agents** (the Conductor/Fleet: the delegated orchestrator session and the agents it runs across machines, with the connect/bootstrap panel **moved here** from space settings, where one orchestrator serves the whole assistant), **Worksites/Chantiers** (the Director's directives, with the same `steer`/`rate`/`cancel` actions already shipped), **Roles** (a first read-only glance at the role registry — the _same_ `Skills` registry the Director and the fleet seed from, so improving a role improves everywhere; full metrics and lifecycle land in the next slices), and **Knowledge** (a read-only pointer to the space's library grounding, with deep edits staying in Library). This slice is **almost entirely a view layer** over machinery already shipped through 0.28.99 ([pilotage.md](docs/design/pilotage.md)); it opens **no new backend** and no new trust edge, and the per-space privacy choke point is unchanged. Pilotage is **voice-first**: a prominent **"Parler au pilote"** push-to-talk heads the Agents tab (speak a directive, read it back, then send — routed through the ordinary chat `send()` so the director uses its own fleet tools), and a mic sits on the composer. This reuses the dictation path already shipped end to end (`toggleDictate` + `POST /api/transcribe` + the sovereign/local `Transcriber`, honouring the space's privacy level via `PURPOSE.transcription`, transcribed and never kept) — pure wiring, not a new capability. Responsive from the start (the tab row scrolls on a phone, the push-to-talk stacks). French throughout. **1.0.0 is reserved for when Pilotage is complete** (after slice 6); the 0.29.x line opens here.

## 0.28.99 (2026-10-26)

- Cross-assistant orchestration, phase 5 follow-up — **a connect/bootstrap panel for the fleet orchestrator, in each space's settings.** The slice-4b bridge was host-agnostic but had no UI: pairing a delegated orchestrator session meant calling `fleet_setup` from chat. The space editor now carries a **Fleet orchestrator** panel that shows the binding's live state (connected or not, when it last reported and was last dispatched to) and, on _Connect_, prints the copy-paste bootstrap instruction to paste into a **Claude Code session with Remote Control on — your own host is fine, Anthropic-hosted is not required** ([orchestration.md §5.1](docs/design/orchestration.md)); _Disconnect_ forgets the binding. The binding is **one per assistant**: a single orchestrator serves the whole assistant, so the panel in every space configures the _same_ shared orchestrator — but each space still governs independently whether it may dispatch, because the **privacy choke point is unchanged** (a confidential or secret space cannot dispatch outward, shown inline in the panel as the privacy level changes). Pairing leaks nothing — it mints only a one-time code and an instruction, no key — so `fleet_setup` is **no longer privacy-gated**; the guard stays at the single outbound seam (`toFleet`/`fleet_dispatch`) where knowledge actually travels. The webhook URL and inbox secret still live 0600 in `conductor.json`, are never returned by any API after pairing, and are never logged; the new status endpoint is secret-safe by construction (asserted in the suite).

## 0.28.98 (2026-10-26)

- Cross-assistant orchestration, phase 5 — **the messaging backbone, built once so every cross-agent edge rides the same rails.** Slices 4a/4b each stitched their own ad-hoc channel; this phase extracts the shared substrate the whole spec leans on ([orchestration.md §3](docs/design/orchestration.md)) into one service, `Relay`, that enforces the five invariants in a single place so no caller can skip one. **Address:** every reachable agent has a stable string `"<kind>:<id>"` (`worker:42`, `director:7`). **Durable mailbox:** an append-only queue per address (`relay_messages`) that outlives the chat and the container, with deliver-once `pull`. **Consent, default closed:** a message is refused unless the recipient has granted the sender a scoped, revocable capability (`relay_grants`) — there is no ambient reachability. **Budget envelope + loop guard:** a message carries a budget that may only _shrink_ downstream, a decrementing depth limit, and the path it has travelled, so a repeat recipient is a cycle and is refused — two agents cannot burn real money in a loop. **Untrusted-input posture:** a relayed message is data, never a command; `render()` prefixes the banner and the backbone itself never executes anything. Proven end to end by the backbone's first rider, an **in-assistant worker mailbox**: opening a directive grants the worker↔director team their scoped mailbox, a running worker can `ask_director` when it hits a decision only the director can make (surfaced to the user) and keep working, the director reads the questions (`directive_inbox`) and reaches a running worker (`directive_tell`), the worker reads the reply once with `check_inbox` wrapped as untrusted data, and finalising or cancelling the directive dissolves the team's grants — no mailbox outlives the work it served. The two spec use cases (the corporate hub, the session conductor) now have one substrate to become thin adapters over, rather than two trust models that drift apart.

## 0.28.97 (2026-10-26)

- Cross-assistant orchestration, slice 4b — **the conductor reaches the wider fleet, through a credential-light bridge.** A local `claude -p` session (slice 4a) runs on this host; the sessions the user actually keeps live across their account, and there is **no external API** by which Talixio could list, read or message them ([orchestration.md §5.1](docs/design/orchestration.md)). So Talixio now drives a **delegated cloud _orchestrator_ session** — itself a Claude Code session, holding the native `claude-code-remote` tools — over a narrow, two-way webhook bridge, and **no account-wide token ever lives in the Talixio container.** Pairing is inverted so the credential stays with the agent that owns it: `fleet_setup` mints a one-time code and hands back a copy-paste instruction; the orchestrator session mints **its own** inbound webhook (`watch_url` — a sealed credential Talixio cannot read), POSTs that URL back to Talixio's authenticated `/api/conductor/inbox` with the code, and collects a steady secret in return (issued **once**, at pairing). From then on Talixio POSTs **KB-grounded** instructions to that webhook (`fleet_dispatch`), and the orchestrator reports its fleet's progress back to the inbox as `Authorization: Bearer <secret>`, surfaced in the notifications inbox. The **privacy choke point** holds at the single outbound seam: a confidential or secret space cannot dispatch to the fleet, and the instruction is grounded in that space's knowledge _inside_ Talixio so the orchestrator never touches raw knowledge. The orchestrator's replies are **untrusted input** ([§5.3](docs/design/orchestration.md)) — surfaced to the user, never executed here. `fleet_status` reports liveness (when it last reported vs. when last sent to, with a recreate hint if it has gone quiet) and `fleet_disconnect` forgets the binding. The webhook URL and inbox secret live 0600 in `conductor.json`, are never returned by any API after pairing, and are never logged.

## 0.28.96 (2026-10-26)

- Cross-assistant orchestration, slice 4a — **the session conductor, local foundation.** The assistant can now **delegate one role to a background Claude Code session** — a real repo, git and shell for long work — not just the headless-web and sandboxed-shell workers it already runs. This is the agent factory's **Tier-D** worker environment ([orchestration.md §5.4](docs/design/orchestration.md)), and it is deliberately **not a second system**: a code session is dispatched from a **role in the registry** through the _same_ path as any in-process worker — `Director::plan` resolves the role and `Director::seed` writes the prompt (the shared builder extracted so the two can never drift), it becomes an ordinary **directive with one worker**, and when it finishes the **same finalisation** aggregates it, relays it to the chat, and records the acceptance+cost anchor per role. So a code agent is measured, rated (`directive_rate`) and improved (`role_improve` → `role_publish`) exactly like every other role — the whole fleet stays homogeneous, improving together. A new chat tool **`conductor_dispatch`** (role, task, goal, tier?) starts one; it reports back on its directive, shows up as a worker card on the existing Activity board, and spends against the same budget meter — no separate conductor UI. The runtime is a small `ClaudeCli` seam: **B-local** first — a detached `claude -p` session on this host, read back from its log (`ShellClaudeCli`); a fake drives the tests. The **privacy choke point** holds: a confidential or secret space cannot dispatch a session outward. Cancelling the directive stops the live session. (Next: **B-cloud** — whole-account reach through a credential-light webhook bridge, so the assistant drives a delegated orchestrator session that manages the fleet.)

## 0.28.95 (2026-10-26)

- Cross-assistant orchestration, slice 2 follow-up — **binary (image) knowledge assets, and a one-click _Share_ in the library.** The company knowledge base now holds **images** (a brand chart, a logo…), not only text: an approved image lives as content in a **blob store beside the catalogue** (`blobs/<sha1>`, content-addressed and deduplicated), so the bytes never bloat `catalog.json` — the catalogue keeps a reference (hash, mime, filename, size) and the contributor's provenance. The bytes travel as base64 only through the outbox and the admin's review, who now sees the actual image preview before approving. Installing an image asset writes the real file into the library under _Company KB_ (updating it in place on a later version). Only raster images are accepted — PNG, JPEG, GIF, WebP, 2 MB at most — and **never SVG**, which can carry script. A new chat tool `asset_publish_file` contributes a library image, and the library viewer now has a one-click **_Share to the company knowledge base_** button (shown only where the space holds the _contribute_ grant) that sends the open document — image or text — up for review through the same privacy guard and per-space grant as the chat tools, so the UI path cannot bypass them. (Next in the spec: the session conductor.)

## 0.28.94 (2026-10-26)

- Cross-assistant orchestration, slice 2 — **per-space grants for the company knowledge base, and provenance.** The shared knowledge base is now reached **per space, through two grants an admin turns on** (both off by default, both revocable): _pull_ lets a space install approved assets, _contribute_ lets it submit its own for review. `asset_install` and `asset_publish` now check the current space's grant and, when it is missing, refuse with a message pointing at the space's settings — on top of the existing privacy guard, so a confidential or secret space still cannot publish outward whatever its grants. The grants sit in the space editor (shown only on a multi-assistant server) as two checkboxes under _Company knowledge base_. Every published asset now records **who contributed it** — the contributor's name, their assistant, and the space it came from — shown on the admin's store page, so the knowledge base is attributable. (Next in the spec: binary assets such as a brand-chart image, a one-click _Share to the company knowledge base_ button in the library, and the session conductor.)

## 0.28.93 (2026-10-26)

- Cross-assistant orchestration, slice 1 — **contribute a knowledge asset to the company hub.** On a server where several people each run their own Talixio, there is now one shared **company knowledge base**: a space can submit a spec, a template, a policy or an FAQ/note to it, the admin reviews it in the store, and once approved every assistant can install it into its own library under a _Company KB_ folder. Two new chat tools drive it — `asset_publish` (send the current document up for review) and `asset_install` (pull an approved one down) — and they reuse the existing store review/approve machinery, so nothing is shared until a human approves it. Sharing is **scoped by space**: a confidential or secret space cannot publish outward (the same privacy guard that already protects every other outbound write), and only the asset's title, text and category ever leave — never a key or saved state. Re-installing an updated asset replaces the library note in place rather than piling up copies. (This is the first buildable piece of the [orchestration spec](docs/design/orchestration.md); the per-space hub grants, binary assets and the session conductor come next.)

## 0.28.92 (2026-10-25)

- Agent factory — **the director is discoverable before you ever run one.** The directives section on the Jobs/Activity page used to be invisible until a directive existed, which made the whole feature hard to find. It now shows a one-line hint when empty — _"🎬 No directives yet. Ask in the chat to run a team, e.g. «research our top 3 competitors and draft a one-pager on each». The director runs a worker role for each part, in parallel — the roles must already exist."_ — so you know the capability is there and exactly how to start it. (Once a directive runs, the real rows replace the hint.)

## 0.28.91 (2026-10-25)

- Agent factory, phase 3 finished — **the board's budget meter and voice dictation.** The directives section on the Jobs/Activity page now carries a **budget burn-down meter** (today's spend against the daily limit, turning amber then red as it fills) — the control-room gauge for how much the running teams are eating. And a **dictation mic** on the chat composer: tap it, speak, tap again, and what you said is transcribed (via your configured transcription) straight into the box to edit and send — nothing is kept as a file. The mic is wired by a reusable `data-dictate-for` hook, so it can sit on any field. (Hands-free "talk to the director" with send-on-silence is a later addition.)

## 0.28.90 (2026-10-25)

- Agent factory, phase 4 — **share worker roles across the fleet.** On a multi-assistant server, a role can now be published to the shared store (admin-reviewed, exactly like an app or connector) and installed by other assistants. New tools: **`role_publish`** sends a role for review (only its purpose, brief, tier and the skills/tools it uses — never any data or key; a protected role like the assessor is never shared; it warns if the store already has a similar role), and **`role_install`** installs or updates a shared role into the local registry. The admin's store page reviews and lists roles alongside apps and connectors. This is the piece that lets an organisation's assistants get better together — one person's proven role becomes everyone's.

## 0.28.89 (2026-10-25)

- Agent factory, phase 2 complete — **roles now improve themselves, honestly.** New tool **`role_improve`**: propose a better brief for a role and the assessor **A/Bs it for real** — a background evaluation job runs the current and the candidate brief on the role's golden cases, an impartial judge pass scores both against each case's rubric, and the candidate is **promoted to a new version only if it clearly wins** (and isn't gated). A brief that doesn't beat the current one is discarded; the current version stays. This closes the loop the earlier phases set up: run a team → record real acceptance → test improvements against reference cases → keep only what measurably helps.

## 0.28.88 (2026-10-25)

- Agent factory, phase 3 (glass box, first slice) — you can now **watch and steer** the director's teams. The Jobs page shows **directives as rows** with their workers nested inside (role · tier · status · cost), a progress bar, and controls: raise priority, cancel, or — once a directive is done — **👍 Accept / Not useful**, which feeds the phase-2 acceptance anchor so the roles learn from real outcomes. Expand a directive to see its workers; its combined result is there too. A slim **live bar above the chat composer** (`🎬 N directives · M workers running · $X left today`) appears whenever a directive is active and opens the Activity view in one tap. Worker jobs stay out of the flat job list — they live under their directive. (The board and voice remain for later in this phase.)

## 0.28.87 (2026-10-25)

- Agent factory, phase 3 (executor backbone) — the director can now run a **team of worker roles** toward one goal (see `docs/design/agent-factory.md`). The director is the ordinary chat assistant given four tools: **`direct`** opens a *directive* (a goal) and queues its **workers** — each a role running as a background job, so they reuse the whole jobs engine (tiers A+B: headless web + sandboxed shell, cost caps, cancel, recovery); **`directive_status`** shows a directive and its workers; **`directive_steer`** reprioritises or cancels; **`directive_rate`** records whether the user accepted the result — the phase-2 anchor, now fed by real runs. Workers don't each report to the chat: when the last one finishes, the directive is finalised once, its workers' outcomes aggregated into a single reply, and each role's run recorded for the assessor. Worker jobs stay out of the flat Jobs list (they live under their directive). The glass-box UI (board, Activity, in-thread status, voice) is the next step; this release is the engine under it.

## 0.28.86 (2026-10-25)

- Agent factory, phase 2 — the assessor and the improvement gate (see `docs/design/agent-factory.md`). The measurement spine that keeps role improvement honest: **`role_record`** logs how a role's run turned out — the anchor metric (was it accepted, at what cost), which is external and can't be gamed; **`role_case_add`** keeps the golden cases a role is judged on; **`role_evaluate`** is the assessor's read on a role (acceptance %, mean cost, trend, metrics, golden cases, near-duplicates). The **assessor is seeded as role #0**, protected: editing it, changing a role's metrics, a role gaining a new capability, or publishing it are gated — `role_save` refuses such a change until the user approves (`confirm`). A new proxy metric must **backtest** (correlate with the anchor over past runs) before it is trusted. Live A/B promotion (running old vs new on the golden cases) lands with the worker executor in phase 3, on top of this data.

## 0.28.85 (2026-10-25)

- Agent factory, phase 1 — the role registry (see `docs/design/agent-factory.md`). A **role** is a reusable worker definition (a purpose + a brief + a model tier + the skills and tools it uses), stored as an enriched skill — no parallel store. Two new tools: **`role_find`** ranks existing roles that already cover a purpose, so one is reused instead of a near-duplicate being made (search-before-create); **`role_save`** writes a role, bumps its version, and warns when it overlaps an existing one. Skill files round-trip the extra header fields, so the editor can see and change them.

## 0.28.84 (2026-10-24)

- Fix: a turn could fail with "Command array element N contains a null byte" after fetching a PDF. `fetch_url` now extracts a PDF's text (instead of returning raw bytes) and strips null/control bytes from any page, and the Claude Code provider strips a stray null byte from its command, so a turn never crashes on one.

## 0.28.83 (2026-10-24)

- Fix: the store catalog failed to show ("s.replace is not a function") when it listed an installed app, because an app's id is a number. HTML-escaping now accepts numbers, so the Connectors/Apps catalog renders correctly.

## 0.28.82 (2026-10-24)

- Fix: the store catalog ("From the store") could vanish entirely instead of showing its list, if the store response was missing a field. It now always shows the heading, and a clear message if the catalog can't be loaded.

## 0.28.81 (2026-10-24)

- Store catalog easier to navigate: **Connectors** and **Apps** are now separate tabs, with the existing theme/category tabs and a new **A–Z** filter (A–C, D–F…). The connector list and the store's apps are both browsable from Settings → Connectors.

## 0.28.80 (2026-10-24)

- French & EU law search, built in (nothing sent to a third party): **Légifrance** and **JudiLibre** (French codes, laws, decrees, Journal officiel and case law) via a free PISTE account, set up in Settings → Connected accounts; and **EUR-Lex** for EU law (read the GDPR, the AI Act… by CELEX number), which needs no key. The EU AI Act MCP (Lexbeam, hosted) was added to the connector list.

## 0.28.79 (2026-10-22)

- Fix: bulk actions on a regex search (delete/move) failed with a 422 even when they succeeded, and the results did not refresh afterwards. Both are fixed.
- Library on mobile: the toolbar is now a single **+** button that opens every action (import, new note/folder, shares, recent, trash); the search no longer overflows, the folder's actions sit on one scrollable row, and the stats line is compact. The full toolbar stays on desktop.
- Library: search file and folder names with a regular expression (tick *Regex*), then move, download as a zip, or delete the matches in bulk.
- Library (desktop): each file and folder has an actions button (⋯) — rename, move, download, share, delete, and for a folder: new file, new folder, download zip.
- The assistant can make real document files — PDF, Word, Excel, OpenDocument, HTML or Markdown — and save them to the library, all on the server with LibreOffice, nothing sent to an outside service (tool `kb_make_document`). Ask for "a PDF of this", "a Word letter", "an Excel of these figures".

## 0.28.78 (2026-10-21)

- Security, PHP2Shell (CVE-2026-109618, CVE-2026-109619, critical PHP flaws that let anyone run code on the server without logging in): the server's dependencies image is rebuilt at the next update with Debian's fixed PHP, and its build stops if that fix is missing. The PHP upload-progress handler (one of the two attack paths, not used by Talixio) is turned off everywhere. Without Docker, run `apt update && apt upgrade` and restart talixio-web. A `deploy/check-php2shell.sh` script reports whether a running server is patched.

## 0.28.77 (2026-10-21)

- Apps: an open app now takes the whole page width and height (the page's introduction hides while an app is open).
- Connectors → From the store: the connectors are grouped in tabs by theme (French public data, Online services, Banking…), taken from the FAQ list; a search looks in every theme.
- Connectors → From the store: a line now explains the form below the list (share one of your connectors, the admin reviews it first), the empty-store message says the FAQ connectors arrive after the server update, and a search box appears when the store holds more than 8 connectors.

## 0.28.76 (2026-10-21)

- Store: the connectors listed in the FAQ are added to the catalog automatically when the agent starts or is updated
  (only once each: one the admin removes does not come back). The admin button still adds them all on demand.
- Settings: every section has tabs, one per card, so you see one card at a time (all of them while searching). Each
  section remembers its last tab.
- Admin: when the agent is older than the admin page (an action answers "not found"), the message says to run the
  update again or install-stack.sh.
- FAQ: Dolibarr and lemlist connectors added to the connector list (and so to the store).
- FAQ: the connector list says where to find these connectors in the assistant's store.

## 0.28.75 (2026-10-21)
- Store: *Add the connectors listed in the FAQ* on the admin's store page puts every connector of the FAQ's connector
  list in the store at once (those it does not have yet). Remove the ones you do not want.
- Connector list: Brevo (official) and Jira and Confluence (Atlassian, official, with an API token). An "▶ Install"
  link can now name the headers each person fills in.

## 0.28.74 (2026-10-21)

- Fix: the store answered “not found” to every assistant (its web routes were not wired), so nothing could be submitted, listed or installed. A browser test now submits an app to the store.
- “Submit to the store” on an app opens only the submission form; “Share” keeps the copy and file options with it.

## 0.28.73 (2026-10-21)

- The store is always visible: in Apps (“From the store”), on each app (a new “Submit to the store” button, also in Share) and in Settings → Connectors. Where the store is not set up, it says why and what to do instead of hiding.
- An agent made by an older update now repairs itself when it starts: it adds the store volume to itself, then remakes the assistants the old code made (so they get the store too). Runs of this repair and of an update never overlap.

## 0.28.72 (2026-10-21)

- A store for servers shared by several people. The admin page has a **Store** tab: apps and connectors sent by people wait there for review (who sent it, their note, all the code, sources and connectors) and reach the store only once approved; the admin can also publish an app file or a connector directly, and remove anything. Each assistant sees the store in **Apps → From the store** (install, update to a new version, uninstall) and in **Settings → Connectors** (install, uninstall, send one for review). An app is submitted from its **Share** card. Nothing secret travels: no data, saved state, keys or header values; connectors only by their GitHub repository or https address. An installed app still waits for its owner's approval, and Secret-space apps never leave. The catalog lives on a shared volume the agent writes and every assistant reads only.

## 0.28.71 (2026-10-21)

- Apps can be shared. **Share** on an app copies it to another of your assistants, or downloads it as a `.talixio-app.json` file that anyone adds with **Add an app from a file**. The copy carries the code, what the app reads and how to install the connectors it uses (GitHub repository or online address, with header names but never their values), never its data, saved state or keys. Library files are found again by their place. A copied app waits for approval, and its page lists what is missing: connectors to install with one button, tools to let run without asking. Apps of a Secret space stay where they are.

## 0.28.70 (2026-10-21)

- Settings → Connectors: each connector is a card you unfold, with its own description (from the connector, or `description` in `mcp.json`). Inside, a table lists every tool with what it does, grouped by what it seems to do (only reads, not sure, can change or send things), with a filter for long lists and one box per group to let a whole group run without asking. The groups are only a guess shown to help; only your ticks skip the approval.

## 0.28.69 (2026-10-20)

- Tests: browser tests for the web app (`node tests/browser/run.mjs`, Playwright and Chromium). They sign in, open every view, and go through the main flows: chat and its search, quick capture of a task and a note, spaces, library folders, notes, search, sharing, the board in all its views with quick add and card editing, contacts, settings, notifications, the French interface and a phone-sized screen. Any error in the page fails the run; the run also reports how much of `app.js` it used (about two thirds).

## 0.28.68 (2026-10-20)

- Code quality: the web API router is split by area (system, chat, work, connectors, mind, library, board, apps) instead of one 250-line match, and the assistant's tools are registered by group instead of in one 460-line constructor. No change in behaviour; the tools keep their order.

## 0.28.67 (2026-10-20)
- Review fixes. Apps: an app of a Confidential space, or one reading a Confidential file or folder, never reaches the web or a connector; the approval card names every kind of source (folders, searches, connector tools, calendar, contacts); a folder source with `_` or `%` in its name lists its subfolders; script arguments may not start with `-`; cached answers older than a day are cleared. Moving a space: a link to a file that did not come along points nowhere (it no longer opens another file with the same number), and a file that would land in another space's folder goes to the moved space's "Linked files" folder instead. Library: changing a file keeps its creation date; Recent changes filters before counting, so a burst of your own changes no longer hides others'; the digest is sent once an hour (or day), never twice or skipped.

## 0.28.66 (2026-10-20)
- Recent changes: an *Acknowledge* button marks one file's change as seen; the folder button is now *Stop notifying* / *Notify again* (clearer than "Mute" in French).

## 0.28.65 (2026-10-20)
- Library: **Recent changes**. Each file keeps when it was last created, changed, moved, renamed or restored, and by whom (you, the assistant, a routine, the network drive). A *Recent changes* view (last hour, today, 2 days, 7 days) marks the files others changed that you have not opened since; a grouped in-app notification (at most hourly, daily or off; folders can be muted) never counts your own changes; the morning briefing gets a "since yesterday" line; new `kb_recent` tool; an app's folder source gives `changed`. Secret files stay in their space.

## 0.28.64 (2026-10-20)

- Apps: links now work (library files, Talixio pages; a web address asks first), a new list component, and a
  cleaner dashboard look (figure cards, section titles, links, empty states).

## 0.28.63 (2026-10-20)

- Apps: new sources (library folder and search, calendar, contacts, connector tools marked as only reading), looser
  source parameters, new visual components (pie, gauge, ranked bars, progress, heatmap, timeline, badges, tabs,
  trend lines, stacked bars) and downloads (CSV, PNG). New `docs/ROADMAP.md`.

## 0.28.62 (2026-10-19)

- Apps: making or changing an app takes the deep tier; an app whose code breaks now shows the error instead of a blank page, and a DOMContentLoaded listener in its code still runs.

## 0.28.61 (2026-10-19)
- Apps: an answer saying an app was created when no app_save succeeded goes once to a stronger model, which saves it for real or gives the error; app_save errors now say plainly that nothing was saved, a missing script source is refused up front, sources sent as JSON text and script names ending in .py are accepted; the example descriptions are translated.

## 0.28.60 (2026-10-19)
- Apps menu: small apps (charts, tables, trackers) the assistant builds from a vetted kit and runs in a sealed frame with no network; each reads only the sources it declares (a fixed web address, a library file, the space's tasks, a saved script) and gets no data until you approve it. Two examples (Stock prices, Tasks at a glance); ask “With what you know about me, could you suggest several apps…?” for ideas. FAQ: Apps.

## 0.28.59 (2026-10-19)
- Optional knowledge graph of the library (Settings → Web search and memory, off by default): the people, companies, projects and places each document mentions are noted in the background by the fast model (capped per day, never for a folder the model may not read), and the new `kb_related` tool gathers everything about a name or what is linked to a file.

## 0.28.58 (2026-10-19)

- Connectors: a long result (over 12,000 characters, e.g. a converted HTML page) is saved whole as a library file and
  the assistant moves it where you want, instead of retyping it and cutting it. Approved actions now show their raw
  result folded in the chat ("show the result"). Jobs done without trouble are archived after 2 days (failed or
  tagged ones after 30).

## 0.28.57 (2026-10-18)

- Trusted websites: sites in Settings → Browser and approvals open without asking (subdomains included), also from
  Confidential spaces, routines and after outside content; an "Always allow" button on approvals adds the site (or
  allows web search from Confidential spaces). Secret spaces stay offline. Approvals now send one notice per batch
  instead of one email each.

## 0.28.56 (2026-10-18)

- Routing benchmark on outside cases: 2,100 documents and questions from three public datasets, labelled by their
  own PII annotations, with a locked test split, a cross-validated naive Bayes baseline and the router.

## 0.28.55 (2026-10-18)

- Routing benchmark: the rules now treat offer letters, pay rises and bonuses as confidential, and the router
  prints its first error instead of failing silently.

## 0.28.54 (2026-10-18)

- New `research/routing-bench`: a small benchmark that tests how requests would get their trust label, tier and
  split into steps, before that routing goes into the app. It has 32 synthetic cases and scores leaks (label too
  low), labels set too high, tier and split. It runs with pattern rules only, or with any OpenAI-compatible router model.

## 0.28.53 (2026-10-17)

- Installed app: on a server where a person's assistants share one address (`/alex-work/`, `/alex-private/`),
  switching to another assistant now stays in the app's window. Before, the app showed it as an outside page,
  with an address bar and a close button taking up space.

## 0.28.52 (2026-10-17)

- Server update: once the new version of the admin agent runs, it restarts every assistant whose settings that version
  writes differently. Before, the previous version restarted them, so a fix in what each assistant is given (like
  0.28.51's full list of linked assistants) only showed after a later update.

## 0.28.51 (2026-10-17)

- Assistant switcher: an assistant now lists all of the person's assistants, also the ones linked only through
  another (Work and PIA each linked to Private: Work now shows PIA too). Update the server so they restart with it.

## 0.28.50 (2026-10-17)

- Microsoft sign-in: when the Microsoft app accepts only your organisation (Microsoft's error AADSTS50194),
  the message now says to put its Directory (tenant) ID in Settings → Calendar → Microsoft directory.

## 0.28.49 (2026-10-17)

- Microsoft sign-in (Outlook calendar, the assistant's email, your mailbox): when it fails, the reason now
  stays under the *Connect with Microsoft* button until a sign-in works. Before, it only showed for a few
  seconds after Microsoft sent you back, so the page looked as if nothing had happened.

## 0.28.48 (2026-10-17)

- Channels: a channel's last error shows its time and message on separate lines, so the message is
  translated; the test notification lists one line per channel. More French for the email channel's
  details and the "Set (…). Type to replace." placeholder.
- The assistant's name at the top of the menu was cut after its first word ("Frédéric" for
  "Frédéric OLIER - PIA"): a long name now wraps over two lines in a smaller size, and its full text shows on hover.

## 0.28.47 (2026-10-17)

- Admin page: a new **Name and email…** action renames an assistant. The name is the one shown in the
  assistant switcher (it comes from the admin page, not from the assistant's own Settings) and was only
  set at creation until now. The assistant and the ones linked to it are made again to carry the new name;
  changing only the email restarts nothing.
- One name per assistant: on a shared server, the name set on the admin page is also the assistant's own
  name (top left, emails, notifications, two-step sign-in label). Its *Name* in Settings shows it read-only,
  with where to change it.

## 0.28.46 (2026-10-17)

- Microsoft sign-in for email: choosing *Microsoft 365 account (OAuth)* now also sets the servers to Microsoft's
  (smtp.office365.com port 587 STARTTLS, outlook.office365.com port 993). Before, the old servers were kept,
  so the mail still went to the previous server. The account's token is now only ever sent to Microsoft's
  servers: with another server, sending stops with a message saying which server to change.

## 0.28.45 (2026-10-17)

- Outlook / Microsoft 365 email: the assistant's address and your mailbox can sign in with the Microsoft
  account (OAuth, XOAUTH2 for SMTP and IMAP) instead of a password, which Microsoft is turning off. Email
  settings → *Sign-in* → *Microsoft 365 account (OAuth)*, save, then *Connect with Microsoft*. Uses the
  app set up for the calendar, with the extra permissions *SMTP.Send* and *IMAP.AccessAsUser.All*; each
  mailbox has its own connection, separate from the calendar's.
- Assistant switcher: each assistant has a coloured badge with its initials (from its id), shown at the
  top of the menu. With the menu folded, the badge opens the switcher. The list shows the current
  assistant first, with each one's name and id.

## 0.28.44 (2026-10-17)

- Fix: "Sign in with Claude" hung after pasting the code, then said Claude did not answer in time. Claude Code
  takes a long burst of typed input as a paste, so the Enter sent with the code was pasted instead of pressed and
  the code was never submitted. The code is now typed first and Enter pressed on its own a moment later (and once
  more if nothing happens). A test fake Claude Code now behaves the same way.

## 0.28.43 (2026-10-16)

- **Move a space to another assistant**: space card → *Move…*, pick one of your linked assistants. The browser
  carries the whole space there (conversation and summaries, instructions, notes, settings, tasks with their
  dependencies, history and time, routines and jobs, what the assistant learned, the library folder and the
  documents it points to), then removes it here: the folder goes to the trash, calendar entries are withdrawn, chat
  apps go back to *General*. Name, task code or folder already taken there get a free one; renumbered tasks keep
  their old codes. The AI account and its keys never travel. Without a link (or to another server): *Download the
  package*, *Import a space…* from the space menu on the other side, then *Remove it here*. The transfer uses a
  one-time ticket valid 10 minutes, accepted only from a linked assistant's page; a sign-in ticket cannot be used for it.

## 0.28.42 (2026-10-15)

- **Task filters, reworked**: the endless row of tags is replaced by one filter bar shared by the Today, Board,
  List, Week and Month views: a text search (title, description, code, tags; accents ignored), a tag picker (type
  to choose among all tags, the five most used as chips, the chosen one shown with ✕), a priority floor, and on the
  Board a sort by priority, due date or title. A count shows how many tasks match, and *Clear filters* resets them.
  The List's own priority filter moved into this bar. Agenda events are hidden while a tag or priority is chosen.

## 0.28.41 (2026-10-15)

- **Real costs from the provider** (Anthropic, OpenAI): with an Admin key (Settings → AI model → Budget, or *Real
  costs* in an AI account), the assistant reads what the provider really billed each day, every 6 hours, optionally
  for one workspace or project. Billed days replace the estimates in the budget, today's estimate is corrected from
  the last 30 days, and no bill is asked for. Activity lists each source with what it billed this month and a *Sync
  now* button; a refused key says so and keeps the days already read. Usage now records which settings made it, so
  each source is compared with its own estimates. Admin keys stay on the server, are never sent back to the browser
  and are removed from Claude Code's environment. Mistral and Gemini keep the estimates and the bills you enter.

## 0.28.40 (2026-10-15)

- **One sign-in across a person's assistants** (several people on one server): on the admin page, link a person's
  assistants (work and private, say) with *Their other assistants (one sign-in)…*. The assistant's name in the app
  then lists the others and opens them in the same tab, already signed in. The browser carries a ticket signed for
  that assistant only, valid once for a minute; the assistants never call each other. Links work both ways, and
  removing one changes the keys so earlier tickets stop working.

## 0.28.39 (2026-10-15)

- **Spaces are walled off more strictly** (Confidential and Secret):
  - a model reads a level's files, notes and memory only if it may take that level, also from General and inside
    the space itself (your own searches still find everything);
  - nothing goes down a level: notes, moves and job reports from a stricter space stay in a folder at least as
    protected, and the shared memory, contacts and skills refuse it (the space's notes take it instead);
  - the assistant's learning (Mind) consolidates each Confidential or Secret space on its own, with that space's
    AI account, and keeps what it learns there;
  - routines and jobs of a space the model may not read are not listed and cannot be cancelled, continued or
    archived from elsewhere; a Secret space's folder and activity are not shown outside it;
  - the provider's own web search is off once the work is above Internal; the heartbeat starts each check fresh.

## 0.28.38 (2026-10-15)

- **Mistral batch API**: with a Mistral account (main settings or a space's AI account), summaries and the assistant's
  reflection go through Mistral's batch API at half the price; the worker collects the answers. *Compact now* and
  *Think it over now* still answer at once; a failed batch, or a budget running low, falls back to a direct call.
  Setting: *Mistral: background work at half price* (`TALIXIO_MISTRAL_BATCH`, on by default).
- Spaces are kept apart better: merging old summaries now runs in each summary's own space (its AI account and its
  privacy level), meeting summaries are filed in the meeting's space, and a job's report goes to its space's folder
  instead of the Inbox.

## 0.28.37 (2026-10-15)

- **AI accounts per space**: Settings → *AI model* → *AI accounts* holds named accounts (Claude API, Claude subscription
  with a `claude setup-token` token, OpenAI-compatible, Gemini, European provider). Each space, General included,
  picks one in its settings (General: in the space menu); the others keep the main settings. Two Claude
  subscriptions can serve different spaces, and several European providers can be used at once.
- The privacy checks follow the space's account: a space on a European account may hold confidential work even
  when the main model is a subscription. *Europe only*, when locked, still blocks non-European accounts.
- Keys and tokens stay on the server (the app only sees whether one is set); a field left empty keeps the saved one.
  *Test* tries an account before it is saved. Deleting an account puts its spaces back on the main settings.
  A change of account is noted in the space's history.

## 0.28.36 (2026-10-15)

- New **List** view on the Tasks page: a sortable table (code, title, space, priority, due, load, status) grouped
  by due date (Overdue, Today, Next 7 days, Later, No date), status, space or priority. Quick views (*This week*,
  *Critical & high*, *Waiting*, *No date*), status and priority filters, and a quick-add field. Change a status
  right in the row; keyboard: arrows or j/k to move, x or space to select, Enter to open.
- **Bulk actions**: tick several tasks to set their status, reschedule them, change their priority, move them to
  another space, add a tag or move them to the trash (with *Undo*). Esc clears the selection.
- The task editor opens as a **side panel** on wide screens and as a full-screen sheet on phones, with its title
  and Save button always in view.
- Board with *All spaces*: one **row per space**, each with its open count and hours left; click a space's name to
  fold it (remembered on this device). Dragging a card into another space's row moves it there.

## 0.28.35 (2026-10-15)

- New **Notifications** page: every notification the assistant sends (reminders, scheduled tasks, job reports,
  approvals, alerts) is kept in the app with its full text, newest first. A red number in the menu (on phones, on
  *More*) shows the unread ones; tap one to open its conversation and mark it read, or *Mark all read*, or *Clear*.
  The last 200 are kept, for 60 days; a Secret space's notifications only say where to look.
- The number on the app icon now counts unread notifications plus approvals waiting, and goes away once you
  have read them; opening the page also removes them from the lock screen.

## 0.28.34 (2026-10-15)

- Tasks: new **Week** view to plan time. An hour grid shows the week with your timed tasks and calendar events;
  beside it, "Not planned yet" lists the tasks due this week that have no time. Drag one onto the grid to book a
  slot (snapped to 30 minutes, sized from its estimate), drag a block to move it, or drop it back on the list to
  remove the time. Every change can be undone from the message that confirms it.
- Tasks: the Week view counts booked hours against "Hours a day" (7 by default) and says which days are full.
- Tasks: on a phone the Week view shows one day or three, with a strip of the week's booked hours. Tap a task,
  then tap a free slot to place it.
- Tasks: the Calendar tab is now called **Month**. On a phone the view tabs scroll instead of wrapping.

## 0.28.33 (2026-10-15)

- Tasks: new **Today** view, now the first tab. It shows overdue tasks (with one-click "Today" and "Tomorrow"),
  today's tasks with a checkbox to mark them done, and the next tasks from your overall order. On a computer an
  agenda beside it shows today's timed tasks and calendar events, with a line for the current time.
- Tasks: add a task in one line. "Call Alex tomorrow 9am #sales 30m p2" sets the date, time, space, estimate and
  priority; chips under the field show what was understood, and a click on a chip keeps that part as text.
  English and French words both work. The same field replaces "+ Add a task" at the bottom of each board column.
- Tasks: a **⋯** button on each task (always visible on phones), a long press or a right-click opens a menu to
  change its status, reschedule it (Today, Tomorrow, Next week, a date, no date), open, duplicate or trash it.
  On a phone the menu slides up from the bottom. Trashing shows a "Restore" button in the confirmation.
- Tasks: on touch screens, cards are no longer draggable, so scrolling the board no longer drags a card by mistake.

## 0.28.32 (2026-10-14)

- Linux drive script: with davfs2 1.7.0 and a recent kernel (WSL2 6.x among them), the drive mounted but every `ls`
  failed with "Invalid argument": davfs2 reads folders through a 16 KiB buffer and the kernel asks for 32 KiB.
  The script now sets `buf_size 64` for the mount point in `/etc/davfs2/davfs2.conf`.

## 0.28.31 (2026-10-14)

- Linux drive script: inside a Docker container, where the kernel has FUSE but `/dev/fuse` is not handed over,
  it now says so and gives the options to start the container with (`--device /dev/fuse --cap-add SYS_ADMIN`),
  or to mount the drive on the host and share it with `-v`. Before, it took the WSL path for Windows.

## 0.28.30 (2026-10-14)

- Linux drive script: on WSL without FUSE ("can't open fuse device"), `mount-webdav-linux.sh` no longer stops
  with advice. Windows connects the drive (`net.exe use`, kept at sign-in), and WSL mounts that letter with
  drvfs, plus an fstab line for `sudo mount /mnt/talixio`. A rootless FUSE client would not help, because it
  needs `/dev/fuse` too.

## 0.28.29 (2026-10-14)

- One name per folder, with versions: saving a file under a name already used in its folder (an upload in the library, the assistant saving a note again, the network drive, the shell) now replaces that file in place, with the same id, links and shares. What it held is kept as a version for 30 days, at most 20 per file.
- The open file has a *Versions* section: the date and size of each earlier content, *Download*, and *Restore* (the current content becomes a version in turn). The assistant can list and restore versions too (`kb_versions`).
- *Rename* and *Move* (also by drag and drop) onto a name already used there ask: *Replace* (that file gets this content; its old content becomes a version) or *Keep both* (named "name (2)"). The assistant's `kb_move` keeps both unless asked to replace. Chat attachments keep both.
- Files that already had the same name in a folder are merged once at startup: the newest is kept and the older ones become its versions. Their old links lead to the file that was kept.
- A file restored from the trash onto a name taken meanwhile gets a number instead of a duplicate name.

## 0.28.28 (2026-10-14)

- Notes in other formats: the assistant's `kb_save_note` keeps a `.html`, `.csv`, `.json`, `.xml` or `.txt` name and saves that format, instead of adding `.md` (`page.html.md`). An HTML newsletter now shows as HTML in the library and opens as a web page.
- Renaming a text file to another extension changes its type: a note renamed `page.html` stops being a note (and is no longer shown as Markdown source); renamed back to `.md`, it is a note again.
- Notes already renamed to `.html` (or another non-note extension) are repaired once at startup.

## 0.28.27 (2026-10-14)
Test coverage release: the test suite now runs 97% of the code's lines and 88% of its branches (was 81% / 69%).
- **New tests** in `tests/more/`, run by `tests/run.php` after the main suite: `content.php` (previews, privacy,
  files, Markdown, 123 checks), `fleet.php` (fleet, admin, Claude sign-in, 129), `tools.php` (tools, agent, mind,
  search, Telegram, 248), `web1.php` and `web2.php` (web API, WebDAV, shares, 154 + 235), `misc.php` (worker, CLI,
  Spotify, board, European provider, transcription, doctor, browser proxy, config, connector packages, updates, 247).
  Shared helpers (`check`, `web_app`, `FakeModel`, ports, temp folders) moved to `tests/helpers.php`.
- **WebDAV on Linux**: `deploy/mount-webdav-linux.sh` checks for FUSE first and explains what to do on WSL.
- **Fixes found by the new tests**:
  - Email previews drop the text inside `<script>`, `<style>`, `<head>` and `<title>`, not only the tags.
  - OpenDocument list items with several paragraphs keep them all.
  - Front matter with `#` comment lines is read correctly.
  - The privacy log names a call "Ollama" only when both host and port match.
  - Fleet transfer progress names the instance and the source server; an update records the previous image only
    when there was one.
  - Invalid settings and European provider keys answer 422, so the page shows the field errors.
  - Connector package inspection blanks secret values by variable name (`*PASS*`, `*SECRET*`, `*TOKEN*`, `*KEY*`).

## 0.28.26 (2026-10-14)
Security release, second part: the Low findings of the code review, and some tidying.
- **Shared to the app** (Android share sheet): a share belongs to the browser that sent it (its own cookie), so a
  link to someone else's share opens nothing; each browser keeps its last 5, and at most 50 wait in all.
- **Approvals**: a bare "yes" / "no" answers only a request raised in the same channel (or by a schedule or job);
  "yes 12" still works from anywhere.
- **Two-step sign-in**: turning it on asks for your password.
- **Web app**: cannot be framed by another site (`frame-ancestors`, `X-Frame-Options`), sign-out is POST only,
  a conversation is marked read only by the app itself, and cookies are `Secure` whenever the public address is
  https. Server errors show a reference (the details stay in the log); Telegram does the same.
- **Files shown in the page** only when their content matches their type (images, PDF, audio, plain text);
  anything else downloads. PDFs keep the browser's viewer, which cannot run sandboxed.
- **Links in answers**: `intent://` opens an Android app by its package only, nothing else.
- **Connector sign-in (OAuth)**: the addresses a server hands out stay on its own host or on a public one.
- **Claude Code (subscription)**: reads only the images of the turn; web pages are read with Talixio's
  `fetch_url`, which refuses local addresses, instead of Claude Code's WebFetch.
- **Google, Microsoft and Spotify sign-in** use `TALIXIO_PUBLIC_URL` for the return address when it is set.
- **File names**: one rule everywhere (uploads, renames, WebDAV): "Facture (Mars) & co.pdf" stays as is. New files
  are stored on disk by number.
- Deployment notes on the fleet agent's Docker socket, AppArmor and image tags (deploy/docker/README.md).
- Tidying: exceptions in their own files, unused code removed.

## 0.28.25 (2026-10-14)
Security release: the High and Medium findings of the code review.
- **Shares**: a folder holding a Secret space can no longer be shared, and every opening checks each file again
  (a file moved into a Secret space disappears from links; Confidential needs a password and an end date).
- **Chat by email**: only mail your provider verified is trusted (DMARC pass or your domain's DKIM in the topmost
  `Authentication-Results`); `TALIXIO_EMAIL_AUTHSERV_ID` names the only server to believe.
- **Behind a proxy**: `X-Forwarded-For` / `X-Forwarded-Proto` count only from `TALIXIO_TRUSTED_PROXIES` (default
  `loopback`), and a global lockout stops password guessing spread over many addresses.
- **Updates**: the root script keeps its state in `/var/lib/talixio-update`, never in the app's data folder.
- **Sandbox with internet**: shell commands and scripts count as going outside (approval in Confidential spaces,
  never in Secret ones).
- **Chat links**: link text and addresses in Markdown are escaped, so a crafted link in a reply can no longer
  inject markup or scripts into the page.
- **Approvals** show the whole email (with cc) or event description, not the first lines.
- **Address checks** (web fetches, webhooks, connectors) block IPv4-mapped and NAT64 forms of private addresses.
- **Browser**: all its traffic goes through a small proxy that resolves each host itself and refuses private
  addresses, so DNS rebinding and WebSockets no longer reach the local network.
- **After reading untrusted content** (an email, a web page, a connector's result), lasting changes (memory,
  skills, routines, filing) and fetches of addresses not found in that content wait for your approval. Known limit:
  the page's own links still open without asking, so a form on it submitted by GET is not stopped.
- **Connectors**: every tool asks for approval until you mark it read-only (click it in Settings → Connectors; a ?
  shows what the connector claims, which is only a hint). Local connectors run in a sandbox (bubblewrap) that
  sees only their own folder; `"sandbox": false` lets one out, except on a shared server.
- **Transfers**: a code is good for one copy (a test copy and the final one take two codes), and the copy goes
  over https only (`--allow-http` for a private address on a local network).
- **Shared servers**: the service worker no longer asks for the whole site, and the admin settings warn that the
  one-address mode puts everyone on one web origin (subdomains are recommended unless everyone trusts each other).

## 0.28.24 (2026-10-13)
- Memory page redesigned like Settings: a side menu (chips on a phone) with one section at a time (Character and
  values, Skills, Scripts, Conversation digest, Memory files), each showing what it holds (beliefs established,
  skills, scripts to approve, digest depth, unsaved changes). Search moves to the header and replaces the sections
  while it runs; links such as `#memory/scripts` open a section directly. Memory files gain "Undo changes".

## 0.28.23 (2026-10-13)
- Linux drive script: checks for FUSE before mounting and says what to do when it is missing (on WSL, map the drive
  in Windows and mount it with drvfs) instead of blaming the address or password; no more locale warning.

## 0.28.22 (2026-10-13)
- Network drive on Linux: `deploy/mount-webdav-linux.sh` installs davfs2 and sets up the mount (address with spaces
  and accents encoded, files owned by the user, mount without sudo, run again to change the address or password).
  The FAQ explains it, and how to open a subfolder directly.

## 0.28.21 (2026-10-13)
- Network drive: a device retrying an old saved password no longer locks the drive. Each different wrong password
  counts once, the right password always gets in (it is long and random), Settings › Network drive shows when an
  address is blocked with **Unblock now**, and a new drive password unblocks every address.

## 0.28.20 (2026-10-12)
- Library: the Markdown editor is now Milkdown Crepe (MIT), self-hosted in `web/public/vendor/` and loaded only
  when you edit. Type `/` for headings, lists, quotes, code, tables or a divider; select text for the formatting
  toolbar; edit tables from their handles. **Markdown** switches to the source. A file saved without changes is
  kept byte for byte. The build recipe is in `web/vendor-src/milkdown`.
- Library: Esc closes the editor's menus first and only then the viewer.
- Library: **Use as a script** now confirms in the viewer where the script went (Memory › Scripts), with a
  **Show the script** button that opens it.

## 0.28.19 (2026-10-12)

- Library: Markdown files are edited in the visual editor (headings, lists, checklists, tables, links), with the **MD**
  button for the source; files with front matter, HTML or fenced code with a language open on the source. A file
  saved without changes is kept byte for byte.
- The library editor has a *Full screen* button (Esc leaves full screen before closing the viewer).
- The visual editor keeps `####` headings instead of turning them into `###`.

## 0.28.18 (2026-10-12)

- Updates and restarts no longer silently lose running jobs: a job killed by a restart is started again once (a
  research job carries on from the steps it had done; a job that was already such a restart is not retried again),
  and the user gets one message, in the thread and on their notification channels, listing the interrupted jobs.
- The update waits up to 10 minutes for running jobs to finish before restarting, and starts no new job meanwhile
  (`talixio drain`, released by the restart or `talixio drain off` if the install fails).
- Prompt: Python tools the assistant writes for itself go through `script_save` (stored in /tools with their header,
  shown on the Memory page), never as `.py` files in the library; "outil", "tool" and "simulat…" now bring the
  sandbox tools.
- Prompt: the assistant never says it created, saved or scheduled something unless a tool did it and returned success
  (it had announced three scripts in a library folder that were never written).
- Prompt: for a study or long project, ask the user a numbered list of questions first, keep the report in the folder
  the user named and update it there, and offer a weekly routine to move it forward.

## 0.28.17 (2026-10-12)

- New browser tool `browser_save_login`: after logging in to a site in Talixio's browser (the user gives the code they
  receive), the assistant saves that login's cookies to the file a connector reads (e.g. the `cookiesPath` an Uber Eats
  connector reports), so connectors that ask for a login "in your browser" work on a headless server. Playwright cookie
  format (or storage state if the file already has that shape), only a `.json` file under the server's home or data
  folder, never over a file that isn't a cookie file, and it asks for approval first.
- Prompt: when a connector says it isn't logged in, the assistant does that login in its browser and saves it.

## 0.28.16 (2026-10-12)

- Asked to "look on Uber Eats" for dinner, the assistant asked "shall I proceed?" before checking the connector's
  status. Looking things up (a site, a connector's status, the web, the documents) is now done straight away;
  when a tool fails, the assistant gives its actual error. Without browser tools on the server, it uses a connector
  made for the site if there is one, otherwise says so in one sentence. "regarde sur…", "check on…", a site's
  address and common sites (Uber, Deliveroo, Amazon, Doctolib, SNCF…) bring the browser tools to the first answer.
- It no longer guesses what a postal code means (13006 is Marseille 6e, not Paris 13e): when unsure, it looks it up.

## 0.28.15 (2026-10-12)

- Your own scripts: **New script** and **Upload a .py** on the Memory page, and **Use as a script** on a `.py` of
  the library (under More), which copies it into the assistant's scripts (using it again updates the copy). A
  script you add counts as approved. Without a header, it is named after the file and described by its first
  comment.

## 0.28.14 (2026-10-12)

- Cost estimates that learn from your real bills. Once a month (from the 3rd), the assistant asks at the end of
  an answer what your AI provider actually billed for last month, and records it (`usage_bill`). Talixio
  compares it with its own estimate for the same days, and corrects the budget by that ratio, averaged over the
  last three bills: the estimates get closer to the bill each month. You can also enter a bill under Activity
  (the line below the meters). Bills in euros are converted with Settings → Budget → Euro to dollar rate.
- Reusable scripts. With the sandbox on, the assistant can save the Python code of a mechanical task it does
  again (converting a bank export, tidying a table, a recurring calculation) as a script, and run it next time
  with its arguments instead of rewriting the code. The scripts are listed in its instructions with what they
  do, mounted read-only in the sandbox, and shown on the Memory page (open, edit, delete). With
  `TALIXIO_SHELL=ask`, you approve a script's code once; it then runs without asking until it changes.

## 0.28.13 (2026-10-12)

- Lower model bills through the prompt cache. The time, budget and recalled memories of each turn now come in front
  of the latest user message instead of after the instructions, so the instructions and the earlier conversation
  stay identical from one call to the next, and the provider bills them as cached input (10% of the price with
  Mistral, OpenAI and Claude). With Claude the conversation itself is now cached too, at every tool step.
- With focused tools (the default for European and OpenAI-compatible providers), a tool group given to one message
  stays for the next 30 minutes in that space, so the list of tools doesn't change from message to message and
  break the cache.

## 0.28.12 (2026-10-12)

- Routines are set in plain words: `schedule_task` and `schedule_job` take `when`, like "every weekday at 8:00",
  "every monday and thursday at 18:30", "on the 1st of every month at 9:00", "tomorrow at 14:30", "in 20 minutes",
  or in French "tous les jours à 7h", "du lundi au vendredi à 8h", "demain à 9h". Talixio turns it into the repeat
  itself, so small models no longer get cron fields wrong. The reply lists the next three runs and the prompt that will
  run, so the model can check them; a schedule it cannot read comes back with examples. `cron` and `at` still work.
- Mistral costs much less: Small now does the everyday work (Standard), and Medium the hard turns (Deep), instead of
  Medium and Large. Small costs a tenth of Medium.
- A stronger model when needed (Settings → AI model → advanced, "Stronger model when needed"): with a European provider,
  a message about routines and reminders, a retry ("ça ne marche pas", "try again"), or the turn after a tool failed
  goes to the Deep model, with "Using a stronger model…" shown while it works.

## 0.28.11 (2026-10-11)

- A connector driving a web browser that was installed before its browser was (Uber Eats before 0.28.9) no longer fails
  with "Executable doesn't exist": the first time one of its tools is used, Talixio downloads the browser (about
  100 MB, once), then runs the tool. If the download fails, the reason is shown, and it is tried again an hour later.

## 0.28.10 (2026-10-11)

- Connector list: a "Hosting and cloud" section (OVHcloud, Hostinger; what exists for Scaleway, Infomaniak, o2switch).

## 0.28.9 (2026-10-11)

- Connectors that drive a web browser (Playwright, Patchright, such as Uber Eats) work: installing one also downloads the
  Chromium it was built for, once for all of them, into the data folder. Before, it failed with "Executable doesn't
  exist … npx playwright install". Reinstall such a connector to get its browser.
- Mistral: background jobs no longer fail with "reasoning_effort='low' is not supported for this model". The effort of
  a job is only sent where one is set for the model, and a model that refuses it is asked again without it.

## 0.28.8 (2026-10-11)

- FAQ: a list of connectors that work with Talixio, by topic: French and European public data (data.gouv.fr, company directory, Sirene, addresses, BODACC, public procurement, property sales, SNCF, Eurostat, EUR-Lex…), cities and regions (Paris and its events, Île-de-France, Toulouse…), maps, routes, bike sharing and trains from OpenStreetMap and open data, weather, energy and fuel prices, RSS feeds, films, series, TV schedules and cinema showtimes (where to watch on Netflix or Prime Video, Allociné), Qonto, online services (Notion, Linear, monday.com, Airtable, Todoist, Miro, Canva, Gamma, Calendly, Trello, Stripe, PayPal, Wix, Hugging Face, Wolfram, and how to add Zapier or Make), ferries and activities (Ferryhopper, Peek), jobs (France Travail, Arbeitnow, Remotive), and experimental grocery and delivery ones (Uber Eats, E.Leclerc Drive). It also says what does not exist or cannot be installed yet (Carrefour, Fnac, Darty, EDF, SeLoger, RATP, Google Maps, travel agencies, other banks, Google Drive, Dropbox, LinkedIn, Indeed, APEC, temp agencies…). *Connectors / Connector list* in /FAQ.
- One click installs: **▶ Install** in that list (or in an answer of the assistant) opens the install window and fetches the GitHub repository; for an online service, Talixio asks you to confirm, then adds it and connects to it.
- Asking which connectors exist, or to install one by its name, brings the list to the assistant, which offers the same install links.

## 0.28.7 (2026-10-11)

- Connectors: install one from the address of its GitHub repository. Settings → Connectors → *Install a connector…*, paste `https://github.com/owner/repo` (or `…/tree/main/folder` for a server in a sub-folder) → *Fetch*: it is downloaded into /Connecteurs and installed like a `.zip`. The assistant can do it too ("install the connector https://github.com/…"), after your approval; when the connector needs settings, it gives you the link to fill them in yourself.
- Questions about connectors: the assistant now answers from the FAQ page, which comes with the question, instead of from general knowledge (some models described `git clone`, `npm start`, a `localhost` address and a restart, none of which Talixio needs). The FAQ page says what not to do.

## 0.28.6 (2026-10-11)

- Settings → European provider: a table shows the model behind each tier (fast, standard, deep) with its price per million tokens in and out, and what a typical message and a month of use cost. Prices added for Mistral's current models, Qwen 3.5, DeepSeek and GLM.
- General can now have its own model tier, like the other spaces (space menu → "Model for General"). It was always standard.
- Fewer tokens per message with the European and OpenAI-compatible providers: only the tools a message seems to need are sent (memory, files and web always; mail, calendar, tasks… when mentioned), and the model can ask for the others with `tools_more`. `TALIXIO_TOOLS` (Settings → AI model, advanced): auto, all or focused.

## 0.28.5 (2026-10-11)

- Jobs: a finished job's card no longer squeezes its title into a narrow column; the buttons wrap on the right instead.

## 0.28.4 (2026-10-11)

- Mistral: replies (and job results) no longer show "Array". Its newer models send their answer as a list of chunks, thinking included; only the text is kept now.

## 0.28.3 (2026-10-11)

- A meeting is no longer held back for a missing email address: the assistant creates it without that guest
  and tells you who still needs inviting ("fais sans" and "tant pis" now count as a go-ahead too).
- An answer that reworded the previous one without doing anything is now caught too, and handed to a
  stronger model.
- The assistant can no longer call the same tool with the same details again and again in one answer, and
  when it runs out of steps it now says what is done and what is missing instead of "(stopped after too
  many tool steps)".

## 0.28.2 (2026-10-11)

- The quick model could repeat the same plan after "yes" instead of doing it. A short "yes" / "go ahead"
  now gets at least the standard model, and an answer that only repeats the previous one, with no action
  taken, is handed once to a stronger model, which acts or says plainly what it cannot do.
- The progress messages in the chat ("Reading the attachments…", "Asking a stronger model…") are now
  translated.

## 0.28.1 (2026-10-11)

- Recurring meetings: when asked for a meeting "every Monday until Christmas", the assistant could make one
  task running from the first Monday to Christmas (busy for weeks in your calendar). It now makes the
  series in the calendar (with the guests and the days left out), and a task with a time can no longer
  span more than a week when the assistant makes it.

## 0.28.0 (2026-10-11)

- FAQ: one folder per language in the library, /FAQ/Français and /FAQ/English (every page now also in
  English). The release notes and the reference documents are in /FAQ/English. The assistant cites the page
  in your language. The old folders are replaced on the first start.
- README: screenshots, and what makes Talixio European and GDPR-friendly (your server, AI from Europe only
  with a lock, a log of every outside request, a confidentiality level per space).

## 0.27.0 (2026-10-11)

- Calendar: the assistant can now create recurring meetings (every day, every weekday, every week or
  every month, until a date), leave out some days or the French public holidays, and invite people by
  email. Google Calendar and Outlook send the invitations; with CalDAV the guests are in the event.
  The approval request shows the series and the guests.
- When you answer "yes" to something the assistant proposed, it now does it with its tools instead of
  repeating its plan, and it says plainly what it cannot do.

## 0.26.0 (2026-10-11)

- Outlook / Microsoft 365 calendar: connect it in Settings → Calendar with your own app registered in
  Microsoft Entra (the steps are shown there). The assistant reads your Outlook calendar, adds events to
  it after your approval, and the tasks marked "show in my calendar" go there and are kept up to date
  (all day, or busy at their hours for a meeting), like with Google Calendar. Both can be connected.
- European provider: when it refuses its key (expired or deleted, HTTP 401/403), the chat now says so
  and where to make a new one, instead of the raw error.

## 0.25.0 (2026-10-11)

- Meeting invitations: a task can have guests (email addresses) and a place. "Send the invitations"
  emails them a standard calendar invitation that Outlook, Gmail, Apple Calendar and Thunderbird show
  with Accept / Decline; after a change, "Send the update" sends the new time or place, guests removed
  get a cancellation, and "Cancel the meeting" (or moving the task to the trash) tells everyone. It is
  sent from your own mailbox when it is connected (a copy goes to Sent, the answers come back to you),
  otherwise from the assistant's address on your behalf. Only the title, time and place are sent, never
  the description. The assistant can do it too (board_invite), after your approval. The place also goes
  to Google Calendar and the iCal link.
- European provider: "Test the key" now makes a real call, since some providers (OVHcloud) list their
  models without checking the key: a refused key is reported instead of "it works". The embeddings no
  longer use a local (Ollama) model name with the European provider. Missing French texts added.

## 0.24.0 (2026-10-11)

- Tasks can have hours ("From" / "To" next to the dates): a timed task is a meeting slot. It shows with
  its time in the calendar view and on the board, and in Google Calendar and the iCal link it is a timed
  event marked busy (one hour by default). Entering a start time ticks "Show in my calendar". The
  assistant can set the hours too (start_time / end_time).

## 0.23.5 (2026-10-11)

- Library: "Ask about this document" now attaches the document and leaves the message box empty, with
  "Ask about <name>…" as its hint, instead of a long cut-off line of text.
- Markdown files that start with a YAML header (`---` type, id, title… `---`) show it as a small table of
  properties instead of a huge bold heading.
- Wide screens: the library uses up to 1400px, and the document reader is wider and sits beside the list
  instead of covering it.

## 0.23.4 (2026-10-11)
- Mail: links to a document of the library open it in the app, at its page, on the app's own address. They used
  to point to the server root (without /fred), and some models made up an address like assistant.example.com:
  such made-up addresses are now replaced with the app's. The assistant is also told to keep these links as given.

## 0.23.3 (2026-10-11)
- Europe only: a new option moves search by meaning (Ollama) and transcription from this server to the European
  provider, so a smaller server is enough. Changing the search model re-indexes the library in the background.
- European providers: the fast model is a Mistral model when the provider offers one (gpt-oss, the usual
  alternative, tends to answer in English). The assistant is also told explicitly to answer in the language of
  the user's last message, even when tools and documents are in English.

## 0.23.2 (2026-10-11)
- Europe only: "Run checks" no longer reports "sovereign credentials" as failing with a European provider (the
  check did not know it; nothing was wrong). It now names the provider's key and adds an "EU · GDPR" step that says
  everything stays in Europe, or lists what still leaves it.
- Settings: a "100% Europe · GDPR" stamp next to "Run checks" while nothing leaves Europe (orange, with a count,
  when something still does). With a European provider, the keys and sign-in for providers outside Europe are kept
  but hidden, with a link to show them.
- Connectors running on this server or the local network are no longer counted as leaving Europe.

## 0.23.1 (2026-10-11)
- FAQ: no more copies of the same page ("Page (#12).md" over WebDAV). A sync cut short (or run twice at once) lost
  track of the pages it had made and made them all again; pages are now found again by folder and name, only one
  sync runs at a time, and the copies already there are removed at the next start.

## 0.23.0 (2026-10-11)
- Europe only: a card in Settings → Model and budget switches the conversation model, transcription and memory
  search to one European provider (OVHcloud AI Endpoints by default, Mistral AI, Scaleway, Infomaniak), with a
  Test button, links to get a key and the provider's data terms, and a lock that never sends anything to an AI
  or search service outside Europe. It lists what still leaves Europe (web search, Telegram, Google Calendar,
  Spotify, remote connectors…) with a link to change each one.
- The European provider is also in the regular settings (Provider, transcription, memory search).
- Multi-user server: the admin page can give every assistant one European provider account (key never sent
  back to the page; the Google client secret isn't either anymore).
- List prices for the European providers' models (Mistral, gpt-oss, Qwen, Llama), approximate.

## 0.22.0 (2026-10-11)
- Settings redesigned. Seven sections (Essentials, Model and budget, Channels, Connected accounts, Library
  and memory, Security, System) in a side menu, shown as chips at the top on a phone. A status band at the
  top lists what needs attention, each section shows a dot (ok / to review), advanced settings are folded,
  a search box filters every field, and one Save bar applies all changes at once (with Cancel).
- Old links (#settings/google, #settings/budget…) open the matching new section.
- Network drive (WebDAV): the address shown is now the one the page is open on, instead of the stored
  public address (which could be a placeholder such as assistant.example.com).

## 0.21.4 (2026-10-11)
- Document sheet on a phone: the buttons no longer take half the screen. Only "Ask about it" and a "More"
  button show; "More" unfolds Download, Open in a new tab, Share, Rename, Move, Review and Delete. Margins
  are tighter and the preview uses the full width, with smaller headings on narrow screens.
- Multi-user server: an update no longer breaks the admin page. Moving the admin and agent containers to
  the new version sent Docker empty lists where it expects empty objects (`ExposedPorts`, `Volumes`…),
  which Docker refused (HTTP 400). The update now also reloads Caddy, so its new routes apply right away:
  this is what kept the WebDAV fixes of 0.21.2 from reaching servers updated from the admin page.
  To recover a server where the update failed: `git pull` then `sudo deploy/docker/install-stack.sh`.

## 0.21.3 (2026-10-11)
- Emails look better. The messages the assistant sends by email (scheduled jobs, morning briefing, reports,
  notifications, answers to emails) now come in a proper HTML layout: a card with the title, the space and
  its color, a button that opens the app, and the message's full Markdown (headings, nested lists, tables,
  quotes, code, task lists, italics), all styled inline so Gmail and Outlook show it as intended. Links to
  pages of the app are made absolute so they work from a mail app. Mail sent on your behalf from your
  mailbox gets the same formatting without the Talixio frame.

## 0.21.2 (2026-10-11)
- Network drive (WebDAV) on Windows: Windows could not map `https://…/<id>/dav/` on a multi-user server
  because it first probes the folders above (`/` and `/<id>`), which answered "not found" or redirected.
  Caddy now answers those probes like a WebDAV server, `/<id>` reaches the assistant without a redirect for
  WebDAV requests, and the assistant answers OPTIONS before sign-in. Run the update so Caddy picks up the
  new configuration. In Settings, a new attempt to create a drive password hides the previous one, so an
  "incorrect password" message is no longer shown next to an old drive password.

## 0.21.1 (2026-10-11)
- Admin page (multi-user server): an update cut short, e.g. because `install-stack.sh` was run from the
  command line while it was building and replaced the agent, no longer stays "in progress" forever and blocks
  the Update buttons. The agent now records the job's process and shows a job whose process is gone as
  interrupted, so a new update can start. `install-stack.sh` also records the image it built, so "Running…"
  in the Update tab shows it rather than the last update made from the admin page.

## 0.21.0 (2026-10-11)
- Network drive (WebDAV): the library's folders open as a drive in Windows Explorer, macOS Finder, the Files
  app on iPhone/iPad and WebDAV apps on Android, at `<address>/dav/`. Settings → Network drive: off, read only
  or read and write, and its own password (shown once, needs the app password). Files added or changed there
  are read and indexed again like uploads; moves and renames keep the index; deletions go to the library's
  trash. Secret spaces never show, the FAQ stays read only, system clutter (.DS_Store, ._ files, Thumbs.db…)
  is kept out of the library, wrong passwords are throttled per address.
- File names: renaming keeps any character but slashes, control characters and `<>:"|?*`.

## 0.20.0 (2026-10-10)
- Quick capture: a chat message starting with `t:` (or `task:`, `todo:`, `tâche:`) adds a task to the space's
  board, `n:` (or `note:`) saves a note in the space's folder or the Inbox, at once and without calling the
  model. `!1`..`!5` sets the priority; the first line is the title or the note's name. A **+** button in the
  top bar and a "Capture" shortcut of the installed app open the same capture as a dialog.
- Routines learn from 👍/👎: each line of a message sent by a routine (shown as cards) can be rated; the votes
  stay on the message, are listed (and removable) per routine on the Routines page, and are given to the
  routine's next runs. New assistant tool `routine_feedback` for feedback said in words.
- Morning briefing: 5 lines at most, most important first, one emoji per kind. Existing briefings are updated
  (what you added to them is kept).
- Weekly review, ready-made on the Routines page (Sunday 18:00 by default): done, slipped, the week ahead,
  waiting emails and 3 priorities; the full review is saved as a note in /Notes/Weekly reviews.
- Watch and draft: a ready-made routine that follows topics (sources, audience, format, daily/weekly/monthly)
  and has a background job write the draft of the next issue in /Drafts/<name> (default name "Fil IA").
  Several watches can be set up and edited.
- "Where is…?": the library search lists the folders and files whose **name** matches first (accents and case
  ignored), then the content matches. New assistant tool `kb_find`; folder links in the chat open the folder.

## 0.19.9 (2026-10-10)
- Library: moving a file (and choosing a folder for a new folder, a renamed folder or a space) shows the whole
  folder tree under the field, indented, filtered as you type, with the keyboard or a click. The old list
  was empty because it only kept the folders matching the current path. A path that does not exist yet
  is still accepted and created on save.

## 0.19.8 (2026-10-10)
- Jobs page: search (title, instructions, report), Active / Archived switch, and per finished job: the link
  to its report note (found by id, so it follows the note when it is moved; says so when it was deleted),
  *Archive* / *Unarchive*, *Delete* (the report note and the usage history stay) and *Schedule…*, which
  makes a routine that runs the job again once or on a repeat, without going through the model.
  *Archive finished* puts away every finished job of the view; finished jobs older than 30 days are
  archived on their own. New assistant tools `archive_job` and `schedule_job`; `list_jobs` can search and
  show archived jobs and gives the report links.

## 0.19.7 (2026-10-10)
- Jobs: the full report is saved as a note in the library when the job finishes, so nothing is lost when the
  chat relays it in short: in the Inbox ("Job - title - date"), in the folder the user named when asking
  (new `folder` option of `delegate`, kept by "run again" and "continue"), or next to the recording for a
  meeting summary. The assistant gives the link and can file it elsewhere.

## 0.19.6 (2026-10-10)
- Docker: updating from the admin page now also moves the admin page and its agent to the new version (they
  stayed on the image of the installation, so new admin features never showed). The agent replaces itself
  last; if the new one does not answer, the old one stays and the job says so.

## 0.19.5 (2026-10-10)
- Admin page, Updates: says whether the branch has a newer version than the one running (its number, date
  and last change) and lists the changelog entries in between; checked when the tab opens and with
  *Check now*. Only the branch's last commit is fetched (and its files only when it changed).
- FAQ (Audio and meetings): the transcription models available for Gemini and whisper.cpp, and which to pick.

## 0.19.4 (2026-10-10)
- Admin page: **Log** in each assistant's Actions menu shows the end of its log (errors, warnings and failed
  requests; every line on demand), without a terminal on the server. Reading it is in the audit trail.
- A file that cannot be read (transcription, image reading, PDF…) now writes why in the log.
- Transcription with Gemini: a whisper.cpp model name left in "Transcription model" is no longer sent to
  Gemini (it was rejected); the Gemini model is then chosen automatically.

## 0.19.3 (2026-10-10)
- Docker: updating from the admin page works with a private repository: put a read-only token in
  `GIT_TOKEN` in `deploy/docker/.env` and run `install-stack.sh` once; the agent sends it as a header when it
  clones (not in the address or the command line). Without it, the error now says what to do instead of
  "could not read Username".

## 0.19.2 (2026-10-10)
- Searching the conversation (the magnifier in the chat) and the Memory page: the messages with the words
  show at once, with a spinner while search by meaning completes the list; a new search cancels the
  previous one (late answers are dropped). Before, nothing showed until the slow part was done.
- Connectors: tools named like reads (`list_…`, `get_…`, `search_…`) run without asking for approval when the
  server does not say otherwise; the assistant then answers from the result directly.
- An approved action's result is kept whole in the conversation (up to 20 000 characters, JSON indented in a
  scrolling block), so the assistant can read all of it; before, it was cut at 600 characters.

## 0.19.1 (2026-10-10)
- Docker: faster updates. The dependencies (system packages, Chromium, LibreOffice, whisper.cpp,
  signal-cli…) are an image of their own, `talixio-base:<hash of deploy/docker/base.Dockerfile>`, built only
  when that file changes; the application image starts from it and only copies the code. Before, an update
  from the admin page used another builder than `install-stack.sh` (no shared cache) and pulled Debian
  again, so everything was often rebuilt. The admin page now builds from a copy of the branch, with the
  right version shown. `install-stack.sh --rebuild-base` rebuilds the dependencies on purpose.

## 0.19.0 (2026-10-10)
- A trash for the library: deleting a file or a folder moves it to the trash (bottom of the folder tree;
  a *Trash* button on phones) for 30 days. It leaves searches, the assistant and shared links meanwhile.
  *Restore* puts it back where it was (a folder comes back whole); *Empty the trash* deletes for good.
  The assistant can list and restore it too (`kb_trash`).

## 0.18.0 (2026-10-10)
- Install your own connector without an administrator: put the MCP server's folder as a .zip in the library
  (e.g. /Connecteurs), then *Install as a connector* on the file (or Settings → Connectors → *Install from
  the library…*). Talixio finds how to start it and the settings it reads (address, account, password),
  unpacks it in the assistant, installs its Node dependencies (builds it if needed), declares it and lists
  its tools. Reinstalling the same name updates it and keeps a password left empty.
- Connectors may give a working folder relative to the data folder: they keep working after a move.

## 0.17.0 (2026-10-10)
- Settings → Web search and memory: a choice for search by meaning. On this server (Ollama): very light
  (multilingual-e5-small, ≈ 0.3 GB), light (EmbeddingGemma, ≈ 0.7 GB) or best quality (Qwen3-Embedding,
  ≈ 1.5 GB); online: Gemini, OpenAI, Voyage; or off. Each says what it needs; a local model is downloaded
  when chosen; switching re-indexes in the background, with the progress shown.
- *Measure on this server*: the time of a search, of indexing a passage, and of comparing every vector.
- sqlite-vec (Docker image, and `install.sh`): SQLite compares the vectors in C, about 6 times faster than
  PHP (20 000 passages: 0.15 s instead of 0.95 s on a test machine). Without it, PHP still does it.
- Docker: Ollama is limited to half the processors (`OLLAMA_CPUS`); turned off in the admin page, it is
  stopped and its memory goes to the assistants. The admin page picks the shared model among the three and
  lists those downloaded.
- e5 models get their `query:` / `passage:` prefixes.

## 0.16.3 (2026-10-10)
- Library search in two passes: documents with the words show at once, then the search by meaning (the
  local model, slower) completes the list. A spinner says it is running; *Cancel* (or Esc) stops it and keeps
  what was found.
- One search at a time: opening another folder or starting another search stops the running one, and an
  answer that comes late is dropped. Results and the folder's list are never shown together any more (a slow
  search used to land on top of the folder opened meanwhile). *Close the search* goes back to the folder.

## 0.16.2 (2026-10-10)
- Admin page, *Update everyone*: the branch field shows the branch running now (or the one the stack was
  installed from) instead of always `main`. Running `install-stack.sh` again records the branch the clone is on.

## 0.16.1 (2026-10-10)
- Archiving a space asks for a second click: it was one click, next to *Delete*, and easy to hit by mistake.
- An archived space says so above its conversation, with *Restore*.
- Each space keeps a history (created, renamed, archived, restored, confidentiality or folder changed: when,
  and whether from the app or by the assistant), shown in its settings and in the log.
- The assistant can list the spaces (archived ones included), tell what happened to one, and restore one
  (`spaces` tool). The FAQ page on spaces explains archiving.

## 0.16.0 (2026-10-10)
- Sign in with Claude: Settings → AI model (or the card in the conversation) runs Claude Code's own sign-in
  for a Pro/Max plan from the web app: open Claude's page, paste the code back, done. No terminal needed.
- No model set up: the conversation says so and how to fix it (a card with *Sign in with Claude* and
  *Use an API key*), instead of an API error.
- Documents found again after the data folder moves (install.sh → Docker, a transfer): their paths are
  updated at start. A migrated library was listed but its files could not be opened.
- Library: the folder tree folds and unfolds (remembered per device; the current folder's path stays open).
- Docker image: signal-cli falls back to its Java build when the native one cannot run on the processor
  (virtual machines with an older processor model); the build no longer fails on it.
- Background actions (admin page jobs, Claude sign-in) are fully detached from the web server.

## 0.15.1 (2026-10-10)
- Admin page: a Server panel with memory, processors (load, model) and disk (images, data, build cache), whether
  the server is well sized for the people on it (limits promised against the machine, load, swap, free disk) and
  how many more people fit; the shared services (Caddy, Ollama, agent, admin page): answering or not, in how long,
  restarts, memory. Each person shows whether their assistant answers, its last check time, restarts and
  out-of-memory stops.

## 0.15.0 (2026-10-10)
- FAQ in the library (/FAQ, read-only, in French): using the assistant, privacy, installing and updating,
  administration, and one page per version (release notes). Kept up to date with each version and indexed:
  ask the assistant about itself and it answers from these pages, citing them.
- Moving an assistant to another server, whole (conversations, memory, skills, library, tasks, settings and
  keys, password): Settings → Move to another server makes a transfer code; on the new server, admin page →
  From another server (or `talixio transfer-pull`). Test copies stay paused (no channels, no routines) and
  can be repeated; the final one pauses the old server and points it to the new address.

## 0.14.1 (2026-10-10)
- Moving an installation into the Docker stack: the settings of `/etc/talixio/talixio.env` were not imported
  (the file is readable by root only).

## 0.14.0 (2026-10-10)
- The Docker image includes whisper.cpp (transcription on the server, the model shared by every assistant
  in one volume) and signal-cli (Signal channel). Moving an install made with `--with-whisper` reuses its model.
- Local transcription uses only the processors the container is given, and says when the memory is too small.

## 0.13.1 (2026-10-10)
- Docker stack behind a reverse proxy you already have (`install-stack.sh --behind-proxy 8080`): plain HTTP on
  one port, HTTPS stays on your proxy.

## 0.13.0 (2026-10-10)
- Privacy: a page showing where your data goes (worked out from your settings), what each service may do with
  it and how to fix it; a journal of every request to the outside (never the content), CSV export, and a record
  of processing (GDPR) to start from.
- A confidentiality level per space: Internal, Confidential (only a business-terms model reads the content, the
  web needs your approval each time, local transcription and indexing, silent notifications, protected links),
  Secret (nothing leaves the server). Enforced on every outside request.
- Under each answer: where it went, which documents were given to the model, what stayed here or was blocked;
  live while it answers.
- Settings: "My Gemini key is on a paid plan", "Notifications without content".

## 0.12.1 (2026-10-10)
- Fix: an installed app stuck on an older version (frozen in French by the 0.11 translation loop) now picks up
  the fixed scripts as soon as the server is updated: their address changes with each version.

## 0.12.0 (2026-10-10)
- Local transcription with whisper.cpp (`sudo ./install.sh --with-whisper`, then Settings → Audio → Transcription →
  Local): voice notes and meetings are transcribed on your server, nothing is sent out. Slower, no speaker names.
- Gemini transcription no longer pays for thinking tokens.

## 0.11.2 (2026-10-10)
- Fix: in French, the page could freeze when switching spaces (the message field's hint was translated again and
  again). The interface never translates its own translations any more.

## 0.11.1 (2026-10-10)
- Fix: after an update, the installed app could show the new page with the previous version's scripts until
  "Reload" was pressed, so new buttons (Share) did nothing. A page now always gets the scripts of its own version.

## 0.11.0 (2026-10-10)
- Share library documents with people outside: a link to a file or a folder (with its subfolders), for a
  set time or without end, optional password, view only or download, a message for them. Library →
  Share / Shares: copy, email, extend, revoke, and who opened what. You are told the first time a link is
  opened. The assistant can make links too (`share_create`), always with your approval.

## 0.10.0 (2026-10-10)
- Interface in French (Settings → Assistant → Interface language; automatic = the browser's).
- Character and values learned by itself from the history, a character per space (Memory page).
- Jobs: cost cap and effort per job, warned at 80%, continue a job after its cap.
- Connectors: sign in to remote MCP servers with OAuth.
- Several people on one server: Docker stack with an admin page; the app can run under a prefix.
- Tasks: a board per space (codes, priorities, workload, dependencies, overall order), calendar view, reminders.
- Spotify and phone buttons; mail to handle, people, morning briefing.
- Library: knowledge base with folders, real previews, citations with pages, filing of the inbox.
- Spaces, sandboxed shell, Google Calendar, chat comforts, compaction with progressive forgetting,
  two-step sign-in, installable app (PWA), local embeddings with Ollama.

## 0.9 (2026-10-09)
- Full configuration from the web app, self-update from the git repository.

## 0.8 and before (2026-10-08)
- Skills and nightly backups (0.8), headless browser (0.7), approvals, calendar and mailbox (0.6), live answers,
  attachments, voice notes and meeting summaries (0.5), email, web push and Signal (0.4), memory search, model
  tiers, budgets and sub-agents (0.3), web app, scheduler and install.sh (0.2).
