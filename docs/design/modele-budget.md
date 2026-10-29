# Modèle et budget — the model cockpit

> Status: shipped, Oct 2026 (slices 1–5, versions 1.7.0–1.11.0; 1.11.0 made the
> Fournisseurs tab an inline per-card connect surface and moved the Europe lock to
> the Modèles tab). This is the
> **human-facing UI and routing engine** over the providers and AI-accounts the
> assistant already reaches. Nothing here opens a new trust edge: a model is only
> ever reached through a credential source the person already configured (the main
> settings or an AI account). It surfaces, compares and routes what the services
> already expose.

## 1. Why — one page to choose and route models

`Réglages → Modèle et budget` (`#view-settings`, the `model` section) is where a
person picks which model answers, reads images, transcribes and reasons — and what
that costs. Before this it was a single provider + three tier dropdowns. Now it is a
catalogue the person browses, compares on price and trust, and assigns from, mixing
providers freely per usage.

The section carries its own three tabs (`.mb-tab[data-mtab]`, wired once by
`wireMbTabs`, shown by `mbApplyTab`):

- **Modèles** (`#mb-models`, `data-mtab="models"`) — the catalogue. The heart.
  It also holds the **Europe lock** (§5).
- **Fournisseurs** (`data-mtab="providers"`) — a plain **connect surface**: the
  **provider-card grid** (`#mb-pgrid`, `mbRenderProviders`), one card per catalogue
  provider (flag, name, country · billing, trust / kind / model-count chips using
  `.pchip`, never the red `.badge`), plus a dashed **« + Ajouter un fournisseur sur
  mesure »** card. Each card connects **inline**, on the card itself:
  - A connected provider shows **✓ Connecté** (and a **Gérer** link when it maps to
    an editable account) — no lifecycle clutter.
  - A mappable provider (sovereign/European, Claude, OpenAI, Gemini) shows
    **Se connecter** / **Connect**; the button (`[data-popen]`, `mbToggleConnect`)
    reveals an inline `<form class="pconnect">` right on the card with a single key
    field, a **Créer un compte** sign-up link, and Connect/Cancel. Submitting
    (`mbProviderConnect`) POSTs through the one uniform account path
    (`POST /api/accounts`, kind from `MB_KIND` / sovereign service), then refreshes
    the grid and the Modèles table (`mbRefreshProviders`).
  - An exotic provider (local, DeepSeek, HuggingFace, ElevenLabs) falls back to the
    advanced account form (`#acc-card`, `[data-pcustom]` → `mbOpenAccountForm`); the
    **+ sur mesure** card opens the same form with no kind pre-chosen.

  The `#eu-card` (Europe provider switch) and the `brain`/« Modèle d'IA » group-card
  are **no longer shown on this tab** (`mbApplyTab` hides `#eu-card`, `#acc-manage`,
  and the brain card); the separate « Comptes d'IA » management card and the old
  **« Tout passer en Europe »** one-click button are gone from here. Connecting an
  account is the only thing this tab does — still the existing credential flow, so no
  new trust edge; choosing which connected model answers each usage lives in Modèles.
- **Budget** (`data-mtab="budget"`) — the existing `budget` group-card, with a live
  « dépensé ce mois-ci » glance (`mbBudgetSnapshot`, from `state.status.budget`).

Loaders live in `app.js`: `loadModelCockpit` → `mbRender` (scopebar + usage cards +
filters + table), `mbRenderUsages`, `mbRenderRows`.

## 2. The catalogue (`ModelCatalog`, `GET /api/catalogue`)

`catalogue()` returns `{providers, models, updated, source}`.

- **providers** — a seed of a dozen (`PROVIDERS`): id, name, country, `flag`,
  `tl` (trust level), `kind`, `bill`. Each is decorated with the `sources` that can
  serve it (the credentials a model of that provider routes through) and a
  `connected` flag.
- **models** — a hand-kept seed (`MODELS`) folded with any live-discovered ids
  (§4). Each carries `name`, a canonical **`id`** (the API model string; it falls
  back to `name` for the providers whose display name already *is* the API string —
  only Anthropic keeps a separate display name and API id), `prov`, `type`
  (LLM / Vision / Voice / Image / Embedding), `rank` (1 basic → 10 flagship), a price
  (`pin`/`pout` per Mtok, or `unit`+`unitKind` for voice/image), `est` when the
  price is an estimate, and `available` when its provider has a source.
- The frontend keys model identity on `mbId = m => m.id || m.name`; the canonical
  `id` keeps that single across providers (routing, assignment, dedup).

