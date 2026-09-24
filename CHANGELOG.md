# Changelog

## Unreleased

- Fix: article URLs were built with `rex_getUrl()`'s default separator `&amp;` and escaped
  once more by the fragments, rendering `href="index.php?article_id=1&amp;amp;clang=2"` —
  the second parameter was lost (visible without yrewrite on multilingual sites, e.g.
  `clang`). `tree()` now returns raw URLs (`&`), like media, link and yrewrite URLs
  already were; the fragments escape exactly once. Same for the `_url` the editor prints
  in an item's hint, which showed a literal `&amp;`. Code that prints `tree()` URLs
  itself must escape them (as for every other URL type) — the READMEs say so now.
  Only code that printed them **unescaped** sees a different string (`&` instead of
  `&amp;`): inside an `href` that keeps working, it becomes visible when such a URL is
  printed as text.

## 2.0.1

- The per-row **+** button inserts an article straight into the edit form instead of showing a
  type picker first (the form's type switch covers the other types). Adding or duplicating an
  entry applies the currently open form first, so only one form is open at a time, and a
  duplicated entry opens for editing right away.
- Fix: duplicating a navigation redirected to YForm's table_field page when the
  yform_usability addon is installed — its `PAGE_CHECKED` hook hijacks every backend request
  with `func=duplicate`. The list action is `func=copy` now.

## 2.0.0

### Per-navigation permissions (#16)

- New complex permission **Navigations** in the role editor: which navigations a role may
  edit (selection bound to the navigation id, rename-safe; "edit all" grants editing
  everything without create/delete rights).
- New option permission **`navbuilder[manage]`**: create, duplicate and delete navigations,
  includes editing all of them; admins implicitly. Without it the list/editor hide those
  actions — enforced server-side either way.
- Deleting a navigation removes its id from all role permission sets.

### Hardening pass (pre-release audit)

- `structure`/`structure_legacy` are `MEDIUMTEXT` now — a legal tree (or one long text body)
  overflows `TEXT`'s 64KB, and truncated JSON decodes as an *empty* navigation. `save()`
  additionally enforces `Navigation::MAX_BYTES`.
- A save whose payload is unparseable JSON is **rejected** instead of silently stored as an
  empty navigation; updating a navigation deleted in the meantime is rejected too.
- After a refused save the editor gets the posted tree back **verbatim** (including the item
  whose url was refused — it used to be dropped by the re-render) and keeps the posted name.
- Page-Save first applies every open edit form; a form that would fail its apply (bad url,
  empty conversion target) blocks the submit and shows its inline error.
- `update.php` preflights v1 names (aborts on >191 chars, warns about non-slug names) and the
  migration reports how many items it had to drop (details in the system log, original in
  `structure_legacy`). The silent item-count overflow drop is logged now.
- The article-autocomplete API requires the `navbuilder[]` permission (CSRF alone is not
  authorization); `mediapool` is a declared dependency; the init script carries the CSP nonce.
- `host:port` urls (`sub.example.ch:8080`) are recognized as such in the editor — the client
  treated them as an unknown scheme and refused what the server accepts.
- Article autocomplete ignores out-of-order responses; a slow early query can no longer
  overwrite newer results.
- The pure editor logic lives in `assets/navbuilder-core.js` (`window.NavBuilderCore`), unit
  tested via `node --test`; PHPUnit covers the schema/migration surface; CI runs both.
- Vendored Vue bumped to 3.5.41.

Complete rewrite. `~440` LOC of PHP/JS plus a 1173-line vendored jQuery drag & drop plugin
become a fragment-based renderer, a validated v2 data model, and a vendored Vue 3 backend editor
— no build step, no CDN, no jQuery.

### Breaking

- Data model v2 (`{"v":2,"items":[…]}`); existing v1 navigations are migrated automatically and
  idempotently on update, with a `structure_legacy` backup column. See `README.md#upgrading-from-1x`.
- `yform` and `phpmailer` dependencies dropped. Requirements now: `redaxo ^5.18`, `php >=8.1`.
- `rex_navbuilder::get()` / `getStructure()` are deprecated in favor of `render()` / `tree()`
  (kept as shims — not removed). `get()`'s hardcoded `rex-*` CSS classes are gone; output now
  comes from overridable fragments, so a project relying on those exact class names must add a
  `fragments/navbuilder/*.php` override.

### Added

- `rex_navbuilder::render()` / `tree()` — fragment-based, overridable frontend API
  (`fragments/navbuilder/{navigation,item}.php`).
- `activePath` (ancestor-active) and `online`/`active` flags on every tree node.
- CSRF-protected article-autocomplete endpoint (`rex_api_function`) for fast article search in
  the backend tree.
- Live, server-enriched `_label`/`_online`/`_url` per article item so a renamed, offline, or
  deleted article never shows a stale label (closes #23, #7).
- "Duplicate" action for navigations in the backend list.
- Native HTML5 drag & drop plus a full keyboard path (up/down/indent/outdent buttons,
  `aria-label`ed) in the backend tree editor.
- `update.php` and `uninstall.php` (previously missing).
- Complete `de_de`/`en_gb` language files (previously an empty `de_de.lang`, no `en_gb.lang`).

### Fixed

Security-relevant defects found during the pre-rewrite analysis:

- **B1** — no CSRF protection on save/delete. Save/delete/duplicate now require a valid
  `rex_csrf_token`; the article-autocomplete API endpoint is CSRF-protected too.
- **B2** — an empty navigation's structure was inlined raw into a `<script>` tag
  (`var navbuilderJson = ;`), a syntax error that killed all backend JS on the page. State is
  now handed over via `json_encode()`/`window.NavBuilderInit`.
- **B3** — editing a deleted navigation's id threw a fatal error; now shows a normal "does not
  exist" message.
- **B4** — no null-check on an unknown navigation name led to warnings and a PHP 8.1
  `json_decode(null)` deprecation; `Navigation::decode()` now degrades to an empty list.
- **B5** — both backend JS files loaded on *every* backend page (global `body` style mutation,
  global jQuery click delegates). Assets are now scoped to the addon's own page.
- **B6** — the linkmap widget preselected article id 1 for a new item; the picker now opens
  empty.
- **B7** — dead code referencing nonexistent DOM elements (`#btnUpdate`, `#internLabel`); moot,
  the whole jQuery editor is replaced.
- **B8** — stored XSS: external link/group text and href were written into frontend HTML
  unescaped. All fragment output now goes through `rex_escape()`.
- **B9** — `getStructure()` did not filter offline articles (unlike `get()`), leaking
  unpublished titles depending on which entry point a project used. `render()`/`tree()` now
  filter offline and deleted articles consistently.
- **B10** — the `REX_NAVBUILDER[name=…]` regex broke on names containing `]` or regex
  metacharacters and ran against non-HTML responses. Now uses a restricted `[a-z0-9_-]+` name
  pattern and skips non-`text/html` responses.
- **B11** — a no-op `->select('id')` on the YForm query builder; moot, YForm dependency removed.
- **B12** — no `update.php`/`uninstall.php`, no migration path, table left behind on uninstall.
  Added.
- **B13** — `de_de.lang` was empty (all strings hardcoded German), no `en_gb.lang`. Added
  complete language files for both.
- **B14** — unused `phpmailer` requirement (the addon never sends mail) removed.
- **B15** — duplicate `class` attribute on the structure `<textarea>`; moot, markup rewritten.
- **B16** — leftover scaffold-addon comments in `pages/index.php` removed.

### Security

- Server-side structure validation on save: whitelisted `type` values, `articleId` coerced to a
  positive int, `link` URLs restricted to `http`/`https`/`mailto`/`tel`/relative (control
  characters stripped before validation to block scheme smuggling via e.g. `java\nscript:`), and
  depth/item-count caps (10 levels / 1000 items) against oversized payloads.
- The normalizer whitelists known keys, so transient `_label`/`_online`/`_url` fields (and
  anything else a tampered request adds) can never round-trip into storage.

### Internal

- Vue 3.5.13 vendored (`assets/vue.global.prod.js`, no CDN, no build step); Composition API,
  template strings.
- jQuery removed — the 1173-line vendored `jquery-menu-editor.js` (`sortableLists` +
  `MenuEditor`) is gone.

### Fixed (final review pass)

- A rejected save (e.g. a `javascript:` link url) no longer discards the editor's unsaved tree —
  `pages/menus.php` re-renders from the posted, non-strictly decoded and re-enriched structure
  instead of reloading the unchanged DB row.
- The extern-link form now pre-checks the url scheme client-side against the same allowlist the
  server enforces (`navbuilder_js_url_scheme`), so an obviously-rejected url is caught before
  submit instead of only after a full-page server error. Server-side validation stays
  authoritative.
- `Navigation::save()` now validates the navigation `name` against `[a-z0-9_-]+`
  (`navbuilder_error_name_invalid`) — the `REX_NAVBUILDER[name=…]` output filter only matches
  that pattern, so any other name previously saved fine but produced a dead snippet. README notes
  names are slugs.
- `navbuilder[]` is now registered via `rex_perm::register()` in `boot.php`, so the permission is
  assignable in the role editor (previously only declared in `package.yml`).

### Added (user-feedback pass)

- **Deletion guards**: an article still referenced by a navigation can no longer be deleted
  (`ART_PRE_DELETED`, the error links the affected navigations — same pattern as yrewrite's
  domain guard), and the mediapool reports files referenced by `media` items as "in use"
  (`MEDIA_IS_IN_USE`) and refuses their deletion.
- **Insert below**: every row has a `+` action that opens the three add buttons as a ghost row
  below the item (and below its children — where the new sibling actually lands) instead of only
  appending at the end of the list. No separate "add child" affordance: the existing indent (`→`)
  button and drag-inside already cover that in one click, a second insertion mode would only
  double the chooser's state.
- **Smart link field**: the link form is one input for url, email address and phone
  number. The kind is detected live and shown as an indicator inside the field; a bare address
  becomes `mailto:…`, a phone number `tel:…` (digits + leading `+`), a bare hostname or a `www.`
  start gets `https://` (anything else containing a `/` stays untouched, so a document-relative
  `downloads/broschuere.pdf` can never turn absolute); relative targets and anything with a
  scheme are stored as typed. Editing such an
  item shows the bare value again — unless stripping the scheme would not round-trip
  (`tel:0800-REDAXO`), then the full url stays visible and untouched. The raw
  `target` select became an "open in a new window" checkbox (`_blank`), shown for real urls only.
  The data model is unchanged — both schemes were already in the `link` allowlist — and the
  detector runs *before* the existing client-side pre-check, never instead of it; the server
  stays authoritative.
- **`categoryId`** on every node of `render()`/`tree()` (int for `article` items, `null`
  otherwise) — plus the item's internal `id` shown in the backend edit form, so a project
  fragment override can branch per item, per category or per article. See
  `README.md#einzelne-einträge-stylen`.
- German `README.md` (as in 1.x), English kept as `README.en.md`, cross-linked.

### Added (feature round: languages, media, text, depth)

- **Per-language visibility**: every item may carry `hiddenIn: [clang_id, …]` — the languages it
  is not rendered in (absent/empty = visible everywhere, empty lists are dropped on save).
  `Renderer` skips such an item together with its children *before* the offline check, so the
  filter is fully independent of `rex_article::status`. The backend shows a chip row per item,
  but only on multi-language installs; rows carry a small hint with the hidden language codes.
- **New item type `media`**: stores the mediapool file name (plus an optional label, falling back
  to the file name). The URL is resolved live via `rex_media::get()`/`rex_url::media()`, so a
  missing file disappears from the frontend and is flagged in the backend — the same handling a
  deleted article gets. Picked through the core mediapool popup (`rex:selectMedia`), rendered as a
  plain `<a href>`. The type switch in the edit form is now **Artikel | Link | Medium**, with the
  same "conversion without a target is refused inline" guard in all three directions.
- **Per-navigation `maxDepth`** on the structure root (absent = unlimited up to the global hard
  cap of 10). Admins get a number field in the editor; the editor blocks indent and drop-inside
  live, and the server *rejects* an over-deep tree on save (naming the limit) instead of silently
  flattening it.
- **`NAVBUILDER_INIT` extension point** — the whole `window.NavBuilderInit` array passes through
  it before encoding, so a project `boot.php` can add to the contract. The app itself reads
  `textClass`, which is applied to the text item's textarea (always classed
  `navbuilder-text-input`) to attach an editor.

### Changed (feature round: languages, media, text, depth)

- **`group` → `text`**: the structural item type is called `text` now, gained an optional `text`
  field holding HTML, and renders through a new overridable fragment
  `fragments/navbuilder/text.php` (label as a `<span>`, `text` printed **raw** — editor-authored
  HTML, same trust boundary as a module textarea). `group` is kept as a decode alias, so stored v1
  *and* 2.0-dev data converts transparently on the next read; `update.php`/`migrateAll()` also
  normalizes existing rows in place and can be re-run for the same version
  (`bin/console package:run-update-script navbuilder <version>`). Children work exactly as before.
- The segmented type switch got balanced horizontal padding (its labels sat flush against the
  button edge).

### Fixed (feature-round review)

- A `text` item with HTML and **no** label had that HTML stored as its label: the v1 label fallback
  (`label ?: text`) collided with the v2 `text` field. The fallback now only applies to the legacy
  raw types, so `label` and `text` are fully independent. The backend row shows a markup-free
  excerpt (or an em-dash) instead of the type name.
- `maxDepth` is admin-only on the server too, not just in the UI: a save by a non-admin ignores the
  posted value and carries the stored one over (new navigations: none), so the cap cannot be lifted
  through a tampered form — and the carried-over cap is what the depth check then enforces.
- An over-deep save is rejected rather than truncated. `normalizeItems()` used to silently drop
  everything past the global 10-level cap, which both under-reported the depth being validated and
  truncated the tree the error page re-renders. Strict mode (saves) now keeps the tree whole and
  lets `save()` reject it, naming the limit and the real depth; migrations still drop and log.
- `media` nodes expose `file` so a fragment override can branch on the file extension.
- The language chips also render on a single-language install when the item still carries a
  `hiddenIn` entry — otherwise an item hidden in the only remaining language was invisible with no
  way to unhide it.
- The type switch is on **text** items too, and gained a fourth option: **Artikel | Link | Medium |
  Text**. Text items had kept the old "groups are structural, no switch" rule through the rename.
  Converting *to* text needs no target (label and body are both optional); converting *away* from a
  text item that has a body shows a standing hint next to the switch that the body will be dropped
  on apply — it is the only thing a conversion cannot carry over, and *Cancel* restores it.

### Changed (user-feedback pass 2)

- Article and link items convert into each other in place via an **Artikel | Link** switch in the
  edit form: the type is committed on apply, `id`/label/children survive, the other type's fields
  are cleared, cancel restores the original. A conversion whose new target is empty (no article
  picked, or no url typed) is refused with an inline error instead of silently dropping the item
  and its children — in both directions. Groups keep no switch.

- The article search is a real combobox: results float in an elevated listbox under the input
  (shadow, scroll, hover/keyboard highlight) instead of sitting inline in the form. Focusing the
  empty field shows the seeded articles under a "Vorschläge" header, typing replaces them with
  live results, picking one (click, Enter, or the linkmap button) closes it; ArrowUp/Down,
  Enter, Escape and click-outside all work, with `role="combobox"`/`listbox`/`option`,
  `aria-expanded` and `aria-activedescendant`.
- Article form is a two-column grid (search + label override side by side), collapsing to one
  column below a 700px *container* width, so a deeply nested item's form stacks by itself.
- "Externer Link" is just **Link** now (add button, type badge, form) — the smart input takes
  urls, email addresses, phone numbers and relative paths, so "extern" was wrong. Lang values
  changed, key names kept.

### Changed (user-feedback pass)

- Tree chrome: no frame around the whole tree, roomier rows/toolbar/forms, tree connector lines
  to each child row, rounded type badges. The url hint no longer renders as a full-width dark
  pill in be_style's dark theme (its `code` background needed a two-class selector to reset).

### Added (url addon integration)

- **New item type `url`**: navigation items can point at a dataset URL generated by the
  [url addon](https://github.com/FriendsOfREDAXO/url) — picked in the backend through a search over
  all generated URLs (with a profile filter once there is more than one profile), resolved at
  render time. Stored are `profileId`/`dataId` only; URL and name (the generator's SEO title) are
  looked up per language, so an item whose language has no generated URL — or whose dataset is
  gone — is skipped like a deleted article, and `label` stays an editorial override. Only canonical
  dataset URLs resolve; the appended `is_user_path`/`is_structure` rows are excluded everywhere.
  Resolved nodes additionally carry `profileId`/`dataId` (`null` on every other type).
- The url addon is an **optional peer, not a requirement**: the type only appears when it is
  installed, and uninstalling it later keeps stored `url` items (flagged in the backend, still
  editable and savable; skipped in the frontend with one log warning per request).
- Note: downgrading to an older NavBuilder version drops `url` items on the next save.

Closes #23, #22, #7.
