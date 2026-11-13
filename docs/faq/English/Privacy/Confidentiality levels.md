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
- **Other spaces**: routines, jobs and board tasks of a space the model may not read are not listed and
  cannot be changed from elsewhere; a Secret space's folder is not shown outside it.
- **Web search**: the provider's own web search is turned off as soon as the work is above
  Internal; Talixio's web search asks for your approval (Confidential) or is refused (Secret).
- **Content written by others**: once an answer has read an email, a web page (the model's own web search
  included), a document you imported or attached, your calendar, a connector's result or a message from another
  assistant or a worker, text planted in it cannot act on its own: from then on only reading runs freely (searching,
  reading files, mail, the calendar, the board; preparing a mail draft), and everything that changes or sends something
  (memory, skills, routines, tasks, contacts, notes, jobs, the code fleet, messages to other assistants, shell commands,
  opening a web address that was not in that content) waits for your approval. In the browser, typing, clicking and
  choosing go ahead on a site you named in your request or trust, unless the answer has read your own data (it could be
  typed into the page); otherwise you approve that one action at once. Your own notes,
  written and last changed by you in the app, do not count. A routine you allowed to do one of these things
  (*Always allow for the routine*, see *Tasks and routines*) does that one without asking.
  A request is one line (the tool, short values, and the length of long text such as a note's content);
  *Show the details* under it shows everything it would write, and an e-mail or chat notice includes it.
  Results you approve one after the other go into one message in the chat ("Done: 9 approved actions", each
  result folded under it), one per routine; something said in between starts a new one. A result you approved that came from such content keeps
  the rule while it is in the recent conversation, and a skill learned from such work waits for your approval too.
  A background job, a directive or a continued job carries what it read into its report: the turn that relays it
  (and the next ones, while the report is in the recent conversation) asks the same way, and a job that read a
  Confidential file reports at the Confidential level (no web search, nothing copied into shared memory).
- **Trusted websites**: sites listed in Settings → Browser and approvals → Trusted websites (subdomains
  included) open without asking, also from Confidential spaces, routines and after reading an email. The
  "Always allow" button on an approval adds the site for you; for a web search it turns on "Web search from
  Confidential spaces without asking". Secret spaces still never reach the web. While approvals are
  waiting, you get one notice, not one email per request.
  The list is a manager, not a long text: each site is a chip with ✕, you add one with its name or a full
  address (`https://www.lemonde.fr/page` becomes `lemonde.fr`), search the list, and **Import / export** it
  as text (one site per line). Something that is not a web site is refused.
- **Blocked websites** (same page): sites that are never opened, whatever the space, even if you would approve —
  the assistant is told the site is blocked. Subdomains included; a blocked site wins over a trusted one.

## Under each answer
Badges show where the answer went ("Anthropic · 3 documents", "3 local", "1 blocked");
tap them for details. They also show live while the assistant is writing.
