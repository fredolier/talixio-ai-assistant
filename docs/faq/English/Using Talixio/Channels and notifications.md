# Channels and notifications

## App notifications
**Channels** page → *Turn on for this device* (on iPhone: first add it to the Home Screen and
open it from there). One device at a time; no extra service. *Send a test notification* to check.

## Reading your notifications in the app
Everything the assistant sends (reminders, scheduled tasks, job reports, approvals, alerts) is also kept in
the app: **Notifications** in the menu (on a phone: *More* → *Notifications*). A red number shows how many
are unread; the app icon counts them too, with the approvals waiting. Tap one to open its conversation and
mark it read, or *Mark all read*. Opening the page also clears them from the phone's lock screen.
The last 200 are kept, for 60 days. In a Secret space, the list only says where to look.

## Telegram
Create a bot with @BotFather, put its token in Settings → Telegram, then message the bot: it replies
with your id, which you add to the *Allowed chat ids* list.

## Signal
You need a number dedicated to the assistant (a prepaid SIM card or an eSIM that can receive a text message).
Settings → Signal: the assistant's number and yours, then the registration:
- simple install: `sudo talixio signal register`, then `sudo talixio signal verify 123456`;
- under Docker: `docker exec -it talixio-<you> talixio signal register`, then `… verify 123456`, then `docker restart talixio-<you>`.
If a captcha is requested: https://signalcaptchas.org/registration/generate.html, then
`register --captcha 'signalcaptcha://…'`.

## Email
To write to the assistant by email and receive its messages: Settings → *Assistant's email* (SMTP, IMAP, allowed senders).
The assistant's messages (scheduled tasks, briefing, reports, replies) arrive formatted: a card with the title,
the space and its color, the message's headings, lists, tables and links, and a button that opens the app.
For the links and the button to work, the app must know its address: opening it once in the browser is enough
(or Settings → *Public address*). Emails sent on your behalf from your mailbox stay plain, without this frame.

## Outlook / Microsoft 365 mailboxes
Microsoft is turning off passwords for SMTP and IMAP (smtp.office365.com, outlook.office365.com); many
tenants already refuse them ("535 5.7.3 Authentication unsuccessful" or "5.7.30"). Sign in with the
Microsoft account instead:
1. Set up the Microsoft app under Settings → *Calendar* → *Outlook / Microsoft 365* (steps 1 to 5), and
   add the delegated permissions *SMTP.Send* and *IMAP.AccessAsUser.All*.
2. In the email settings (the assistant's, or your mailbox's), *Sign-in* → *Microsoft 365 account (OAuth)*, save.
3. Press *Connect with Microsoft* and sign in with that mailbox's account. Leave the password empty.
The assistant's address, your mailbox and the calendar each have their own connection.
In the Microsoft 365 admin center, *Authenticated SMTP* must stay enabled for the mailbox
(Users → the user → Mail → Manage email apps), even with this sign-in.

## ntfy
Another way to receive notifications: Settings → *Notifications* → *ntfy address*, with a long, random topic.

## Notifications without content
Settings → *Notifications without content*: "New message in …" instead of the start of the answer.
This is automatic in Confidential and Secret spaces.

Only allowed senders get an answer, on every channel.
