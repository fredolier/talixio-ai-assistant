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

## Under each answer
Badges show where the answer went ("Anthropic · 3 documents", "3 local", "1 blocked");
tap them for details. They also show live while the assistant is writing.
