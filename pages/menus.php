<?php

/**
 * Backend page: navigation list + editor shell.
 *
 * ── Contract with the frontend app (assets/navbuilder.js) ────────────────────────────────────
 *
 * This page renders the shell only. Everything dynamic is handed over through exactly two
 * places, both of which are part of the public contract and must not change silently:
 *
 * 1. `window.NavBuilderInit` — a JSON object emitted in an inline <script> right before the
 *    addon's own scripts run. Shape:
 *
 *    {
 *      "mountId":  "navbuilder-app",          // id of the empty div the Vue app mounts on
 *      "outputId": "navbuilder-structure",    // id of the hidden input described in (2)
 *      "formId":   "navbuilder-form",         // id of the surrounding <form>, for the submit hook
 *      "structure": {                          // current state, server-enriched
 *        "v": 2,
 *        "maxDepth": 3,                        // optional per-navigation nesting cap, null = none
 *        "items": [
 *          { "id": "a1b2c3d4", "type": "article", "articleId": 12, "clang": null, "children": [],
 *            "label": "optional editorial override", "hiddenIn": [2],
 *            "_exists": true, "_label": "Kontakt", "_online": true, "_url": "/kontakt/" },
 *          { "id": "e5f6a7b8", "type": "link",  "url": "https://…", "label": "Extern",
 *            "target": "_self|_blank|_top", "children": [] },
 *          { "id": "b1c2d3e4", "type": "media", "file": "prospekt.pdf", "label": "Prospekt",
 *            "children": [], "_exists": true, "_url": "/media/prospekt.pdf" },
 *          { "id": "c9d0e1f2", "type": "text", "label": "Service", "text": "<p>…</p>",
 *            "children": [] },
 *          { "id": "d3e4f5a6", "type": "url", "profileId": 3, "dataId": 17, "target": "_self",
 *            "children": [], "_exists": true, "_label": "Pizzeria Toni",
 *            "_url": "/restaurants/pizzeria-toni/", "_profile": "restaurant" }
 *        ]
 *      },
 *      "clangs":  [ { "id": 1, "code": "de", "name": "Deutsch" } ],  // for the visibility chips
 *      "isAdmin": true,                        // gates the maxDepth field
 *      "maxDepthLimit": 10,                    // Navigation::MAX_DEPTH, the global hard cap
 *      "articles": [ { "id": 12, "name": "Kontakt", "path": "Home / Service", "online": true } ],
 *                  // NOT the full article list: this is Api::searchArticles('') — the first
 *                  // Api::LIMIT (30) articles of the current clang, ordered by name. It exists
 *                  // so the picker has something to show before the first keystroke. Any site
 *                  // with more than 30 articles is truncated silently, so the picker MUST query
 *                  // api.autocompleteUrl for real lookups and must never treat this as complete.
 *      "features": { "url": true },            // url addon installed? gates the URL item type
 *      "urls": [ { "profileId": 3, "dataId": 17, "name": "…", "profile": "restaurant", "url": "/…/" } ],
 *                  // seed suggestions, same caveat as `articles`: first 30 only, never complete
 *      "urlProfiles": [ { "id": 3, "namespace": "restaurant" } ],   // for the picker's filter

 *      "api": {
 *        "autocompleteUrl": "…/index.php?rex-api-call=navbuilder_articles",
 *        "csrfToken": "…",                     // token for the api endpoint (id = Api class name)
 *        "csrfField": "_csrf_token",           // request parameter name for the token
 *        "urlAutocompleteUrl": "…rex-api-call=navbuilder_urls"    // + urlCsrfToken for it
 *      },
 *      "linkmap": {
 *        "fieldId":     "REX_LINK_navbuilder",       // hidden input, receives the chosen article id
 *        "nameFieldId": "REX_LINK_navbuilder_NAME",  // readonly input, receives "Name [id]"
 *        "openParams":  "&clang=1&category_id=0"     // 2nd argument for openLinkMap()
 *      },
 *      "i18n": { "<key without the navbuilder_js_ prefix>": "<translated string>", … }
 *    }
 *
 *    The whole object passes through the `NAVBUILDER_INIT` extension point before it is encoded,
 *    so a project `boot.php` can add to it — e.g. hand the text item's textarea an extra class:
 *
 *        rex_extension::register('NAVBUILDER_INIT', function (rex_extension_point $ep) {
 *            $init = $ep->getSubject();
 *            $init['textClass'] = 'tiny-editor';   // applied to `.navbuilder-text-input`
 *            return $init;
 *        });
 *
 *    The `_`-prefixed fields are transient display data resolved live by the server
 *    (Navigation::enrich()). They are the reason a renamed article never shows a stale label.
 *    They MUST NOT be serialized back — Navigation::normalizeItems() drops unknown keys anyway,
 *    but the app should strip them so the posted payload stays honest.
 *
 * 2. `<input type="hidden" name="config[structure]" id="navbuilder-structure">` — the app writes
 *    `JSON.stringify({v: 2, items: […]})` into it on form submit (tw_gridbuilder pattern, no AJAX
 *    save). The server re-validates and re-normalizes everything it receives; a bare item list is
 *    accepted as well. `config[name]` carries the navigation name.
 *
 * Load order: the addon's scripts come from rex_view::addJsFile() and therefore run in <head>,
 * while `window.NavBuilderInit` is written at the end of <body>. The app must init on
 * `DOMContentLoaded` (or later) — never poll for the object.
 *
 * Without JS the shell degrades to "name + save" — the structure input keeps its current value.
 * ────────────────────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

use FriendsOfRedaxo\NavBuilder\Api;
use FriendsOfRedaxo\NavBuilder\Navigation;
use FriendsOfRedaxo\NavBuilder\UrlAddon;
use FriendsOfRedaxo\NavBuilder\UrlApi;

/** @var rex_addon $this */

