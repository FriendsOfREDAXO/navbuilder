<?php

/**
 * Text item — copy to `<project>/fragments/navbuilder/text.php` to override.
 *
 * @var rex_fragment $this
 *
 * Vars:
 *   item  array<string, mixed>  one `text` node, see FriendsOfRedaxo\NavBuilder\Renderer::get()
 *   class string                base class name
 *
 * `text` is printed **raw**: it is editor-authored HTML, exactly like a module textarea, and
 * escaping it would defeat the point of the field. Only backend users can write it.
 */

$item = (array) $this->getVar('item', []);
$class = (string) $this->getVar('class', 'navbuilder');
$text = (string) ($item['text'] ?? '');

?>
<span class="<?= rex_escape($class . '__label') ?>"><?= rex_escape($item['label'] ?? '') ?></span>
<?php if ('' !== $text) { ?>
	<div class="<?= rex_escape($class . '__text') ?>"><?= $text ?></div>
<?php } ?>
