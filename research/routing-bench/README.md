# Routing benchmark

Tests how requests get their trust label (Public, Business, Confidential, Secret), their chat tier (Fast, Standard, Deep) and their split into steps, before any of it goes into the app.

All cases are synthetic (demo names: Alex, Sam, acme.example). Never add real mail, documents or names: this repository is public.

## Run

```
php bench.php rules                 # router off: space level + pattern rules, no model
BENCH_URL=https://api.example/v1 BENCH_MODEL=some-model BENCH_KEY=… php bench.php router
php bench.php router-split          # router with the splitter
php bench.php all
```

Any OpenAI-compatible endpoint works (Ollama `http://localhost:11434/v1`, Scaleway, Mistral, OpenRouter). Only send these synthetic cases to an outside service.

## Case format (`cases.jsonl`)

`{"id", "space", "text", "attach"?, "label", "tier", "split"?: [{"marker", "label"}], "note"?}`

- `space`: the label of the space the request is typed in. Nothing may lower it.
- `label` / `tier`: the right answer.
- `split`: for mixed requests, a phrase from each part and the label that part needs.

## Scores

1. **Leaks**: label below the right one. Must be 0; a strategy with a leak is out.
2. **Step leaks**: a step carrying a part's phrase got a lower label than that part needs. Must be 0.
3. Label exact / too high (too high costs money and causes needless refusals), tier right, split cases handled, unreadable answers, latency p50/p95, tokens.

The pattern rules were written next to these cases, so their score is optimistic. Add new cases without looking at the rules before trusting a comparison.