$id = rex_request('id', 'int');
$func = rex_request('func', 'string');
$csrf = rex_csrf_token::factory('navbuilder_menu');

// Set on a failed save so the editor below can re-render the posted tree instead of reloading
// the (unchanged) DB copy and discarding the user's edits.
$saveErrorItems = null;

if (in_array($func, ['save', 'delete', 'duplicate'], true)) {
	if (!$csrf->isValid()) {
		echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
		$func = '';
	} else {
		try {
			if ('save' === $func) {
				$config = (array) rex_post('config', 'array', []);
				$name = (string) ($config['name'] ?? '');
				$id = Navigation::save($id > 0 ? $id : null, $name, (string) ($config['structure'] ?? ''));

				echo rex_view::success(rex_i18n::rawMsg('navbuilder_msg_saved', rex_escape($name)));
				$func = 'edit';
			} elseif ('delete' === $func) {
				echo Navigation::delete($id)
					? rex_view::success(rex_i18n::msg('navbuilder_msg_deleted'))
					: rex_view::warning(rex_i18n::msg('navbuilder_error_not_found'));
				$func = '';
			} else {
				$copy = Navigation::duplicate($id);

				echo null !== $copy
					? rex_view::success(rex_i18n::msg('navbuilder_msg_duplicated'))
					: rex_view::warning(rex_i18n::msg('navbuilder_error_not_found'));
				$func = '';
			}
		} catch (rex_functional_exception|rex_sql_exception $e) {
			echo rex_view::error(rex_escape($e->getMessage()));
			$func = $id > 0 ? 'edit' : 'add';

			// Re-render from the posted structure instead of the (unchanged) DB row — and without
			// normalizeItems(), which would drop exactly the item whose url was just refused.
			// The editor must get the tree back verbatim to fix it; its own normalize()/clean()
			// tolerate unknown shapes, and the next save re-validates everything anyway.
			if (isset($config)) {
				$data = json_decode((string) ($config['structure'] ?? ''), true);
				$posted = is_array($data) ? ($data['items'] ?? $data) : [];

				try {
					$saveErrorItems = Navigation::enrich(is_array($posted) ? $posted : []);
				} catch (Throwable) {
					// Structurally broken beyond what enrich() tolerates — fall back to the
					// normalized (lossy) tree rather than a blank editor.
					$saveErrorItems = Navigation::enrich(Navigation::decode((string) ($config['structure'] ?? '')));
				}
			}
		}
	}
}

