# Bibliothèque — the explorer

The Library page is being turned into a real file manager (decided 2026-11-06, mockup v2 validated by the
user; the mockup itself is not in the repo). It ships in slices, each pushed on its own.

## Constraints that shaped it

- **Space for the list.** Measured on the old page: the first file started 272 px from the top on a computer
  (1280×800) and 298 px on a phone (390×800) — title, seven buttons, search form, breadcrumb. The target is
  one toolbar line (46 px, 48 px on a phone) and a 24 px status line at the bottom.
- **Same actions, fewer buttons.** Nothing is removed: what was a row of buttons is in **＋ New** (upload, note,
  folder) or **⋮** (ask, space, zip, share, rename, sort, shares, recent changes, trash, delete folder).
- **A phone is not a small desktop.** No columns: two-line rows, a tap on the icon selects, the search is a
  magnifier. Sorting and (later) filters live in a bottom sheet / the ⋮ menu.

## Slices

1. **1.19.0 — table, sorting, selection** (done). Columns Name/Type/Size/Modified/By, click-to-sort (kept in
   `localStorage`, key `talixio.lib.sort`), checkboxes, Shift/Ctrl selection, selection bar replacing the
   toolbar (Move/Zip/Delete via `api/files/bulk` and `api/library/zip?ids=`), multi-file drag, status line,
   one-line toolbar.
2. **1.20.0 — filters and grouping** (done). One **View** panel (popover; bottom sheet on a phone) instead of separate Filters/Display buttons: sort, group (type/date), filters type/date/state; chips on the toolbar. Deviation from the mockup: one button, not two.
3. **1.21.0 — Details / Compact list / Tiles** (done). Icons next to View plus a Layout section in the panel; key `talixio.lib.view`. On a phone the rows stay two-line (List = Details); Tiles are two per row.
4. **1.22.0 — explorer navigation** (done). Tree toggle (`talixio.lib.tree`, default hidden under 1100 px), Back (own depth counter over `history.back()`), Up, right-click menus reusing `libFileMenu`/`libFolderMenu` through a pointer anchor, keyboard shortcuts in one `keydown` handler that stands down when a field, dialog, menu, panel or regex search is open.
5. **1.23.0 — preview pane** (done). Right column (`talixio.lib.preview`), desktop only; with it on a click previews and a double click opens; Type/By columns drop; tree default-hidden below 1500 px. Text excerpt comes from `api/files/<id>/text` (first 1 500 characters).

6. **1.24.0 — the four open points** (done, decided 2026-11-07): folder *Modified* = newest file inside (`Library::tree()` → `changed`); `api/library?flat=1` for « everything below » (2 000 files max); pins in `localStorage` (`talixio.lib.pins`); folders selectable for Zip (`api/library/zip?paths=a|b`) and Move (one `folder/move` each), never Delete.

## Decisions and open points

- Folders stay on top whatever the sort; on *Size* they sort by their file count. Their *Modified* column is the newest file inside (1.24.0).
- Folders can be selected for Zip and Move only (1.24.0); Delete stays per folder.
- The old regex-search results keep their own checkboxes and bulk bar (`libRegexRender`); they may merge
  into the selection bar later.
