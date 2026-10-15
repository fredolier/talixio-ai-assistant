# Choosing the model (the "brain")

## The simplest way: sign in with Claude
With a Claude Pro or Max subscription: Settings → *AI model* → **Sign in with Claude**
(or the button shown in the chat while no model is set).
1. Open the Claude sign-in page and accept.
2. Claude shows a code: copy it, paste it into Talixio, then *Connect*.
This is the official Claude Code sign-in (installed on the server, and included in the Docker image);
Talixio never sees your password. The token you get is valid for one year.

Usage counts against your subscription limits. Anthropic's terms for using a subscription
through tools like this one changed several times in 2026: check them.
For professional use, an **API key** (billed per use, under a business contract) is still the safest option.

## The other options
Settings → *AI model*:

| Option | Setting |
|---|---|
| Claude API | provider `anthropic`, key `ANTHROPIC_API_KEY` |
| Claude Pro/Max subscription | `--with-claude-code`, then `claude setup-token`; provider `claude-code` |
| Gemini API | provider `gemini`, key `GEMINI_API_KEY` (https://aistudio.google.com/apikey) |
| OpenAI | provider `openai`, key `OPENAI_API_KEY` |
| European provider (OVHcloud, Mistral, Scaleway, Infomaniak) | *Europe only* card (see *Staying in Europe*); provider `sovereign` |
| Local model (Ollama…) | provider `openai`, address `http://localhost:11434/v1`, model name |

## A different provider per space (AI accounts)
Settings → *AI model* → **AI accounts** → *+ Add an account*. Each account has a name, a provider and its key or token.
Then pick the account in the space's settings (*AI account*); for General, in the space menu.
Spaces without an account use the main settings.

- **Two Claude subscriptions** (e.g. "Pro" and "Provite"): on a computer signed in to each subscription, run
  `claude setup-token` and paste the token into an account of type *Claude subscription*.
- **Several European providers**: one account per provider (Mistral for one client, OVHcloud for another…).
  A space on a European account may hold *confidential* conversations even when the main model is a subscription.
- Each account has its own limits on the provider's side, so the load is shared between them.
- *Test* checks the account before saving. Keys and tokens never leave the server; leave the field empty to keep the saved one.
- Transcription and search by meaning keep the main settings.
- Deleting an account puts its spaces back on the main settings.

- Three tiers: **fast** (summaries, checks), **standard** (conversation), **deep** (hard reasoning).
- **Budget**: daily and monthly limits in dollars. Above 80%, it switches to the fast tier;
  at 100%, it stops calling any model.
- The costs shown are estimates based on public prices. Once a month the assistant asks what your provider
  really billed (or enter it under Activity → the line below the meters): the budget is then corrected
  by the ratio between the real bills and the estimates, over the last three bills.
- With free Gemini, Google may use your data and the limits are low: turn on billing.
