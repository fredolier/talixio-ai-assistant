# Calendar and meetings

## Which calendars?
- **Google Calendar** and **Outlook / Microsoft 365**: connect them in Settings → *Calendar* (you can connect both).
  For Outlook, you need to register your own application in Microsoft Entra; the steps are shown
  in the settings.
- **CalDAV** (Nextcloud, Fastmail, iCloud…): address, username and password in Settings → *Calendar*.
- **Read-only** calendars can be added with their iCal link (.ics).

The assistant reads your calendars ("what do I have on Thursday?") and adds events to them **after you approve**:
the approval request shows the title, times, place, repetition and guests. Reply
"yes" (or the number) in the chat, or use the buttons in the notification.

## Recurring meetings
Just ask: "Create a meeting with Cédric and Paul every Monday from 11:00 to 11:30 until Christmas,
except on public holidays".
- Repetition: every day, every working day, every week or every month, until a date (otherwise with no end).
- Excluded days: specific dates, and/or **French public holidays** (Easter, Ascension and Pentecost
  are computed each year).
- Guests: by email address. If you give a first name, the assistant looks for the address in your contacts
  (or asks you for it).
- Google Calendar and Outlook send the invitations themselves. With CalDAV, the guests are listed in
  the event; whether emails are sent depends on your server.

## A task as a meeting slot
- A task can have times (**From** / **To** next to the dates): it then becomes a time slot. It shows
  with its time in the calendar view, and as a "busy" event in Google Calendar, Outlook and the iCal link.
- With **guests** and a **place**, *Send the invitations* sends them a standard invitation
  (Accept / Decline in Outlook, Gmail, Apple Calendar, Thunderbird), from your mailbox if it is
  connected. After a change, use *Send the update*; *Cancel the meeting* (or the trash) lets
  everyone know.

## If the assistant describes its plan without doing it
Reply "yes, go ahead": it must then use its tools, and say clearly what it cannot do.
If this happens again, check the model in use (Settings → *AI model*): small "fast" models
are worse at chaining actions; use the standard tier for these requests.
