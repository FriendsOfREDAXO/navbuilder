<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\NavBuilder;

use rex;
use rex_article;
use rex_clang;
use rex_fragment;
use rex_logger;
use rex_media;
use rex_url;

/**
 * Turns a stored navigation into a resolved tree, and that tree into HTML via fragments.
 *
 * Every node of {@see self::get()} exposes:
 *
 *     id          string   stable item id
 *     type        string   article|link|media|text|url
 *     label       string   article name, media file name, deliberate override, link/text label
 *     url         ?string  raw/unescaped, null for text items - escape it on output
 *     text        ?string  raw HTML, only for text items
 *     file        ?string  mediapool file name, only for media items
 *     articleId   ?int     only for article items
 *     categoryId  ?int     category of that article (0 at root level), null for other items
 *     profileId   ?int     url-addon profile id, only for url items
 *     dataId      ?int     url-addon dataset id, only for url items
 *     target      ?string  only for link, media and url items with an explicit target
 *     online      bool     always true in the returned tree (offline items are filtered out)
 *     active      bool     this item points at exactly the current article
 *     activePath  bool     active, an ancestor of the current article, or has an active descendant
 *     depth       int      1-based
 *     children    list     same shape, recursively
 *
 * Items whose `hiddenIn` contains the rendered clang are skipped together with their children,
 * independently of `rex_article::status` (that filter still applies on top).
 *
 * Supported options: `clang`, `currentId`, `depth` (max levels, 0 = unlimited), `absolute`,
 * plus `fragment`, `itemFragment` and `class` for {@see self::render()}.
 *
 * @api projects may use this class directly (usually via the {@see \rex_navbuilder} facade)
 */
final class Renderer
{
	public const FRAGMENT = 'navbuilder/navigation.php';

	public const ITEM_FRAGMENT = 'navbuilder/item.php';

	/**
	 * @param array<string, mixed> $options
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get(string $name, array $options = []): array
	{
		$navigation = Navigation::load($name);

		if (null === $navigation) {
			rex_logger::factory()->warning('navbuilder: navigation "{name}" does not exist', ['name' => $name]);

			return [];
		}

		return self::buildTree($navigation->items, self::context($options), 1);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public static function render(string $name, array $options = []): string
	{
		return self::renderItems(self::get($name, $options), $options);
	}

	/**
	 * Renders an already resolved tree — useful to render a subtree from a project template.
	 *
	 * @param list<array<string, mixed>> $items
	 * @param array<string, mixed> $options
	 */
	public static function renderItems(array $items, array $options = []): string
	{
		if ([] === $items) {
			return '';
		}

		$wrapper = (string) ($options['fragment'] ?? self::FRAGMENT);

		$fragment = new rex_fragment();
		$fragment->setVar('items', $items, false);
		$fragment->setVar('depth', 1, false);
		$fragment->setVar('class', (string) ($options['class'] ?? 'navbuilder'), false);
		// Both fragment names travel with the vars so an override applies at every nesting level.
		$fragment->setVar('fragment', $wrapper, false);
		$fragment->setVar('itemFragment', (string) ($options['itemFragment'] ?? self::ITEM_FRAGMENT), false);

		return $fragment->parse($wrapper);
	}

