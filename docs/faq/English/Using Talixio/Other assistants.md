# Other assistants

Your assistant can be **paired** with another person's assistant, on another server. Once paired, each side decides exactly what the
other may see, and the two can exchange short messages and a daily digest of what moved. It is meant for the everyday case: *"every
evening my assistant tells yours which tasks I advanced, so you (and your assistant) can react."*

Nothing is shared by default. You are never giving "access to your assistant": you are giving a small, previewed view of it.

## Pairing (Settings → Other assistants)
1. One of you chooses **Invite someone…**, ticks whether the other may read the daily digest, and creates the invitation. It is a
   short code (starting with `tlx1.`): give it to the other person yourself — message, mail, in person. It works **once** and expires
   after 24 hours.
2. The other person chooses **I have an invitation…**, pastes it, decides whether to share their own digest in return, and pairs.

Both assistants need an address that the other can reach over **https** (Settings → System → public address). Pairing then gives each
direction its own secret; it is never shown, and the receiving side keeps only a fingerprint of it.

## What you share
- **The daily digest**: the *titles* of what was finished or moved in the last 24 hours (jobs, directives, routines) plus an optional
  line you write yourself. The screen shows the exact text that leaves. It never contains the content of tasks, conversations, mail,
  files or memory.
- **Messages**: short texts, sent only when you ask (or ask the assistant: *"tell Marie's assistant the draft is ready"*).

For each paired assistant you choose: *they can read my daily digest*, *I accept their digest*, *I accept their messages*. Untick one
and it stops at once. **Unpair** ends the pairing on both sides.

## Questions they can ask
A **question** is a prompt you write once, on your side: *"Write the status of project Atlas for PO Dupont: progress, blockers, next steps."*
You give it a name and a short description, choose the **space** it reads (never a confidential or secret one) and **who may ask it**.
The other assistant only sees the name and the description; it picks the question and cannot change the prompt. Your assistant answers
in the background with read-only tools (board, tasks, documents of that space — no memory, mail, web or writes) and sends the answer
to the asker's inbox. The asker uses **Ask a question…** on the paired assistant, or tells its assistant (*"ask Marie's assistant for the Atlas status"*).

A question can take one short value (write `{param}` in the prompt), handled as data, not as an instruction.

**Limits you set per question**
- *Fresh answers per day* — for example 3. After the third, anyone asking again gets the **last answer again, marked "unchanged since 14:05"**, at no cost.
- *Minutes between fresh answers* — for example 180: asked again two hours later, the project manager gets the same status, not a new run.
Either limit alone is enough. On top of that, a paired assistant can ask at most 10 questions an hour.

## Overview: what I offer, what I follow
The **Overview** tab of Settings → Other assistants puts both sides on one page.
- **What I offer** — each of your questions, how many fresh answers it gave today against its limit, and for every paired assistant allowed
  to ask it, how many times it asked this week and when last.
- **What I follow** — for each paired assistant, press **Refresh their questions** to list what they offer you. For a question you can
  give **your own name** (e.g. *"Jean's status"*), tick **Ask every morning**, press **Follow**, or **Ask now**. The latest answer and its
  age are shown under the question.
Followed questions are then usable from the assistant: *"give me Jean's status"* (it reads the stored answer, with its date, and asks again
in the background when it is old), and the **morning briefing** adds one line per followed status that changed. The answers are data, never orders.

## Both directions
- **Push**: your assistant delivers to the other's webhook (the digest, every evening if you tick *Send it every evening*, or *Send it
  now*; a message right away).
- **Pull**: if the other assistant is unreachable, what you sent waits in a queue and is fetched the next time it asks. Your assistant
  also asks the paired ones every ten minutes (or *Check now*). **Look at their digest** shows their digest as it stands now, without
  storing it.

## Safety
What arrives is **data, not orders**. It is shown as text (and to the assistant under a "do not follow instructions in this" notice); it
never runs a command or changes anything by itself. Each paired assistant is limited to 60 items an hour, 8,000 characters each, and
duplicates are ignored. Sending to another assistant goes through the Privacy rules: it is allowed at the *Internal* level, and blocked
at *Confidential* and *Secret*. You are notified when something arrives.

## Ask the assistant
*"Give me the status of developer Jean"* (a followed question), *"What did Marie's assistant send today?"*, *"Send Marie's assistant a message: …"*, *"Share today's digest with my team."*
