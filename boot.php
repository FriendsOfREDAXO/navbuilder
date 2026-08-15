<?php

declare(strict_types=1);

/** @var rex_addon $this */

rex_api_function::register('navbuilder_articles', FriendsOfRedaxo\NavBuilder\Api::class);
rex_api_function::register('navbuilder_urls', FriendsOfRedaxo\NavBuilder\UrlApi::class);

if (rex::isBackend()) {
	// package.yml declares `perm: navbuilder[]` on the page, but that alone doesn't make the
	// permission pickable in the role editor — it has to be registered explicitly too.
	rex_perm::register('navbuilder[]');

	// Assets belong to this addon's own page only — loading the editor globally used to leak
	// its jQuery delegates and body styles into every backend page.
	rex_extension::register('PAGE_CHECKED', static function () {
		if (!rex::getUser() || 'navbuilder' !== rex_be_controller::getCurrentPagePart(1)) {
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
