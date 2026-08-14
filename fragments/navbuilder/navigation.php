<?php

/**
 * Navigation wrapper — copy to `<project>/fragments/navbuilder/navigation.php` to override.
 *
 * @var rex_fragment $this
 *
 * Vars:
 *   items        list<array<string, mixed>>  resolved nodes, see FriendsOfRedaxo\NavBuilder\Renderer::get()
 *   depth        int                         1-based nesting level of this list
 *   class        string                      base class name
 *   fragment     string                      this wrapper fragment, threaded so an override applies at every depth
 *   itemFragment string                      fragment used per item
 */

$items = (array) $this->getVar('items', []);
$depth = (int) $this->getVar('depth', 1);
$class = (string) $this->getVar('class', 'navbuilder');
$wrapperFragment = (string) $this->getVar('fragment', 'navbuilder/navigation.php');
$itemFragment = (string) $this->getVar('itemFragment', 'navbuilder/item.php');

if ([] === $items) {
	return;
}

?>
<ul class="<?= rex_escape($class) ?> <?= rex_escape($class . '--depth-' . $depth) ?>">
	<?php foreach ($items as $item) {
		$fragment = new rex_fragment();
		$fragment->setVar('item', $item, false);
		$fragment->setVar('depth', $depth, false);
		$fragment->setVar('class', $class, false);
		$fragment->setVar('fragment', $wrapperFragment, false);
		$fragment->setVar('itemFragment', $itemFragment, false);
		echo $fragment->parse($itemFragment);
	} ?>
</ul>
