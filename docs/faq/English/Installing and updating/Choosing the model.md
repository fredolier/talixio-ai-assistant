# Choosing the model (the "brain")

## The simplest way: sign in with Claude
With a Claude Pro or Max subscription: Settings → *Model and budget* → **Sign in with Claude**
(or the button shown in the chat while no model is set).
1. Open the Claude sign-in page and accept.
2. Claude shows a code: copy it, paste it into Talixio, then *Connect*.
This is the official Claude Code sign-in (installed on the server, and included in the Docker image);
Talixio never sees your password. The token you get is valid for one year.

Usage counts against your subscription limits. Anthropic's terms for using a subscription
through tools like this one changed several times in 2026: check them.
For professional use, an **API key** (billed per use, under a business contract) is still the safest option.

## The other options
Settings → *Model and budget* → *Providers* (or a model set in the main settings):

| Option | Setting |
|---|---|
| Claude API | provider `anthropic`, key `ANTHROPIC_API_KEY` |
| Claude Pro/Max subscription | `--with-claude-code`, then `claude setup-token`; provider `claude-code` |
| Gemini API | provider `gemini`, key `GEMINI_API_KEY` (https://aistudio.google.com/apikey) |
| OpenAI | provider `openai`, key `OPENAI_API_KEY` |
| European provider (OVHcloud, Mistral, Scaleway, Infomaniak, AKI.IO) | connect it on its *Providers* card (see *Staying in Europe*); provider `sovereign` |
| Local model (Ollama…) | provider `openai`, address `http://localhost:11434/v1`, model name |

## A different provider per space (AI accounts)
Settings → *Model and budget* → *Providers*: connecting a provider on its card creates an AI account (a name, a provider and its key or token);
*+ Add a custom provider* opens the full account form for the less common ones.
Then pick the account in the space's settings (*AI account*); for General, in the space menu.
Spaces without an account use the main settings.

- **Two Claude subscriptions** (e.g. "Pro" and "Provite"): on a computer signed in to each subscription, run
  `claude setup-token` and paste the token into an account of type *Claude subscription*.
- **Several European providers**: one account per provider (Mistral for one client, OVHcloud for another…).
  A space on a European account may hold *confidential* conversations even when the main model is a subscription.
- Each account has its own limits on the provider's side, so the load is shared between them.
- *Test* checks the account before saving. Keys and tokens never leave the server; leave the field empty to keep the saved one.
- Transcription, image reading and search by meaning are chosen from the Models table (see below); an account's own models only serve the three chat tiers.
- Deleting an account puts its spaces back on the main settings.

## The Model and budget page
Settings → *Model and budget* has three tabs.

- **Models**: a catalogue of every provider and model, sortable and filterable (search, type, provider, trust level,
  availability). Each row shows its **rank** (1 basic → 10 top of the range), its **price** (in / out per million
  tokens, per minute for voice, per image for image models; a « ~ » means an estimate), its **type** (LLM, vision,
  voice, text → image, **embedding**) and its **trust level** from 1 (consumer) to 5 (sovereign, GDPR); *loc* is a
  model on your own machine.
- **Providers**: one card per provider with its status, country, billing and trust. **Connect** on the card, paste the
  key right there (the *Create an account* link opens the provider's sign-up), and its models appear in the Models
  tab at once. Local, DeepSeek, Hugging Face and ElevenLabs use the *+ Add a custom provider* card.
- **Budget**: the limits (see below) and what was spent this month.

### Each usage on its own model
The cards above the table show what each usage currently uses: **fast**, **standard**, **deep**, **voice**, **images** and
**embeddings**. The small **F / S / A / V / I / E** buttons on a row send that usage to that model, so quick work can run on a small European model while
hard reasoning goes to a flagship, each on a different provider. The *Routing* selector switches between the **global
default** and a **per-space override** (overridden usages are marked; reset returns to the global default). With nothing
set, everything behaves as before.

### Voice, images and embeddings
Three more jobs are chosen from the same table; **click their card** to filter the table and see what is left to set:
- **Voice** (button **V**): transcription of recordings, meetings and dictation, at a European provider (Whisper, Voxtral),
  OpenAI or Gemini, or **Whisper (local)** on this server, where the audio never leaves it. A model from a provider that
  cannot transcribe is not offered. The card keeps the meeting settings: the summary tier, and the length from which a
  recording counts as a meeting.
- **Images** (**I**): reading photos and scanned PDFs so the library can search them. Any model that can read an image
  will do; until you choose one, the fast model does it. The on/off switch stays in Settings → Library.
- **Embeddings** (**E**): search by meaning. All spaces share one index, so this one is only set in the *global default*.
  Changing it re-indexes the memory in the background, and a local model is downloaded if it is missing. The card keeps
  the vector size, the index status and *Measure on this server*; a Voyage key stays in Settings.

### The catalogue keeps itself up to date
Once a week, and when you press **Refresh now**, the assistant asks each connected provider for its model list and adds
the ones the catalogue does not know yet (marked as estimates until a price and rank are confirmed). The header
only shows an update date once a refresh has really run; before that it reads « Base catalogue ».

### The Europe lock
The **Europe lock (GDPR)** toggle is in the Models tab (see *Staying in Europe*). It hides non-compliant models and
providers and makes Privacy refuse any non-European AI or search service.

### AKI.IO
When your AKI.IO key lists models tagged **TL5**, the fast/standard/deep picks are drawn only from those, so the
assistant is processed in Germany with no extra step. Choosing a non-TL5 model by hand opts out.

## Tiers and budget
- Three tiers: **fast** (summaries, checks), **standard** (conversation), **deep** (hard reasoning).
- **Budget**: daily and monthly limits in dollars. Above 80%, it switches to the fast tier;
  at 100%, it stops calling any model.
- The costs shown are estimates based on public prices. Once a month the assistant asks what your provider
  really billed (or enter it under Activity → the line below the meters): the budget is then corrected
  by the ratio between the real bills and the estimates, over the last three bills.
- **Real costs (Anthropic, OpenAI)**: with an **Admin key** (not a regular API key: Anthropic Console → Settings →
  Admin keys, `sk-ant-admin…`; OpenAI → Organization → Admin keys), the assistant reads what the provider really
  billed, every 6 hours. Put it in Settings → Model and budget → Budget for the main settings, or under *Real costs* in an AI
  account. Add a workspace id (Anthropic) or project id (OpenAI) to count only that part of the organization.
  Past days then use the real figures, today's estimate is corrected from the last 30 days, and no bill is asked for.
  Activity shows what each source billed this month (*Sync now* reads it again). The Admin key only reads costs,
  stays on the server and is never given to Claude Code or tools. Mistral and Gemini have no such API: they keep the
  estimates and the bills you enter.
- With free Gemini, Google may use your data and the limits are low: turn on billing.