	/**
	 * @param array<string, mixed> $options
	 *
	 * @return array{clang: int, currentId: ?int, ancestors: array<int, true>, depth: int, absolute: bool}
	 */
	private static function context(array $options): array
	{
		$clang = isset($options['clang']) ? (int) $options['clang'] : rex_clang::getCurrentId();
		$currentId = array_key_exists('currentId', $options)
			? (null === $options['currentId'] ? null : (int) $options['currentId'])
			: rex_article::getCurrentId();

		$ancestors = [];
		$current = null !== $currentId ? rex_article::get($currentId, $clang) : null;

		if (null !== $current) {
			foreach ($current->getPathAsArray() as $pathId) {
				$ancestors[$pathId] = true;
			}
			$ancestors[$current->getCategoryId()] = true;
		}

		return [
			'clang' => $clang,
			'currentId' => $currentId,
			'ancestors' => $ancestors,
			'depth' => max(0, (int) ($options['depth'] ?? 0)),
			'absolute' => (bool) ($options['absolute'] ?? false),
		];
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @param array{clang: int, currentId: ?int, ancestors: array<int, true>, depth: int, absolute: bool} $ctx
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function buildTree(array $items, array $ctx, int $depth): array
	{
		if (0 !== $ctx['depth'] && $depth > $ctx['depth']) {
			return [];
		}

		$tree = [];

		foreach ($items as $item) {
			// Per-language visibility comes first: a hidden item takes its whole subtree with it,
			// and it does so regardless of whether the article behind it is online.
			if (in_array($ctx['clang'], (array) ($item['hiddenIn'] ?? []), true)) {
				continue;
			}

			$type = (string) ($item['type'] ?? '');
			$children = self::buildTree($item['children'] ?? [], $ctx, $depth + 1);

			$node = [
				'id' => (string) ($item['id'] ?? ''),
				'type' => $type,
				'label' => (string) ($item['label'] ?? ''),
				'url' => null,
				'text' => null,
				'file' => null,
				'articleId' => null,
				'categoryId' => null,
				'profileId' => null,
				'dataId' => null,
				'target' => null,
				'online' => true,
				'active' => false,
				'activePath' => false,
				'depth' => $depth,
				'children' => $children,
			];

			if ('article' === $type) {
				$articleId = (int) ($item['articleId'] ?? 0);
				$clang = is_numeric($item['clang'] ?? null) ? (int) $item['clang'] : $ctx['clang'];
				$article = rex_article::get($articleId, $clang);

				// Deleted or offline articles never reach the frontend; the backend flags them instead.
				if (null === $article || !$article->isOnline()) {
					continue;
				}

				$node['articleId'] = $articleId;
				// Identity a project fragment can branch on without hardcoding article ids.
				$node['categoryId'] = $article->getCategoryId();
				$node['label'] = '' !== $node['label'] ? $node['label'] : $article->getName();
				$node['url'] = self::url($articleId, $clang, $ctx['absolute']);
				$node['active'] = $articleId === $ctx['currentId'];
			} elseif ('link' === $type) {
				$node['url'] = (string) ($item['url'] ?? '');
				$node['target'] = '_self' === ($item['target'] ?? '_self') ? null : (string) $item['target'];
			} elseif ('media' === $type) {
				$file = (string) ($item['file'] ?? '');
				$media = rex_media::get($file);

				// Mirror of the deleted-article rule: gone from the frontend, flagged in the backend.
				if (null === $media) {
					continue;
				}

				// The file name travels with the node so a fragment override can branch on the
				// extension without parsing it back out of the url.
				$node['file'] = $media->getFileName();
				$node['label'] = '' !== $node['label'] ? $node['label'] : $node['file'];
				$node['url'] = self::mediaUrl($node['file'], $ctx['absolute']);
				$node['target'] = '_self' === ($item['target'] ?? '_self') ? null : (string) $item['target'];
			} elseif ('url' === $type) {
				$profileId = (int) ($item['profileId'] ?? 0);
				$dataId = (int) ($item['dataId'] ?? 0);
				$resolved = UrlAddon::find($profileId, $dataId, $ctx['clang']);

				// Missing addon (logged once), missing clang row or deleted dataset — same
				// rule as a deleted article: gone from the frontend, flagged in the backend.
				if (null === $resolved) {
					continue;
				}

				$node['profileId'] = $profileId;
				$node['dataId'] = $dataId;
				// The generator's seo title is render-time resolution, not a navbuilder cache —
				// the url addon keeps it current. An empty title falls back to the path.
				$node['label'] = '' !== $node['label'] ? $node['label'] : ('' !== $resolved['label'] ? $resolved['label'] : $resolved['path']);
				$node['url'] = $ctx['absolute'] ? $resolved['absolute'] : $resolved['path'];
				$node['target'] = '_self' === ($item['target'] ?? '_self') ? null : (string) $item['target'];
				$node['active'] = self::isCurrentPath($resolved['path']);
			} elseif ('text' === $type) {
				// Raw HTML, exactly as the editor stored it — same trust level as a module textarea.
				$node['text'] = (string) ($item['text'] ?? '');
			} else {
				continue;
			}

			$node['activePath'] = $node['active']
				|| (null !== $node['articleId'] && isset($ctx['ancestors'][$node['articleId']]))
				|| self::hasActive($children);

			$tree[] = $node;
		}

		return $tree;
	}

	/**
	 * @param list<array<string, mixed>> $children
	 */
	private static function hasActive(array $children): bool
	{
		foreach ($children as $child) {
			if (true === $child['active'] || true === $child['activePath']) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A url item is "the current page" when the request path equals its generated path. The
	 * url addon resolves every dataset page onto the profile's mount article, so `currentId`
	 * cannot tell one dataset from another — path equality (trailing slash ignored) can.
	 */
	private static function isCurrentPath(string $path): bool
	{
		$request = (string) parse_url(rex_server('REQUEST_URI', 'string', ''), PHP_URL_PATH);

		return '' !== $request && rtrim($request, '/') === rtrim($path, '/');
	}

	/**
	 * Raw URL (`&` as separator): the fragments escape it once on output. rex_getUrl()'s
	 * default separator is `&amp;`, which the fragment's rex_escape() turned into `&amp;amp;`
	 * — the second query parameter (e.g. `clang` without yrewrite) got lost.
	 */
	private static function url(int $articleId, int $clang, bool $absolute): string
	{
		if (!$absolute) {
			return rex_getUrl($articleId, $clang, [], '&');
		}

		if (class_exists(\rex_yrewrite::class)) {
			return \rex_yrewrite::getFullUrlByArticleId($articleId, $clang, [], '&');
		}

		return self::absolute(rex_getUrl($articleId, $clang, [], '&'));
	}

	/** Media has no yrewrite equivalent — the configured server is the only absolute base there is. */
	private static function mediaUrl(string $file, bool $absolute): string
	{
		$url = rex_url::media($file);

		return $absolute ? self::absolute($url) : $url;
	}

	/**
	 * Prefixes the configured server. In the frontend, rex_getUrl() without a rewriter yields
	 * `./index.php?…` (HTDOCS_PATH is `./`); glued straight onto the server that became
	 * `https://example.com./index.php` — hence the trim of `./` on both sides of the join.
	 */
	private static function absolute(string $path): string
	{
		return rtrim(rex::getServer(), '/') . '/' . ltrim($path, './');
	}
}
