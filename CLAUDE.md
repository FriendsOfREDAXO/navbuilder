# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

NavBuilder is a REDAXO 5 addon (PHP >= 8.1, REDAXO ^5.18, structure ^2.9): a drag & drop navigation editor in the backend, rendered in the frontend through overridable fragments. It is developed standalone here but runs inside a REDAXO installation under `redaxo/src/addons/navbuilder/`.

There is **no build step and no runtime dependencies**: Vue 3 ships prebuilt as `assets/vue.global.prod.js` (no CDN); the editor is plain JS (Composition API, template strings, no jQuery) split into `assets/navbuilder-core.js` (pure logic, exposed as frozen `window.NavBuilderCore`, require()-able by Node) and `assets/navbuilder.js` (Vue app; boot.php loads core first).

## Commands

- `composer install` once, then `vendor/bin/phpunit` — PHP tests (`tests/php/`, REDAXO stubbed in `tests/php/bootstrap.php`; only Navigation's pure schema surface, DB paths are exercised in a real instance).
- `node --test "tests/js/**/*.test.mjs"` — tests for `navbuilder-core.js` (a bare directory argument does not work).
- `php -l <file>` / `node --check <file>` for syntax. CI (`.github/workflows/ci.yml`) runs all of the above.

## Architecture

Data model: one DB table `rex_navbuilder_navigation` (`id`, `name`, `structure`, `structure_legacy`, `updated_at`). `structure` is a JSON tree, schema v2 (`{"v":2, "maxDepth"?, "items":[...]}`), documented in README.md. Item types: `article` (stores only the article ID — labels/URLs are always resolved at render time, never cached), `link`, `media` (stores only the filename), `text` (raw HTML content). Any item may carry `hiddenIn: [clang_id,…]` for per-language visibility. The legacy v1 type `group` maps to `text` on read.

PHP (all in `lib/`, namespace `FriendsOfRedaxo\NavBuilder` except the public facade):

- `rex_navbuilder.php` — public facade: `render()` / `tree()` plus deprecated shims `get()` / `getStructure()`. Kept intentionally thin.
- `Navigation.php` — persistence and the schema: load/save/delete/duplicate, `decode`/`encode`/`normalizeItems` (the schema validator; `strict` mode rejects instead of repairing), depth enforcement, the v1→v2 migration (`migrateAll()`, idempotent — rows with `"v":2` are skipped, originals preserved in `structure_legacy`). Names are slugs (`[a-z0-9_-]+`) so they stay addressable by the output filter.
- `Renderer.php` — resolves the tree for the frontend (article names/URLs, `active`/`activePath`, offline/deleted filtering, `hiddenIn` filtering **before** the status check) and renders through fragments.
- `Api.php` — `rex_api_function` for the backend editor's article autocomplete/name refresh (`rex-api-call=navbuilder_articles`). Backend-only, CSRF-protected.

Entry points wired in `boot.php`: the API registration, the `navbuilder[]` permission, backend assets loaded **only on this addon's own page** (via `PAGE_CHECKED`), and the frontend `OUTPUT_FILTER` that replaces `REX_NAVBUILDER[name=…]` in templates/modules.

Backend editor: `pages/menus.php` renders the shell; everything dynamic crosses to `assets/navbuilder.js` through exactly two points — `window.NavBuilderInit` (JSON, includes all i18n strings and server-enriched `_`-prefixed display fields) and a hidden input holding the structure JSON on submit. **This contract is documented at the top of `pages/menus.php` and must not change silently.** The `NAVBUILDER_INIT` extension point lets projects modify the init payload (e.g. attach a WYSIWYG class to text fields).

## Rules that are easy to break

- **The server is authoritative.** Client-side checks (depth limit, link-scheme detection, `maxDepth` admin-only) are conveniences; `Navigation::save()` re-validates everything and rejects — never silently repairs — invalid input. Keep both sides in sync (`SCHEMES` in navbuilder.js mirrors `Navigation::SCHEMES`).
- **No cached article/media names in stored data.** A `label` on an `article` item is an editorial override, never a cache. Resolution happens at render time.
- **No rendered string literals in navbuilder.js** — every user-visible string comes from `NavBuilderInit.i18n` (lang files in `lang/`, German is the primary language, keep `de` and `en` in sync).
- **`_`-prefixed item fields are transient** server display data; the `clean()` whitelist in navbuilder.js must keep stripping them from the posted payload.
- All fragment output goes through `rex_escape()` **except** the `text` field in `fragments/navbuilder/text.php`, which is deliberately raw (editor-written HTML, same trust boundary as module content).
- `update.php` / `Navigation::migrateAll()` must stay idempotent and never overwrite `structure_legacy`.
- README.md (German) is the primary documentation and describes behavior in detail — keep it and README.en.md updated together when behavior changes.

## Conventions

- PHP: `declare(strict_types=1)`, tabs for indentation, `final` classes, heavy doc-block documentation of contracts and non-obvious decisions (match this density in this repo, it is the norm here).
- Version/changelog: `package.yml` (`version`), `CHANGELOG.md`. Release publishing runs via `.github/workflows/publish-to-redaxo-org.yml` on GitHub release.
