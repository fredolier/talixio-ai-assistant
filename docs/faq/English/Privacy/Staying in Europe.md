# Staying in Europe ("Europe only")

To keep your invoices, contacts, emails and documents away from the big American platforms, use a European
provider, paid by use, with nothing to host: **Settings → Model and budget**.

## Getting started
1. **Providers** tab: on a European provider's card (OVHcloud, Mistral, Scaleway, Infomaniak, AKI.IO), press **Connect**,
   create a key with them (the *Create an account* link) and paste it on the card. Its models appear in the Models tab.
2. **Models** tab: send each usage (fast, standard, deep, voice) to a European model, with the **F / S / A / V** buttons.
3. Turn on the **Europe lock (GDPR)** if nothing must leave Europe.

## Which provider?

| Provider | Where | Conversation | Search by meaning | Transcription |
|---|---|---|---|---|
| OVHcloud AI Endpoints | France | open models, including Mistral's | bge-m3 | Whisper |
| Mistral AI | France | Mistral models | mistral-embed | Voxtral |
| Scaleway | Paris | open models | Qwen3-Embedding 8B | Whisper |
| Infomaniak | Switzerland | open models | BGE Multilingual Gemma2 | Whisper |
| AKI.IO | Germany | open models (use the **TL5** ones) | bge-m3 | — |

- **OVHcloud** is the recommended choice: hosted in France, you pay per amount of text processed, and
  you can try it **without a key** (low limits).
- **Mistral**: take a **paid plan**. On the free *Experiment* plan, Mistral may use
  your data to train its models.
- **AKI.IO**: only models tagged **TL5** are processed in Germany; the others can be served from Singapore. Talixio picks the TL5 ones for you when your key lists any.
- **Infomaniak** also asks for the address of your AI product (it contains its number).
- The open European models are good for everyday use, a little behind the best
  American models on hard reasoning.

## What you choose, and what the lock does
Connecting a provider no longer switches everything at once; you choose where each job goes:
- **the conversation**: per usage, in the *Models* tab (several providers can be mixed);
- **search by meaning**: Settings → *Search by meaning* (a model on this server, or the European provider's).
  Changing the model re-indexes the memory in the background;
- **transcription** of voice messages and meetings: it stays on this server if it is installed there.

The **Europe lock** is a guard on its own. Turning it on in the *Models* tab filters the table to compliant models and
providers, and **swaps** any non-compliant selection for the best sovereign or local model (the swaps are
reported). It does not force which provider answers, so you can lock to Europe without changing your main model.

## Half price for background work (Mistral)
With Mistral, summaries of past conversations and the assistant's own reflection go through Mistral's
**batch API**: half the price, the answer comes back within a few hours (usually minutes). The worker
collects it and finishes the job. *Compact now* and *Think it over now* still answer at once.
A batch that fails is made again directly; when the budget runs low, calls are made directly too.
Turn it off with **Mistral: background work at half price** in Settings (`TALIXIO_MISTRAL_BATCH=0`).
In Activity, these calls show as *summary (batch)* or *mind (batch)*, at half the estimated cost.

## The lock
With the lock (**Europe lock (GDPR)** in the *Models* tab, `TALIXIO_SOVEREIGN_ONLY=1`), anything without a European equivalent is **turned off** instead
of going elsewhere:
- web search, unless you use your own SearXNG;
- search by meaning and transcription at a service outside Europe (word search is kept, and
  transcription on this server if it is installed).

## A smaller server
By default, what already runs on your server stays there: search by meaning with Ollama, and local transcription.
To free the memory, choose the European provider's embedding model in Settings → *Search by meaning*: the library and memory
are re-indexed in the background with it, and the server no longer has a model to run. With several people,
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
- With the lock on, the Models table hides the non-compliant models and providers: their keys stay saved but are not used.

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
  at the provider; choose another model with the **E** button in Settings → Model and budget → Models.
