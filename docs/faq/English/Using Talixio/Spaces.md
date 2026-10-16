# Spaces

A space is a separate conversation for one topic: a client, the house, health…

- You switch spaces at the top of the conversation.
- Each space has **its own folder** in the library (searched first; attachments are filed
  there), **its own instructions**, its notes, a default model, a color and a **task board**.
- A space does not see the conversations of the others, unless you ask it to.
- Telegram, Signal and email write to *General*; `/space Home` moves them to another space.
- Each space has a **confidentiality level** (Internal, Confidential, Secret): the shield next
  to its name. See *Privacy / Confidentiality levels*.
- With one AI account per space, a Confidential or Secret space is walled off: other spaces'
  models do not read it, and nothing from it is written to a less protected place.
- Deleting a space keeps its documents in the library (see below).

## Archive, restore, history

- **Archiving** a space (space card → *Archive*, confirmed with a second click) removes it from the list
  without erasing anything: the conversation, notes, tasks and folder stay. The channels that wrote to it
  go back to *General*.
- Archived spaces are at the bottom of the space picker, under **Archived (n)**. Opening one shows a
  banner with **Restore**; the space card also has the *Restore* button.
- The space card shows its **history**: creation, renaming, archiving, restoring,
  change of confidentiality or folder, with the date and the origin (in the app or by the assistant).
- You can also ask the assistant: "list my spaces", "what happened in the Home space?",
  "restore the Home space". It cannot archive a space itself.
- The history exists since version 0.16.1: older archiving is not in it.
- **Deleting** a space erases its conversation; its documents stay in the library and its
  tasks move to *General*.

## Moving a space to another assistant

When you have several assistants (see *Administration / Several people on one server*), a space
can move from one to the other with everything that belongs to it.

- Space card → **Move…**, pick the assistant, **Move**. Your browser carries the space straight
  to the other assistant, then it is removed here.
- What goes with it: the conversation and its summaries, the instructions and notes, the model,
  colour and confidentiality level, the tasks (with their dependencies, history and time spent),
  the routines and jobs, what the assistant learned about it, and its **library folder**. Documents
  from elsewhere in the library that the conversation or the tasks point to are copied too.
- What stays: the AI account of the space (keys never travel; the other assistant uses its own
  settings, change it there if needed), shared links, notifications.
- If the other assistant already has a space with the same name, task code or folder, the moved
  one gets a free one ("Acme 2", a new code, "/Clients/Acme (2)"). Its tasks are renumbered; their
  old codes still find them.
- Tasks shown in your calendar leave it here, and appear in the other assistant's calendar if it has one.
- Here, the folder goes to the trash (it can be restored for 30 days), and the chat apps that wrote
  to the space go back to *General*.
- Not while a job of the space is running: wait for it to finish.
- To another server, or when the direct transfer fails: **Download the package** (a .zip), then in
  the other assistant open the space menu → **Import a space…**, and come back to click
  **Remove it here**. The package holds the whole space: keep it somewhere safe or delete it.
- The space's history shows "moved from …".