if (!in_array($func, ['add', 'edit'], true)) {
	// ── List ────────────────────────────────────────────────────────────────────────────────
	$list = rex_list::factory(
		'SELECT `id`, `name`, CONCAT(\'REX_NAVBUILDER[name=\', `name`, \']\') AS `snippet`, `updated_at` FROM ' . Navigation::table() . ' ORDER BY `name` ASC',
	);
	$list->addTableAttribute('class', 'table-striped');
	$list->setNoRowsMessage(rex_i18n::msg('navbuilder_list_empty'));

	$addIcon = '<a href="' . $list->getUrl(['func' => 'add']) . '" title="' . rex_escape(rex_i18n::msg('navbuilder_add')) . '"><i class="rex-icon rex-icon-add-action"></i></a>';
	$list->addColumn($addIcon, '<i class="rex-icon fa-bars"></i>', 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
	$list->setColumnParams($addIcon, ['func' => 'edit', 'id' => '###id###']);

	$list->setColumnLabel('name', rex_i18n::msg('navbuilder_name'));
	$list->setColumnParams('name', ['func' => 'edit', 'id' => '###id###']);

	$list->setColumnLabel('snippet', rex_i18n::msg('navbuilder_snippet'));
	$list->setColumnLayout('snippet', ['<th>###VALUE###</th>', '<td><code>###VALUE###</code></td>']);

	$list->setColumnLabel('updated_at', rex_i18n::msg('navbuilder_updated_at'));
	$list->setColumnFormat('updated_at', 'date', 'd.m.Y H:i');

	$list->addColumn('duplicate', '<i class="rex-icon fa-copy"></i> ' . rex_i18n::msg('navbuilder_duplicate'));
	$list->setColumnLabel('duplicate', '');
	$list->setColumnParams('duplicate', ['func' => 'duplicate', 'id' => '###id###'] + $csrf->getUrlParams());

	$list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('navbuilder_delete'));
	$list->setColumnLabel('delete', '');
	$list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###'] + $csrf->getUrlParams());
	$list->addLinkAttribute('delete', 'data-confirm', rex_i18n::msg('navbuilder_confirm_delete'));

	$list->removeColumn('id');

	$fragment = new rex_fragment();
	$fragment->setVar('title', rex_i18n::msg('navbuilder_menus'), false);
	$fragment->setVar('content', $list->get(), false);
	echo $fragment->parse('core/page/section.php');

	return;
}

// ── Editor ──────────────────────────────────────────────────────────────────────────────────
$navigation = $id > 0 ? Navigation::get($id) : null;

if ($id > 0 && null === $navigation) {
	echo rex_view::error(rex_i18n::msg('navbuilder_error_not_found'));

	return;
}

// After a failed save the posted name wins — reloading the DB name (or '' for a new navigation)
// would throw away what the user just typed along with the error they have to fix.
$name = null !== $saveErrorItems && isset($config)
	? (string) ($config['name'] ?? '')
	: (null !== $navigation ? $navigation->name : '');
$items = null !== $saveErrorItems ? $saveErrorItems : (null !== $navigation ? Navigation::enrich($navigation->items) : []);
$maxDepth = null !== $saveErrorItems && isset($config)
	? Navigation::decodeMaxDepth((string) ($config['structure'] ?? ''))
	: (null !== $navigation ? $navigation->maxDepth : null);

$mountId = 'navbuilder-app';
$outputId = 'navbuilder-structure';
$formId = 'navbuilder-form';
$linkmapId = 'navbuilder';

$init = [
	'mountId' => $mountId,
	'outputId' => $outputId,
	'formId' => $formId,
	'structure' => ['v' => Navigation::SCHEMA_VERSION, 'maxDepth' => $maxDepth, 'items' => $items],
	'articles' => Api::searchArticles(''),
	// The url addon is an optional peer — the server decides, the client never probes.
	'features' => ['url' => UrlAddon::available()],
	'urls' => UrlAddon::search(''),
	'urlProfiles' => UrlAddon::profiles(),
	'clangs' => array_map(
		static fn (rex_clang $clang): array => ['id' => $clang->getId(), 'code' => $clang->getCode(), 'name' => $clang->getName()],
		array_values(rex_clang::getAll()),
	),
	'isAdmin' => rex::getUser()?->isAdmin() ?? false,
	'maxDepthLimit' => Navigation::MAX_DEPTH,
	'api' => [
		'autocompleteUrl' => rex_url::backendController(['rex-api-call' => 'navbuilder_articles'], false),
		'csrfToken' => rex_csrf_token::factory(Api::tokenId())->getValue(),
		'csrfField' => rex_csrf_token::PARAM,
		'urlAutocompleteUrl' => rex_url::backendController(['rex-api-call' => 'navbuilder_urls'], false),
		'urlCsrfToken' => rex_csrf_token::factory(UrlApi::tokenId())->getValue(),
	],
	'linkmap' => [
		'fieldId' => 'REX_LINK_' . $linkmapId,
		'nameFieldId' => 'REX_LINK_' . $linkmapId . '_NAME',
		'openParams' => '&clang=' . rex_clang::getCurrentId() . '&category_id=0',
	],
	// `openREXMedia()` prefixes `REX_MEDIA_` itself, so it gets the bare opener id.
	'mediapool' => [
		'openerId' => $linkmapId,
		'fieldId' => 'REX_MEDIA_' . $linkmapId,
		'openParams' => '',
	],
	'i18n' => [],
];