**Trust levels (`tl`).** 1 consumer (e.g. DeepSeek, China) · 2 free/public ·
3 business (OpenAI, Google) · 4 business no-retention (Anthropic) · 5 sovereign
(GDPR: Mistral, Scaleway, OVHcloud, Infomaniak, **AKI.IO**) · **L** local. Rendered
by `mbTlClass`/`mbTlText`, ranked for the floor by `mbTlRank`.

## 3. Routing (`GET`/`POST /api/routing`, scope global + per-space)

A binding is `{account, model}` per usage (**fast / standard / deep / voice**).
`App::tierFor($usage, $space)` resolves: per-space override → global default →
the space's own config. The scope selector (`#mb-scope`) switches between the global
default and a per-space override; overridden usages are tagged, a reset
(`clear:true`) returns them to the global default. The usage cards at the top show
what each usage resolves to *now* and update live on assign. The row's **F / S / A /
V** buttons assign that model to a usage (`mbAssign`, routed through the provider's
first source); a disabled button means the usage's type doesn't fit, the provider
isn't connected, or the Europe lock blocks it.

A slice-1 gap is closed so a cross-provider binding reaches the **resolved
provider's own credentials**, not the space's (`App::provider(Tier, ?Config)` takes
the resolved `$cfg`).

## 4. Catalogue source: seed + live refresh

The table is seed + the connected providers' live `GET /models` lists, folded in by
`liveModels()`:

- Anthropic keeps its first-party seed (accurate prices, a different API); every
  other provider is OpenAI-compatible.
- A live id the seed already covers (same provider, case-insensitive on id-or-name)
  is dropped so the hand-kept row (real price/rank) wins.
- A folded id is `classify()`-d by name into Voice / Image / Vision / Embedding / LLM;
  moderation, rerankers, guards and tokenizers are dropped (no usage runs on them),
  but embeddings are kept (§7).
  A folded model is `est` (no confirmed price/rank) and `available`.
- Each provider is fetched independently (one bad key can't blank the others); the
  fetch is tagged a model call for the privacy journal (`Privacy::as('model', …)`).

`refresh($now)` runs **weekly** in the worker (`catalogue_last` guard) and on demand
from the Modèles tab's **« Actualiser maintenant »** button
(`POST /api/catalogue/refresh`), stamping `catalogue_updated` (the « mis à jour le »
date). `source` is `live` once a refresh has stored a list, else `seed`. The header only
shows a « mis à jour le » date when `source === 'live'`; while the list is still the
pure seed it reads « Catalogue de base — … » instead of a misleading date, so a
freshly-connected provider shows its seed rows (the full AKI catalogue, §6) at once and
« Actualiser maintenant » folds in anything beyond the seed.

## 5. Filters and the Europe (GDPR) lock

Filters (`mbVisible`): free-text search, type (LLM / Vision / Voice / Image /
**Embedding**, §7), provider, trust floor, and an **Available** toggle (on by default —
untick to see the whole catalogue). The table sorts on any column.

The **Verrou Europe (RGPD)** lives in the Modèles tab (`mbToggleLock`, `#mb-lock-cb`).
It does three things at once:

1. raises the table's trust floor to 5 (`mbCompliant`: trust ≥ 5, sovereign or local);
2. force-swaps any current non-compliant selection to the best (highest-rank)
   compliant + available model that can serve the usage (`mbBestCompliant`), reporting
   each swap in a toast;
3. sets the **standalone server-side guard** via `POST /api/sovereign/lock`
   (`Web::sovereignLock` → `Settings::save` of `TALIXIO_SOVEREIGN_ONLY`), so Privacy
   refuses any non-European AI or search service at runtime.

This is where the Europe lock moved from the old Fournisseurs « Tout passer en
Europe » button — but it is now a *standalone* guard: it no longer forces
`TALIXIO_PROVIDER=sovereign`, so you can lock to Europe without changing which
provider answers (that is what the old one-click switch, `POST /api/sovereign/apply`,
still does when you deliberately switch your main model). `showEu` / `mbSyncLock`
keep the table checkbox and `state.eu.only` mirrored so the lock reads the same
whichever way it was last set.

## 6. AKI.IO — German-resident by default

AKI.IO (`aki`) is a sovereign provider (`Sovereign::SERVICES['aki']`, trust 5,
Germany). Its subtlety: **only models tagged `tl5` are processed in Germany**; the
rest can be served from Alibaba Cloud in Singapore, which leaves Europe. So
`Sovereign::choose('aki', …)` keeps only the `tl5` ids when the key lists any, and
falls back to TL5 ids otherwise — an AKI assistant stays in Germany by default. The
catalogue seeds AKI with its **full documented model list** (its OpenAI-compatible
`GET /models` catalogue): nine LLMs (GPT-OSS 120B, Deepseek V4.1 Flash, GLM 5.3 754B,
Mistral 4 119B, Gemma 4 26B, Qwen 3.8 27B, Kolibri 1 TL5, and the EOL-flagged
Qwen 3.6 35B A3B and Apertus 70B) and four € image models (Flux.2 [klein], Qwen Image,
Qwen Image Edit, Z-Image Turbo). Each seed row carries its **exact API model string**
as `id` (e.g. `gpt-oss-120b`, `deepseek-v4.1-flash-1017-tl5`), so routing uses the
real id and a live refresh dedups against the seed. Embedding models (bge-m3,
qwen3-vl-embedding-8b) are now listed too, as the **Embedding** type (§7). Prices are
estimates (`est`). AKI is reached like any sovereign provider (Fournisseurs tab → the AKI card's
inline Connect, which creates an AI account of kind `sovereign`, service `aki`), and it
satisfies the Europe lock (trust 5).

## 7. Embeddings in the catalogue

Embeddings are a real usage — library search, the latent classifier and Mind all
run on them — so the catalogue lists them as their own **Embedding** type
(`MB_TYPE_USAGE.Embedding = []`, like Image). They are priced per **Mtok** of input
(`unit` + `unitKind: 'Mtok'`), shown in the «≈ / message» column with «—» for
input/output, and the type filter gains an **Embeddings** option. The seed carries the
options the assistant can actually use — OpenAI `text-embedding-3-small`, Google
`gemini-embedding-001`, AKI `bge-m3` and `qwen3-vl-embedding-8b`, and the local
`qwen3-embedding:0.6b` / `embeddinggemma` — and `classify()` now types live-discovered
embedding ids as Embedding (while still dropping moderation, rerankers, guards and
tokenizers).

> **Superseded in 1.14.0 / 1.15.0.** Embeddings (and image reading, and transcription) are now
> assigned from the table like any other usage — see §8.

## 8. Voice, vision and embedding are routed usages (1.14.0)

`Routing::USAGES` is now `fast, standard, deep, voice, vision, embedding`. A binding is
`{account, model}`; `voice` and `embedding` add `via` (`local | ollama | openai | gemini | sovereign`),
how the model is reached when the account alone doesn't say (`local` = whisper.cpp / Ollama on this server,
which has no AI account — the main settings stand for it).

