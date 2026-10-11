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

- Three tiers: **fast** (summaries, checks), **standard** (conversation), **deep** (hard reasoning).
- **Budget**: daily and monthly limits in dollars. Above 80%, it switches to the fast tier;
  at 100%, it stops calling any model.
- The costs shown are estimates based on public prices.
- With free Gemini, Google may use your data and the limits are low: turn on billing.
