<?php

declare(strict_types=1);

/** @var rex_addon $this */

rex_api_function::register('navbuilder_articles', FriendsOfRedaxo\NavBuilder\Api::class);
rex_api_function::register('navbuilder_urls', FriendsOfRedaxo\NavBuilder\UrlApi::class);

// Deleting an article a navigation still points at would make the entry silently vanish at
// render time (the Renderer drops unresolvable articles) — block the deletion instead and link
// to the navigations, same pattern as yrewrite's domain guard. Registered outside the backend
// guard on purpose: deletions can also come through the console.
rex_extension::register('ART_PRE_DELETED', static function (rex_extension_point $ep): void {
	$used = FriendsOfRedaxo\NavBuilder\Navigation::referencing('article', 'articleId', (int) $ep->getParam('id'));

	if ([] === $used) {
		return;
	}

	$links = array_map(
		static fn (array $nav): string => '<a href="' . rex_url::backendPage('navbuilder/menus', ['func' => 'edit', 'id' => $nav['id']]) . '">' . rex_escape($nav['name']) . '</a>',
		$used,
	);

	throw new rex_api_exception(rex_i18n::msg('navbuilder_error_article_in_use') . '<ul><li>' . implode('</li><li>', $links) . '</li></ul>');
});

// Same idea for mediapool files referenced by `media` items — this EP is a soft guard: the
// mediapool refuses the deletion as long as usages are reported.
rex_extension::register('MEDIA_IS_IN_USE', static function (rex_extension_point $ep): array {
	$warning = $ep->getSubject();

	foreach (FriendsOfRedaxo\NavBuilder\Navigation::referencing('media', 'file', (string) $ep->getParam('filename')) as $nav) {
		$warning[] = rex_i18n::msg('navbuilder_media_in_use') . ': <a href="' . rex_url::backendPage('navbuilder/menus', ['func' => 'edit', 'id' => $nav['id']]) . '">' . rex_escape($nav['name']) . '</a>';
	}

	return $warning;
});

if (rex::isBackend()) {
	// package.yml declares `perm: navbuilder[]` on the page, but that alone doesn't make the
	// permission pickable in the role editor — it has to be registered explicitly too.
	rex_perm::register('navbuilder[]');

	// Per-navigation permissions (see NavigationPerm): `navbuilder[manage]` grants full
	// control, the complex perm limits non-managers to selected navigations.
	rex_perm::register('navbuilder[manage]', null, rex_perm::OPTIONS);
	rex_complex_perm::register('navbuilder', FriendsOfRedaxo\NavBuilder\NavigationPerm::class);

	// Assets belong to this addon's own page only — loading the editor globally used to leak
	// its jQuery delegates and body styles into every backend page.
	rex_extension::register('PAGE_CHECKED', static function () {
		if (null === rex::getUser() || 'navbuilder' !== rex_be_controller::getCurrentPagePart(1)) {
			return;
		}

		$addon = rex_addon::get('navbuilder');

		// Order matters: navbuilder.js destructures window.NavBuilderCore at load time.
		foreach (['vue.global.prod.js', 'navbuilder-core.js', 'navbuilder.js'] as $file) {
			if (is_file($path = $addon->getPath('assets/' . $file))) {
				rex_view::addJsFile($addon->getAssetsUrl($file) . '?v=' . $addon->getVersion() . '.' . filemtime($path));
			}
		}

		if (is_file($path = $addon->getPath('assets/navbuilder.css'))) {
			rex_view::addCssFile($addon->getAssetsUrl('navbuilder.css') . '?v=' . $addon->getVersion() . '.' . filemtime($path));
		}
	});

	return;
}

rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): void {
	$content = (string) $ep->getSubject();

	if (!str_contains($content, 'REX_NAVBUILDER[')) {
		return;
	}

	// Never touch a JSON/XML/plain-text response. REDAXO only sends the content type after this
	// extension point, so an explicit non-HTML type set by the project is the only signal there is.
	foreach (headers_list() as $header) {
		if (0 === stripos($header, 'content-type:') && false === stripos($header, 'text/html')) {
			return;
		}
	}

	$replaced = preg_replace_callback(
		'/REX_NAVBUILDER\[name=([a-z0-9_-]+)\]/i',
		static fn (array $match): string => FriendsOfRedaxo\NavBuilder\Renderer::render($match[1]),
		$content,
	);

	if (null !== $replaced) {
		$ep->setSubject($replaced);
	}
});
