# Forecasts and decisions

Two specialised models sit next to the language model. A language model is good at words, not at numbers: when you want the next
months of a curve, or a decision with an honest probability, the assistant hands the job to the right tool.

## Forecasts (Google TimesFM)
Give the assistant figures — pasted in the chat, or read from a file — and ask what comes next: *"Here are our monthly sales for
three years, what do we expect for the next six months?"*. It answers with the forecast and an 80% interval (the real value should
fall inside it about four times out of five).

- **Series:** oldest value first, at least 3 numbers (up to 5,000). If the data repeats (12 for monthly figures with a yearly
  pattern, 7 for daily figures with a weekly one) say so; the assistant passes it on.
- **Without any setup** the assistant uses a simple built-in method (seasonal naive with drift, or a damped trend) and says so in
  the answer. It is honest but modest.
- **With TimesFM** (Google's time-series foundation model, about 200 million parameters, open licence, runs on the server's
  processor — no graphics card needed) the forecasts are better, especially on short or irregular series. The answer names the model.
- The assistant never invents figures: if you have not given any, it asks.

### Turning TimesFM on (Docker installation)
1. Run `install-stack.sh --with-models` again from the clone. It builds the service (about 2 GB of memory while it works; the
   weights download the first time a forecast is asked, so the first one is slow).
2. In **Settings → Model and budget → Forecast and decisions**, set *TimesFM service address* to `http://talixio-caddy:8765`
   (instances created from the admin page get it automatically).

If the service does not answer, the assistant falls back to the built-in method and tells you.

## Decisions (Jev, Kev and the like)
Some models do not write text: they answer **typed questions** about a situation — pick one option, give a score on ordered levels,
or answer yes/no — and return **probabilities and a confidence**. TypeSafe's *Jev* is one; open models that speak the same protocol
(POST `/v1/systemone`) exist, such as *Kev* (Apache-2.0) and *Rizzo Flow*.

In **Settings → Model and budget → Forecast and decisions**:
- *Decision model address* — the server: TypeSafe's cloud (**your text then leaves for their servers**; Talixio's privacy rules apply
  and may block it) or your own Kev / Rizzo Flow server.
- *Decision model key* — sent as a Bearer token; leave empty if your server has none.
- *Decision model name* — optional, e.g. `kev-latest`.

Nothing is sent anywhere while the address is empty, and the *decide* ability does not appear. Once set, ask: *"Should we accept this
client's discount request? Options: accept, counter-offer, refuse."* The answer shows each option's probability and the model's
confidence, labelled as coming from the decision model, not from the language model's guess.
