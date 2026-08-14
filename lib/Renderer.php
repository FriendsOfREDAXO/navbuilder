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
 *     type        string   article|link|media|text
 *     label       string   article name, media file name, deliberate override, link/text label
 *     url         ?string  null for text items
 *     text        ?string  raw HTML, only for text items
 *     file        ?string  mediapool file name, only for media items
 *     articleId   ?int     only for article items
 *     categoryId  ?int     category of that article (0 at root level), null for other items
 *     target      ?string  only for link items with an explicit target
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
			rex_logger::factory()->warning('navbuilder: navigation "' . $name . '" does not exist');

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
				$ancestors[(int) $pathId] = true;
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
			if ($child['active'] || $child['activePath']) {
				return true;
			}
		}

		return false;
	}

	private static function url(int $articleId, int $clang, bool $absolute): string
	{
		if (!$absolute) {
			return rex_getUrl($articleId, $clang);
		}

		if (class_exists(\rex_yrewrite::class)) {
			return \rex_yrewrite::getFullUrlByArticleId($articleId, $clang);
		}

		return rtrim(rex::getServer(), '/') . rex_getUrl($articleId, $clang);
	}

	/** Media has no yrewrite equivalent — the configured server is the only absolute base there is. */
	private static function mediaUrl(string $file, bool $absolute): string
	{
		$url = rex_url::media($file);

		return $absolute ? rtrim(rex::getServer(), '/') . '/' . ltrim($url, '/') : $url;
	}
}
