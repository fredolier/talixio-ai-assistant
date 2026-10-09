# Laya for Talixio's decisions — evaluation (October 2026), parked for later

[Laya](https://github.com/he-jev/laya) (Apache 2.0) is a non-generative decision model, an open
alternative to Jev: a text plus typed questions (`choice`, `score`, `noul` = yes/no) give
answers with probabilities in one forward pass. Idea: a free, local first filter for decisions
Talixio makes today with embeddings and a fast LLM.

**Status: parked.** The useful checkpoint needs about 3.5 GB of RAM, more than the server has
next to Ollama and the instances.

## Results (CPU, 4 cores; hand-labelled, invented cases, mostly French)

Accuracy; in brackets, on the half of the cases where the model is most confident.

| Decision | multilingual | english | **typed-decisions** |
|---|---|---|---|
| Email triage, 4 groups (chance 25%) — 39 emails | 26% | 49% | **69% (90%)** |
| "Does this email ask the user to act?" | 62% | 67% | **77% (100%)** |
| Prompt injection in a text — 16 texts | 75% | 62% | **81% (88%)** |
| Model tier for a request (fast/standard/deep) — 15 | 47% | 60% | **73% (100%)** |
| Space of a note (5 spaces) — 20 | 55% | 60% | 55% (70%) |
| Urgency, 3 levels | 26% | 33% | 31% |

- The multilingual checkpoint is not usable zero-shot (chance level on triage, yet confident).
- `typed-decisions` is the one, French included. Its confidence is low but ranks cases well:
  use it as a gate (decide only above a threshold learned from the user's corrections).
- Worse than embeddings + the user's examples for space routing; poor on urgency.
- Questions written in French did not help (`evaluate_fr.py`).
- typed-decisions: ~300 ms per question, ~1 s for 3 questions; ~3.4 GB peak RSS (fp32);
  bfloat16 / dynamic int8 did not work as-is with Laya's code (ONNX export would be the next try).

Small samples: an indication, not a measure. Not compared with the fast LLM tier (no API key
in the evaluation environment).

## Integration plan, for when there is memory

1. A shared `laya` service (Docker, internal network, `typed-decisions` checkpoint).
2. In Talixio, a decision chain: the user's own corrections (Classifier, kNN) → Laya when its
   confidence is above the threshold that gave ≥90% right on this user's history → fast LLM.
   Log every decision and the final answer to calibrate the thresholds per question.
3. Uses: email triage and "make it a task?", a prompt-injection signal on web pages, emails and
   documents read by the assistant, automatic model tier per message. Bonus: emails decided
   locally are not sent to an LLM provider.
4. Later: fine-tune on the accumulated corrections (a few GPU hours).

## Run it again

```bash
git clone https://github.com/he-jev/laya   # weights are Git LFS files (media.githubusercontent.com)
python -m venv venv && venv/bin/pip install torch transformers safetensors numpy
# per checkpoint dir: encoder/, tokenizer/ (tokenizer.json may be LFS too), rl_agent_config.json, model.safetensors
cp laya/rl_common.py laya/rl_agent_api.py laya/email_utils.py .
USE_TF=0 venv/bin/python evaluate.py models/typed-decisions
```
