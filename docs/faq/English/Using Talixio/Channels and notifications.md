# Channels and notifications

## App notifications
**Channels** page → *Turn on for this device* (on iPhone: first add it to the Home Screen and
open it from there). One device at a time; no extra service. *Send a test notification* to check.

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

## ntfy
Another way to receive notifications: Settings → *Notifications* → *ntfy address*, with a long, random topic.

## Notifications without content
Settings → *Notifications without content*: "New message in …" instead of the start of the answer.
This is automatic in Confidential and Secret spaces.

Only allowed senders get an answer, on every channel.
