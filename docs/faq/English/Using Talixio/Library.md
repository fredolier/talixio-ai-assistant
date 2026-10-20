# The library (your documents)

## Adding documents
- **Upload** or drag and drop on the Library page, into the open folder.
- Attachments from the chat, Telegram or Signal go to `/Inbox`;
  recordings go to `/Meetings`.
- On Android: **Share → Talixio** from any app.

## Filing
- The app suggests a folder and a clear name for each new document in `/Inbox`
  ("scan_0012.pdf → /Admin/Invoices/EDF invoice 2026-10.pdf"): *File it*, *Change…* or *File them all*.
  It learns from how you file. Settings → *Filing new documents (Inbox)*: suggest, file
  the obvious ones, or off.
- Folders: create, rename, move, delete (to the trash); a folder can be downloaded as a ZIP.
- The folder tree **collapses and expands** (the arrow in front of each folder; the button at the top collapses everything).
  The path of the open folder stays expanded, and the state is remembered on this device. While dragging a file,
  a collapsed folder opens if you hold it there for a moment.

## Finding
- Everything is read and indexed in the background: PDF (including scans), Word/Excel/PowerPoint, emails,
  text, audio (transcribed), photos (described, text read).
- Search on the page, or ask the assistant: "based on my documents in /Clients/Alice, …".
- **"Where is…?"**: the page's search first shows the folders and files whose **name** matches
  (ignoring accents and capitals; clicking a folder opens it), then those whose content
  matches. In the chat, "where is the Dupont contract?" gives the path and the link.
- The search runs in two steps: documents that contain **the words** show up right away,
  then the search **by meaning** (local model) completes the list a few seconds later. A spinner
  shows it; *Cancel* (or Esc) stops it and keeps what has already been found. Switching folders or starting
  another search stops the previous one; *Close the search* goes back to the folder.
  It cites the document and the page; a click opens it at that page.
- *Ask about this folder* starts a question about its documents.

## One name per folder, versions
- A name is used only once in a folder (capitals aside). Uploading a file under a name already there,
  the assistant saving a note again, or saving from the network drive **replaces** that file: same file,
  same links and shares, with the new content.
- What it held is kept as a **version**: the *Versions* section of the open file lists them (date, size),
  with *Download* and *Restore* (the current content becomes a version in turn). Versions are kept **30 days**,
  at most 20 per file. The assistant can list and restore them too ("bring back yesterday's version of the plan").
- *Rename* or *Move* onto a name already used there asks: **Replace** (that file gets this content, its old
  content becomes a version) or **Keep both** (this one is named "name (2)").
- Attachments from the chat keep both ("image.png", "image (2).png").
- Files that had the same name in a folder before version 0.28.29 were merged once: the newest is kept,
  the others are its versions.

## Recent changes
- *Recent changes* (top of the Library) lists the files created or changed in the last hour, today, 2 days or
  7 days: folder, what happened (created, changed, moved, renamed, restored), by whom (**you**, **the assistant**,
  **a routine**, **the network drive**) and when. A dot marks a file someone else changed that you have not opened
  since; *Mark all as seen* clears them. Click a file to open it.
- **Notifications**: one grouped notification in the app, at most hourly (or once a day, or never: the menu at the
  bottom), for the files *others* changed — never for your own changes. *Acknowledge* clears one file's dot; *Stop notifying* leaves a folder out of the notifications (*Notify again* undoes it).
- The **morning briefing** has a line like "Since yesterday: 3 new and 2 changed files".
- Ask the assistant: "what changed in my library today?".
- Files of a Secret space appear only in that space. Changes made before this feature show their creation date only.

## Deleting, the trash
- *Delete* (on an open file, or on a folder with its files) moves it to the **trash**; it is not
  deleted for good. What is in the trash leaves the searches, the assistant and the share links.
- The **Trash** is at the bottom of the folder tree (on a phone: the *Trash* button at the top of the page).
  It shows where each file came from, when it was deleted and how many days are left.
- *Restore* puts it back in its place (the folder is created again if needed). A deleted folder comes back
  in one piece, with its files in their subfolders. Nothing is read or transcribed again: it is searchable again right away.
- After **30 days**, a file is erased for good. *Empty the trash* does it right away
  (two clicks). An erased file can then only be found in a nightly backup (7 are kept).
- The assistant sees the trash and can restore: "find the quote I deleted yesterday".
- Files deleted before version 0.19.0 are not there: they were erased right away.

## Notes
*New note* creates an editable Markdown note. The assistant can also save notes (`kb_save_note`).

*Edit* on a Markdown file opens a visual editor: headings, lists, checklists, tables and links look as they will
be read. Type `/` on an empty line for headings, lists, quotes, code, tables or a divider; select text for the
formatting toolbar; in a table, add or move rows and columns from its handles. **Markdown** switches to the source
(files with front matter or HTML open on it). *Full screen* gives the editor the whole window; Esc closes a menu
first, then leaves it. A file you open and save without changing it is kept exactly as it was.

## The /FAQ folder
It is read-only and refreshed with each version: these pages are part of it.
