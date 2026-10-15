# Staying in Europe ("Europe only")

To keep your invoices, contacts, emails and documents away from the big American platforms:
**Settings → Model and budget → Europe only**. A single European provider, paid by use, with nothing to
host, then does all the AI work.

## Getting started
1. Choose the provider (OVHcloud by default).
2. Create a key with them (the link is under the field) and paste it.
3. **Test**: it checks the key and shows the models picked for each tier (fast, standard,
   deep), and whether transcription and search by meaning are available.
4. Tick **the lock** if nothing must leave Europe.
5. **Switch everything to Europe**.

## Which provider?

| Provider | Where | Conversation | Search by meaning | Transcription |
|---|---|---|---|---|
| OVHcloud AI Endpoints | France | open models, including Mistral's | bge-m3 | Whisper |
| Mistral AI | France | Mistral models | mistral-embed | Voxtral |
| Scaleway | Paris | open models | Qwen3-Embedding 8B | Whisper |
| Infomaniak | Switzerland | open models | BGE Multilingual Gemma2 | Whisper |

- **OVHcloud** is the recommended choice: hosted in France, you pay per amount of text processed, and
  you can try it **without a key** (low limits).
- **Mistral**: take a **paid plan**. On the free *Experiment* plan, Mistral may use
  your data to train its models.
- **Infomaniak** also asks for the address of your AI product (it contains its number).
- The open European models are good for everyday use, a little behind the best
  American models on hard reasoning.

## What switches over
One click on *Switch everything to Europe* sets:
- **the conversation** (all three tiers, chosen automatically from the provider's models);
- **the transcription** of voice messages and meetings (unless it already runs on this server);
- **search by meaning** (unless it already runs on this server, with Ollama). Changing the model
  re-indexes the memory in the background.

## Half price for background work (Mistral)
With Mistral, summaries of past conversations and the assistant's own reflection go through Mistral's
**batch API**: half the price, the answer comes back within a few hours (usually minutes). The worker
collects it and finishes the job. *Compact now* and *Think it over now* still answer at once.
A batch that fails is made again directly; when the budget runs low, calls are made directly too.
Turn it off with **Mistral: background work at half price** in Settings (`TALIXIO_MISTRAL_BATCH=0`).
In Activity, these calls show as *summary (batch)* or *mind (batch)*, at half the estimated cost.

## The lock
With the lock (`TALIXIO_SOVEREIGN_ONLY=1`), anything without a European equivalent is **turned off** instead
of going elsewhere:
- web search, unless you use your own SearXNG;
- search by meaning and transcription at a service outside Europe (word search is kept, and
  transcription on this server if it is installed).

## A smaller server
By default, what already runs on your server stays there: search by meaning with Ollama, and
local transcription. Tick **Also move search by meaning and transcription from this server to the
provider** before *Switch everything to Europe*: these two jobs then go to the European provider
(at OVHcloud: bge-m3 and whisper-large-v3), and the server no longer has a model to run.
The library and memory are re-indexed in the background with the new model. With several people,
then untick Ollama in the admin page: it is what uses the most memory.

## Are keys for providers outside Europe really unused?
With the lock, every request to Anthropic, OpenAI, Gemini, Voyage, Brave or Tavily is **refused at the
moment it would leave**, even if a key is saved. The attempt is recorded in **Privacy →
journal**: you can check that it never happens. Without the lock, a key is only used if a service
is explicitly set to that provider.

## How to know everything stays in Europe
- At the top of **Settings**, a **★ 100% Europe · GDPR** stamp shows as soon as nothing leaves
  Europe any more (hover over it to see the provider and the country). If something still does, it turns
  orange and says how many services still go outside.
- **Run checks** adds an *EU · GDPR* step: it confirms that everything stays in Europe, with
  which provider, and whether the lock is on; otherwise it flags in red what still goes outside.
- In *AI model*, the keys for providers outside Europe (Claude, OpenAI, Gemini) are hidden:
  they stay saved but are not used. *Show them* brings them back.

## What still leaves Europe
The card lists them, with a link to change each one. The usual cases:

| Service | Why | European replacement |
|---|---|---|
| Google Calendar | your events are held by Google | a CalDAV or iCal calendar (Infomaniak, OVH, Nextcloud) |
| Web search | the queries go to an American engine | SearXNG on your server, or turned off |
| Telegram | the messages go through Telegram | notifications by email, or without content |
| Spotify | what you listen to | — (disconnect it if needed) |
| Remote connectors | what the assistant sends them | a connector that runs on this server |

## Confidential spaces
- **Confidential**: the European provider is accepted, both for answering and for indexing.
- **Secret**: nothing leaves the server, not even to Europe; search there works by words
  (or with Ollama on this server).

## GDPR
Being hosted in Europe is not enough: in your customer area at the provider, **accept
the data processing agreement (DPA)**. That is what binds the provider (processor in the sense
of the GDPR, no training on your data, place of processing). Note it in the record of
processing (Privacy page). The prices shown for these models are approximate: check the
provider's price list.

## Several people on one server
Admin page → Settings → **AI in Europe for everyone**: one European account shared by all
the assistants (provider, key, address, lock). The key is never shown again. The assistants are
recreated to pick it up.

## If something goes wrong
- *The provider refused the key*: wrong or expired key, or not a key for this provider.
- *Could not reach the provider*: the server cannot reach the Internet (firewall, proxy).
- The test says search by meaning is not available: the model name may have changed
  at the provider; set it in `TALIXIO_EMBEDDINGS_MODEL`.
