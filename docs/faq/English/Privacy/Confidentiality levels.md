# Confidentiality levels

Each space has a level, set with the shield next to its name. The rules are applied at the
moment data is about to leave the server: the assistant cannot get around them, even if an
email or a document asks it to.

## Internal
Normal behaviour: the services configured in Settings are used.

## Confidential
- Only a model under business terms reads the content: the Claude API, the OpenAI API, or Gemini if
  your key is on a paid plan.
- Web, browser and connectors ask for your approval every time.
- Transcription and indexing (search by meaning) on the server only.
- Notifications without content.
- Share links: password required, 30 days maximum.
- The space cannot be used from Telegram.

## Secret
Nothing leaves the server: no outside model, no web, no sharing. Its content is never
searched from another space. The library and tasks work; to chat, you need a model
installed on the server.

## "Contamination"
If a question asked in General reads a confidential document, the rest of the answer follows the Confidential rules.

## What stays in its level
- **Reading**: a model that may not take a level does not read that level's files, notes or
  memory, even from General (you still find them yourself with the search).
- **Writing**: what comes from a Confidential or Secret space is only saved in a folder at least
  as protected (its space's folder). It is not copied into the shared memory, contacts or skills,
  which every space reads; it goes in the space's notes instead. A job's report follows the same rule.
- **Learning**: what the assistant learns about you in a Confidential or Secret space stays in
  that space.
- **Other spaces**: routines and jobs of a space the model may not read are not listed and
  cannot be changed from elsewhere; a Secret space's folder is not shown outside it.
- **Web search**: the provider's own web search is turned off as soon as the work is above
  Internal; Talixio's web search asks for your approval (Confidential) or is refused (Secret).

## Under each answer
Badges show where the answer went ("Anthropic · 3 documents", "3 local", "1 blocked");
tap them for details. They also show live while the assistant is writing.