- **voice** — *this is transcription.* `App::voiceCfg()` derives a Config from the binding (European account →
  OpenAI-compatible audio API; OpenAI; Gemini; `local` → whisper.cpp) and `Transcriber` reads it instead of the main
  settings. Before 1.14.0 the **V** button stored a binding that nothing read. A binding on a provider that cannot
  transcribe (Claude, a speech-synthesis service) is ignored: the main settings keep working. Privacy, the Europe
  checks and the doctor read `voiceCfg()` too, so they report where recordings really go. The meeting-summary tier and
  the 180 s threshold stay plain settings (they are not a model).
- **vision** — reading photos and scanned PDFs (`Files::vision`) goes through `App::tierFor('vision')`: the vision
  binding's model, else the **fast** tier, i.e. exactly what it did before. The on/off switch stays `TALIXIO_VISION_INDEX`.
- **embedding** — `App::embedCfg()` derives the config search by meaning runs on (binding → backend by `via` or by the
  account's provider; dims, Ollama address and Voyage key still come from the main settings). **Global only**: one
  index needs one model, so a space can't have its own (`Routing::set` refuses it). `Embeddings::model()` already keys
  the index by backend + model + size, so changing the binding rebuilds it in the background; `App::routingChanged()`
  swaps the live `Embeddings`. A local model not yet downloaded is pulled (this server's own Ollama only). Choosing a
  preset in Settings (`Meaning::choose`) clears the binding, so there is one source of truth at a time.

Catalogue rows of type Voice / Embedding carry `via` (empty = can't be chosen for that job); the local whisper.cpp and
Ollama rows carry a built-in source (`account ''`) and are available when whisper is installed / always (the UI greys
Ollama rows while Ollama is down). European Whisper and embedding models were added to the seed with no invented prices.

## Deliberate divergences from the concept mockup

The mockup (`scratchpad/fleet-ui-mockups.html`; not in the repo) is a concept sketch.
As shipped: the three tabs are **Modèles / Fournisseurs / Budget**. Fournisseurs is
the mockup's provider-card grid, but each card now **connects inline** (key field on
the card itself) — the separate credential cards, the « Comptes d'IA » management
card and the « Tout passer en Europe » button that earlier sat below it are gone from
this tab; Budget reuses the existing card. The per-usage picker is the row's inline
**F/S/A/V** buttons plus the live usage cards, not a separate drawer. The Europe lock
is a single Modèles-tab toggle that raises the filter floor, force-swaps live
selections, **and** sets the standalone server-side guard
(`POST /api/sovereign/lock`).