// Keys handed to the app, without the `navbuilder_js_` prefix. Keep in sync with lang/*.lang.
$jsKeys = [
	'add_article', 'add_below', 'add_link', 'add_media', 'add_text', 'add_url', 'apply', 'article', 'cancel',
	'choose_article', 'choose_media', 'choose_url', 'article_required', 'confirm_remove', 'deleted', 'duplicate',
	'edit', 'empty', 'file', 'hidden_everywhere', 'hidden_in', 'item_id',
	'item_type', 'kind_email',
	'kind_tel', 'kind_url', 'label', 'label_override', 'link', 'link_kind', 'link_placeholder',
	'link_value', 'max_depth', 'max_depth_hint', 'media', 'media_required', 'move_down', 'move_in',
	'move_out', 'move_up', 'new_window', 'no_results', 'offline',
	'remove', 'search', 'search_placeholder', 'suggestions', 'text', 'text_content', 'text_discarded',
	'url', 'url_addon_missing', 'url_all_profiles', 'url_item_required', 'url_label_override', 'url_no_results',
	'url_profile', 'url_required', 'url_scheme', 'url_search_placeholder', 'visible_in',
];

foreach ($jsKeys as $key) {
	$init['i18n'][$key] = rex_i18n::msg('navbuilder_js_' . $key);
}

/**
 * Last stop before the contract goes over the wire — a project can add keys here (documented
 * above; `textClass` is the one the app itself reads).
 *
 * @var array<string, mixed> $init
 */
$init = (array) rex_extension::registerPoint(new rex_extension_point('NAVBUILDER_INIT', $init));

$body = '
	<div class="form-group">
		<label class="control-label" for="navbuilder-name">' . rex_i18n::msg('navbuilder_name') . '</label>
		<input class="form-control" type="text" id="navbuilder-name" name="config[name]" value="' . rex_escape($name) . '" required maxlength="191" autocomplete="off">
	</div>
	<div class="form-group">
		<label class="control-label">' . rex_i18n::msg('navbuilder_structure') . '</label>
		<div id="' . $mountId . '" class="navbuilder-app"><p class="text-muted">' . rex_i18n::msg('navbuilder_loading') . '</p></div>
		<input type="hidden" id="' . $outputId . '" name="config[structure]" value="' . rex_escape(Navigation::encode(Navigation::normalizeItems($items), $maxDepth)) . '">
	</div>
	<div class="navbuilder-linkmap" hidden aria-hidden="true">'
		. rex_var_link::getWidget($linkmapId, 'navbuilder_linkmap', 0, ['category' => 0])
		. rex_var_media::getWidget($linkmapId, 'navbuilder_mediapool', '', ['category' => 0])
		. '</div>
';

$buttons = '
	<button class="btn btn-save rex-form-aligned" type="submit" name="func" value="save">' . rex_i18n::msg('navbuilder_save') . '</button>
	<a class="btn btn-abort" href="' . rex_url::currentBackendPage() . '">' . rex_i18n::msg('navbuilder_cancel') . '</a>
';

if ($id > 0) {
	$buttons .= '<a class="btn btn-delete pull-right" href="' . rex_url::currentBackendPage(['func' => 'delete', 'id' => $id] + $csrf->getUrlParams()) . '" data-confirm="' . rex_escape(rex_i18n::msg('navbuilder_confirm_delete')) . '">' . rex_i18n::msg('navbuilder_delete') . '</a>';
}

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('add' === $func ? 'navbuilder_add' : 'navbuilder_edit'), false);
$fragment->setVar('body', $body, false);
$fragment->setVar('buttons', $buttons, false);
$content = $fragment->parse('core/page/section.php');

?>
<form id="<?= $formId ?>" action="<?= rex_url::currentBackendPage() ?>" method="post">
	<input type="hidden" name="id" value="<?= $id ?>">
	<?= $csrf->getHiddenField() ?>
	<?= $content ?>
</form>
<script nonce="<?= rex_response::getNonce() ?>">
window.NavBuilderInit = <?= json_encode($init, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}' ?>;
</script>
