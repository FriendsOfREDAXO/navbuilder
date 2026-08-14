<?php

declare(strict_types=1);

use FriendsOfRedaxo\NavBuilder\Renderer;

/**
 * Public frontend API of the navbuilder addon.
 *
 *     echo rex_navbuilder::render('main');
 *     echo rex_navbuilder::render('main', ['fragment' => 'project/nav.php', 'depth' => 2]);
 *     $tree = rex_navbuilder::tree('main');
 *
 * `REX_NAVBUILDER[name=main]` in template or module output is replaced by `render()`.
 */
class rex_navbuilder
{
	/**
	 * Renders a navigation through the (overridable) navbuilder fragments.
	 *
	 * @param array<string, mixed> $options clang, currentId, depth, absolute, class, fragment, itemFragment
	 */
	public static function render(string $name, array $options = []): string
	{
		return Renderer::render($name, $options);
	}

	/**
	 * Returns the resolved navigation tree — see {@see Renderer::get()} for the node shape.
	 *
	 * @param array<string, mixed> $options
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function tree(string $name, array $options = []): array
	{
		return Renderer::get($name, $options);
	}

	/**
	 * @deprecated since 2.0, use {@see rex_navbuilder::render()} — the hardcoded `rex-*` classes
	 *             are gone, output now comes from `fragments/navbuilder/*`
	 *
	 * @param array<string, mixed> $options
	 */
	public static function get(string $name, array $options = []): string
	{
		return Renderer::render($name, $options);
	}

	/**
	 * @deprecated since 2.0, use {@see rex_navbuilder::tree()} — nodes gained `activePath`,
	 *             `online` and `url`, and offline articles are filtered out consistently
	 *
	 * @param array<string, mixed> $options
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function getStructure(string $name, array $options = []): array
	{
		return Renderer::get($name, $options);
	}
}
