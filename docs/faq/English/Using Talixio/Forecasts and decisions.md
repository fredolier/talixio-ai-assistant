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

## Where to choose them: the models table
**Settings → Model and budget → Models** lists them like the other models, with two cards next to *Embeddings*: **Forecast** and **Decisions**.
Click a card to see its settings, or press the **T** (forecast) or **Δ** (decisions) button on a row to use that model.
- **Forecast, local (your server, free):** *TimesFM 2.5* (Google, Apache-2.0) and *Chronos-2* (Amazon, Apache-2.0). Both are served by the
  `--with-models` service; the row you press sets the *Forecast model* setting.
- **Decisions:** *Jev* (TypeSafe AI, United States, closed, about $0.042 per million input tokens at the time of writing; address
  `https://api.typesafe.ai`, pressing its button fills it in), *Kev* (own server, Apache-2.0) and *Rizzo Flow* (own server, Apache-2.0). Jev, Kev
  and Rizzo Flow all speak POST `/v1/systemone`. Under the Europe lock the cloud one is greyed out; the ones on your server stay available.
- **Listed to compare, not usable yet:** Toto (Datadog), TiRex (NX-AI, community licence), Granite TTM-r2 (IBM, point forecast only), TimeGPT
  (Nixtla, paid API) and TimesFM through BigQuery. Their buttons are greyed out.

Chronos-2 and the choice between models are new and have not been run against the real weights in our tests: if a forecast comes back
with a note saying the service did not answer, the built-in method answered instead.
