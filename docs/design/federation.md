# Federation — assistants talking to each other

Two people, two Talixio servers. Each assistant can expose a *small, previewed view* of itself to the other, and the two can exchange
short messages, in both directions. The motivating case: every evening my assistant publishes what moved today, and my colleague's
assistant (and the colleague) can react.

This is **cross-person**. `Links.php` (SSO between *one* person's assistants) and `Relay.php` (the in-process mailbox between agents of
*one* assistant) are different things; federation reuses Relay's *ideas* — consent that defaults closed, untrusted-data framing,
hard caps — but not its tables, because the peers are on other servers and must authenticate over HTTP.

## Principles
1. **Closed by default.** Pairing shares nothing. Per peer, two independent lists: `give` (what I let them read) and `accept` (what I
   take from them). Today `give ⊆ {digest}` and `accept ⊆ {digest, message}`.
2. **Small and previewed.** The digest is titles and counts only (finished jobs, directives, routines that ran) plus one free line the
   person writes. Never instructions, results, costs, conversations, mail, files, memory. The settings screen shows the exact text.
3. **Data, never orders.** Whatever arrives is stored as text, shown with a banner to the model (`Federation::UNTRUSTED`), and never
   executes anything. `peer_send` / `peer_publish` are the only outward acts and are for explicit user requests.
4. **Every direction has its own secret**, 256-bit, stored **hashed** on the side that verifies it; the side that presents it stores it
   (it has to). Revocation on either side drops both and tells the other (best effort `bye`).
5. **Egress goes through the privacy gate** (purpose `assistants`): allowed at *Internal*, blocked at *Confidential* and *Secret*;
   Europe-only locks still apply to the destination.
6. **Bounded.** 8,000 chars per item, 60 items/hour/peer (429), 50 items per pull, duplicate message ids ignored, https only (plain
   http only for loopback, for tests).

## Protocol (all JSON; `Authorization: Bearer <secret>` unless noted)
| Call | Who → who | Auth | Purpose |
|---|---|---|---|
| `POST /api/federation/pair` `{code,name,url,secret}` | acceptor → inviter | one-time code (24 h, hashed, single use) | exchange secrets; returns the inviter's secret once and what it gives |
| `POST /api/federation/push` `{mid,kind,title,body,from}` | sender → receiver | sender's secret | deliver one item (webhook) |
| `GET /api/federation/pull?ack=1[&view=1]` | reader → owner | reader's secret | fetch what is queued for me; `view=1` adds the digest as it stands |
| `POST /api/federation/bye` | either | secret | the pairing ended |

A failed push leaves the item in `fed_outbox`; the peer picks it up on its next pull (every 10 minutes by the worker, or "Check now").

## Storage
`fed_peers` (url, `out_secret`, `in_hash`, `give`, `they_give`, `accept`, status), `fed_inbox` (UNIQUE(peer, mid)), `fed_outbox`; kv
`fed_invite`, `fed_auto`, `fed_last`, `fed_polled`.

## UI (Settings → Other assistants)
Paired assistants (per-peer permissions, message, look at their digest, unpair) · What I share (exact preview, own line, "send now",
"every evening") · Received (unread badge, mark read). Tools for the assistant: `peer_list`, `peer_inbox`, `peer_send`, `peer_publish`.

## Questions (1.33.0)
The owner defines **named questions**: prompt, the one space it reads (internal only), allowed peers, optional `{param}` (200 chars, quoted
as data), and the cost limits `maxDay` (fresh answers per day) and `gapMin` (minutes between fresh answers). The asker never sends a
prompt: `POST /ask {query, param, mid}`; `GET /queries` lists names and descriptions only. A fresh run is queued and done by the worker
with the `peer` toolset (kb read, board read, tasks read; no memory/mail/web/shell/writes) and the `peer` role prompt; the answer goes to
the asker as an `answer` item (push, else pull). Past `maxDay` or inside `gapMin`, the last stored answer is re-sent marked *unchanged since
…* (no run, no cost); a call with a `param` and no limit room is refused (429) since its answer cannot be reused. Also: 10 asks/hour/peer,
5 waiting at once. Digest and answers never draw on non-internal spaces. `answer` is accepted wherever `message` is.

## Overview and followed questions (1.34.0)
First tab of the section: **What I offer** (`offerOverview()`: per question today's count vs limits, per allowed peer asks over 7 days
and last ask) and **What I follow** (`fed_subs`: a local copy of each peer's question list refreshed on demand with `refreshSubs`; per
subscription my `alias`, `daily`, `followed`, the optional value, `last_mid`/`last_ask` and the latest answer). Answers carry `re` = the
mid of the ask (new `re` column on `fed_inbox`/`fed_outbox`, in push and pull bodies); `store()` copies an `answer` whose `re` equals a
subscription's `last_mid` into that subscription — the stored status. `askSub` asks now; the worker tick asks `daily` ones once a day
from 06:00 (`askDaily`). Chat/briefing: tool `peer_status(who, max_age_minutes)` matches alias / question / assistant name, returns
the stored answer under the untrusted banner with its date, and asks again in the background when older than the limit; the morning
briefing prompt gets a line using it (`briefing_v3` migration). The asker still never sends a prompt. Withdrawn questions are marked
`gone` and drop out of the daily run.

## Not in this slice (deliberate)
- Richer shares (a chosen space's board, a document). Add them as new `give` kinds, each with its own preview, never as a free-form
  "read everything".
- Request signing with a per-message HMAC and timestamp (today: Bearer over https + duplicate ids). Add if peers ever sit behind
  untrusted proxies.
- Multi-hop (A→B→C) forwarding: not allowed; if added it must reuse Relay's depth/path/budget envelope.
- A directory / discovery of peers: pairing stays out-of-band on purpose.
