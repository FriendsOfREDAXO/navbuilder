<?php

/**
 * Single navigation item — copy to `<project>/fragments/navbuilder/item.php` to override.
 *
 * @var rex_fragment $this
 *
 * Vars:
 *   item         array<string, mixed>  one node, see FriendsOfRedaxo\NavBuilder\Renderer::get()
 *   depth        int                   1-based nesting level
 *   class        string                base class name
 *   fragment     string                wrapper fragment used for the children list
 *   itemFragment string                fragment used per item
 */

$item = (array) $this->getVar('item', []);
$depth = (int) $this->getVar('depth', 1);
$class = (string) $this->getVar('class', 'navbuilder');
$wrapperFragment = (string) $this->getVar('fragment', 'navbuilder/navigation.php');
$itemFragment = (string) $this->getVar('itemFragment', 'navbuilder/item.php');

$classes = [$class . '__item', $class . '__item--' . ($item['type'] ?? 'text')];

if (!empty($item['active'])) {
	$classes[] = 'is-active';
}

if (!empty($item['activePath'])) {
	$classes[] = 'is-active-path';
}

$children = '';

if (!empty($item['children'])) {
	$fragment = new rex_fragment();
	$fragment->setVar('items', $item['children'], false);
	$fragment->setVar('depth', $depth + 1, false);
	$fragment->setVar('class', $class, false);
	$fragment->setVar('fragment', $wrapperFragment, false);
	$fragment->setVar('itemFragment', $itemFragment, false);
	$children = $fragment->parse($wrapperFragment);
}

?>
<li class="<?= rex_escape(implode(' ', $classes)) ?>">
	<?php if ('text' === ($item['type'] ?? '')) {
		// Own fragment because it is the one item type that prints raw HTML — overriding the
		// escaping policy should not mean re-implementing the link/label branches too.
		$textFragment = new rex_fragment();
		$textFragment->setVar('item', $item, false);
		$textFragment->setVar('class', $class, false);
		echo $textFragment->parse('navbuilder/text.php');
	} elseif (null !== ($item['url'] ?? null)) { ?>
		<a href="<?= rex_escape($item['url']) ?>"<?= null !== ($item['target'] ?? null) ? ' target="' . rex_escape($item['target']) . '" rel="noopener"' : '' ?><?= !empty($item['active']) ? ' aria-current="page"' : '' ?>><?= rex_escape($item['label'] ?? '') ?></a>
	<?php } else { ?>
		<span class="<?= rex_escape($class . '__label') ?>"><?= rex_escape($item['label'] ?? '') ?></span>
	<?php } ?>
	<?= $children ?>
</li>
