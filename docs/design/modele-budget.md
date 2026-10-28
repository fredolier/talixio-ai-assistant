# Modèle et budget — the model cockpit

> Status: shipped, Oct 2026 (slices 1–4, versions 1.7.0–1.9.x). This is the
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
- **Fournisseurs** (`data-mtab="providers"`) — surfaces the existing `#eu-card`
  (Europe-only switch), `#acc-card` (AI-account connect/sign-in cards) and the
  `brain` group-card. No new UI: connecting a provider is the existing flow.
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
  (LLM / Vision / Voice / Image), `rank` (1 basic → 10 flagship), a price
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
- A folded id is `classify()`-d by name into Voice / Image / Vision / LLM;
  embeddings, moderation, rerankers and the like are dropped (no usage runs on them).
  A folded model is `est` (no confirmed price/rank) and `available`.
- Each provider is fetched independently (one bad key can't blank the others); the
  fetch is tagged a model call for the privacy journal (`Privacy::as('model', …)`).

`refresh($now)` runs **weekly** in the worker (`catalogue_last` guard) and on demand
from the Modèles tab's **« Actualiser maintenant »** button
(`POST /api/catalogue/refresh`), stamping `catalogue_updated` (the « mis à jour le »
date). `source` is `live` once a refresh has stored a list, else `seed`.

## 5. Filters and the Europe (GDPR) lock

Filters (`mbVisible`): free-text search, type, provider, trust floor, and an
**Available** toggle (on by default — untick to see the whole catalogue). The table
sorts on any column.

The **Verrou Europe (RGPD)** (`mbToggleLock`) raises the trust floor to 5 and
force-swaps any current non-compliant selection to the best (highest-rank)
compliant + available model that can serve the usage (`mbBestCompliant`), reporting
each swap in a toast. Compliance is `mbCompliant` (trust ≥ 5, i.e. sovereign or
local).

## 6. AKI.IO — German-resident by default

AKI.IO (`aki`) is a sovereign provider (`Sovereign::SERVICES['aki']`, trust 5,
Germany). Its subtlety: **only models tagged `tl5` are processed in Germany**; the
rest can be served from Alibaba Cloud in Singapore, which leaves Europe. So
`Sovereign::choose('aki', …)` keeps only the `tl5` ids when the key lists any, and
falls back to TL5 ids otherwise — an AKI assistant stays in Germany by default. The
catalogue seeds AKI with four EU-priced models (GPT-OSS 120B, MiniMax M2.5 230B,
Apertus 70B, and the € image model Flux.2 [klein]); it is reached like any sovereign
provider (Fournisseurs tab → Europe provider switch, or an AI account of kind
`sovereign`, service `aki`), and it satisfies the Europe lock (trust 5).

## Deliberate divergences from the concept mockup

The mockup (`scratchpad/fleet-ui-mockups.html`; not in the repo) is a concept sketch.
As shipped: the three tabs are **Modèles / Fournisseurs / Budget** (Fournisseurs and
Budget reuse the existing cards rather than new panels); the per-usage picker is the
row's inline **F/S/A/V** buttons plus the live usage cards, not a separate drawer;
and the Europe lock is a single toggle that both raises the filter floor and
force-swaps live selections.
