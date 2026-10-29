# Search by meaning

Search by **words** always works. Search by **meaning** also finds a document that does not
contain your words ("home insurance" finds "comprehensive household policy"). For this, each passage
must be turned into a vector: the model that does it is chosen in **Settings → Model and budget → Models**, with the **E** button of an
embedding model (the *Embeddings* card). **Settings → Library and memory → Search and memory** shows the model in use and the index.

| Choice of model | Where it is computed | Memory | Notes |
|---|---|---|---|
| On this server: very light | Ollama, multilingual-e5-small | ≈ 0.3 GB | 5 times lighter than "best quality", a little less accurate |
| On this server: light | Ollama, EmbeddingGemma | ≈ 0.7 GB | 2 times lighter, nearly as good |
| On this server: best quality | Ollama, Qwen3-Embedding 0.6B | ≈ 1.5 GB | the most accurate; needs a recent processor (AVX2) |
| Google Gemini, OpenAI, Voyage | online | nothing here | needs the key; free Gemini may use the texts |
| European provider | online, in Europe | nothing here | the European provider's key (see *Staying in Europe*) |
| Off | — | — | search by words only (*Turn off search by meaning* on the *Embeddings* card) |

- **Changing the choice re-indexes everything** in the background (the vectors depend on the model). Meanwhile,
  search by words stays available; the progress is shown under the choices.
- **Measure on this server** gives the time for one search, for indexing (per passage) and for
  comparing against all the vectors: enough to decide based on numbers.
- **Secret** spaces are only indexed by a model on this server; **Confidential** ones also by
  the European provider. With another online service, they are searched by words.
- **sqlite-vec** (in the Docker image, and installed by `install.sh`) compares the vectors in SQLite,
  about 6 times faster than in PHP (20,000 passages: 0.15 s instead of 0.95 s on a test machine).
  Without it, the comparison is done in PHP, more slowly but without losing anything.

## Several people (Docker)
- Ollama is **shared**. Admin page → Settings → *Shared memory search (Ollama)*: the default model
  and the *Download the model* button. Each person can choose another of the downloaded models.
- Ollama is limited to half of the processors (`OLLAMA_CPUS` in the stack's `.env`), so that
  indexing does not take over the whole machine.
- **Unticking** the shared Ollama stops it: its memory (1.5 GB) goes back to the assistants, and the
  sizing calculation takes this into account. Each person can then use an online service.

## Modest server
On a small processor, indexing a large library can keep the machine busy for hours.
Choose *light* or *very light*, or an online service if confidential spaces can make do with words.

## Knowledge graph (optional)
Settings → *Learning* → *Knowledge graph of the library* (off by default). When it is on, the assistant
notes in the background the people, companies, projects and places each document mentions. It can
then gather **everything about a name** ("everything about Dupont") or **what is linked to a file**,
on top of the search by words and meaning.
- It costs one call to the fast model per new or changed document. *Documents added to the graph per
  day* caps it (100 by default); the rest waits for the next days.
- A folder the model may not read (a Secret space without a local model, for example) is never sent,
  and the assistant only sees links to documents it is allowed to read.
