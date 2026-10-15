# Tasks, reminders and routines

## Tasks (one board per space)
- Columns Ideas / To do / In progress / Blocked / Done; codes per space (MAI-12), priority P1 to P5,
  start and due dates, effort, time spent, labels, description, dependencies (even across spaces).
- **Today** view (overdue, today, next up) and one-line quick add: "Call Alex tomorrow 9am #sales 30m p2".
- **Week** view to plan time: drag a task from "Not planned yet" onto the hour grid to book a slot; booked
  hours are counted against your hours a day. On a phone: tap a task, then a free slot.
- **Month** view; a task can appear in Google Calendar, Outlook or through a private iCal link,
  with its times if it has any (see "Calendar and meetings").
- The day before a due date, a notification lists what is due tomorrow.
- 30-day trash, history of every change, which can be undone.
- An email becomes a task in one click; so do the action items from a meeting.

## Quick capture
- In the chat, start with **t:** to create a task or **n:** for a note, without going through the model
  (nothing is sent, instant answer): "t: call the bank about the loan !2", "n: idea for the newsletter…".
  Also: "task:", "todo:", "note:".
- For a task: the first line is the title, the following ones the description; **!1** to **!5** sets the priority.
  It goes to the board of the current space.
- For a note: it is saved in the space's folder (otherwise /Inbox), named after its first line.
- The **+** button at the top opens the same capture (Ctrl+Enter to confirm). On a phone, a long press
  on the app icon offers *Capture* (a shortcut of the installed app).

## Reminders and routines
- Through the conversation: "remind me tomorrow at 9 am", "every working day at 8 am, send me a briefing".
- **Routines** page: see them, pause them, *Run now*, change the model tier.
- **Morning briefing**: 5 lines at most, the most important first (today's calendar, emails to handle,
  tasks, reminders, weather), each one shown as a card.
- **👍 / 👎**: each line of a message sent by a routine can be rated with one click (a second click removes the rating).
  The routine takes it into account in its next runs: more of what is useful, less of the rest.
  You can also say it in the chat ("stop telling me about the weather"). On the Routines page,
  a routine's 👍👎 button lists its ratings and lets you remove them.
- **Weekly review** (Routines page): Sunday at 6 pm by default (day, time and folder can be changed).
  What was done, what slipped, the week ahead, emails waiting, then **3 priorities**.
  The full review is saved as a note in /Notes/Weekly reviews; the message only gives the priorities and the link.
- **Watch and draft** (Routines page): follows topics for you (a newsletter, a market, a
  technology…) and writes the draft of the next issue. Give a name ("Fil IA" by default), what to watch,
  sources to look at first, who it is for, the format you want, the frequency (day, week, or month: the first
  chosen day of the month) and the drafts folder (/Drafts/<name> by default). Each run starts a background
  job that searches, sorts and writes; the draft lands in the library and the link in the chat.
  You can have several watches; *Settings* on a watch routine reopens its parameters. If a skill
  covers the same topic (e.g. your newsletter style), the watch follows it.

## Background work (Jobs)
For long research, the assistant starts sub-agents (up to 3 in parallel), each with
a cost cap ($2 by default; "budget $5" for more). The report arrives in the space that asked for it.

The **full** report is also saved as a note in the library, as soon as the job ends:
- in the **Inbox** by default, named "Job - title - date";
- in another folder if you say so when starting the research ("put the report in /Projects/Bike");
- for meeting minutes, next to the recording.

The assistant gives you the link. The chat message may be shorter; the file keeps everything.
To file it elsewhere, just ask ("put this report in /Projects").

### Tidying jobs: archive, delete, schedule
The **Jobs** page has a search (title, instructions, report) and an **Active / Archived** selector.
Each finished job shows the link to its report note. The link follows the note even if you
move or rename it. If the note has been deleted, the job says so. Each finished job also offers:
- **Archive**: the job leaves the list, without losing anything; you find it under *Archived*.
  **Archive finished** tidies away all the finished jobs of the current view at once. Jobs finished
  more than **30 days** ago are archived automatically.
- **Delete**: erases the job and its steps for good. The report note stays in the library,
  and its costs stay in the usage history. A job run again by a routine cannot
  be deleted: delete the routine first, or archive the job.
- **Schedule…**: creates a routine that runs the same job again (same instructions, model, effort, cap and
  report folder), either once on a given date, or every day, every weekday, every
  week or every month. The routine appears in **Routines**, where you can pause or
  delete it. Each run creates a new job, with its own report.

You can also ask the assistant: "archive the finished jobs", "run the bike watch again every
Monday at 8 am", "find the job about hotels".
