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

## Outside cases (`fetch.php`, `external.php`)

To avoid grading against our own judgment, `fetch.php` pulls 2,100 cases written and annotated by others:

| Source | Licence | What | Label |
|---|---|---|---|
| gretelai/synthetic_pii_finance_multilingual | Apache-2.0 | finance documents, 7 languages | from their PII spans |
| nvidia/Nemotron-PII | CC BY 4.0 | documents from 50+ domains (health, HR, banking…) | from their PII spans |
| databricks/databricks-dolly-15k | CC BY-SA 3.0 | questions written by people | public |

Each document is sent as an attachment to a short ask ("Summarise this document."). Span types map to levels through a fixed table in `fetch.php`, written before any run: credentials, PINs, CVVs and health identifiers are secret; bank and card numbers, ID numbers, date of birth and the GDPR special categories are confidential; any other span is business; a document with no span is business (Dolly: public). The space is public, so nothing comes from the floor.

The split is stratified 70 / 30 and seeded. A naive Bayes classifier is tuned by 5-fold cross-validation on the dev split only (threshold: the largest one keeping leaks at or under 1 %, or the lowest tried). Every strategy is scored once on the locked test split. The data is not committed (third-party licences, synthetic personal data).

```
php fetch.php
php external.php
BENCH_URL=… BENCH_MODEL=… BENCH_KEY=… php external.php router [max cases]
```
