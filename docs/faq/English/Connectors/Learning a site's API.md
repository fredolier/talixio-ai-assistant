# Learning a site's API

Many sites have no public API and no connector, but their own pages load their data from the site's
servers (a search, your orders, a basket). The assistant can watch those calls while it browses the site,
and turn them into a **connector** for that site: next time, it asks the site directly instead of
clicking through pages.

## How to do it
1. Ask: « learn the API of shop.example.com to search products and see my orders ».
2. The assistant opens the site in its browser and goes through the pages that do what you asked. If the
   site needs you to be logged in, log in first (« log me in to shop.example.com »): the assistant types
   what you give it, and asks your approval before sending a login form.
3. It reads the calls the pages made (`browser_api_calls`). What it reads has the **cookies, tokens, keys
   and passwords removed**; long answers are shortened.
4. It describes the useful calls (`site_api_save`) and asks your **approval**. The request lists:
   - the address it will call, which is always that site and nothing else;
   - the calls that only **read**, which will run without asking;
   - the calls that **change** something (add to the basket, order, send…), which will ask you each time;
   - how it signs in.
5. Once approved, the connector's tools work at once, like any connector's.

What is saved is a **description** of the calls (addresses, parameters), never a program: Talixio makes
the calls itself.

## Signing in
- **Your login on the site** (cookies): the connector uses the login you did in the assistant's browser.
  When it expires, the connector says so and the assistant asks you to **log in again** in its browser;
  you don't need to do anything else.
- **An API key**: the assistant never asks for it in the conversation. Enter it in Settings → Connected
  accounts → **Connectors (MCP)** → *Learned from sites* → the connector → **API key** → *Save the key*.
  It is kept encrypted. A refused key: enter a new one there.
- **Nothing**: public calls.

## Seeing and removing them
Settings → Connected accounts → **Connectors (MCP)** → *Learned from sites*: each connector, its site, how
it signs in, and its calls (**asks first** marks the ones that change something). **Remove** deletes it
with its tools. Asking the assistant to learn the same site again replaces it, and keeps the key entered.

## Limits
- It only works for sites whose pages load their data with calls to their own API (most recent sites). A
  site that builds its pages on its server has nothing to learn: the assistant then uses the browser.
- A site that keeps its sign-in token in the page itself (not in a cookie) is not supported yet.
- Sites can change their API at any time: when calls start failing, ask the assistant to learn it again.
- Check the site's terms of use: some don't allow automated access.
