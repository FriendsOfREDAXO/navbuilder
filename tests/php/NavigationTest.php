<?php

declare(strict_types=1);

use FriendsOfRedaxo\NavBuilder\Navigation;
use PHPUnit\Framework\TestCase;

final class NavigationTest extends TestCase
{
	protected function setUp(): void
	{
		rex_logger::$messages = [];
	}

	// ── decode: envelope + malformed input ──────────────────────────────────────────────────

	public function testDecodeAcceptsEnvelopeAndBareList(): void
	{
		$item = ['id' => 'a1b2c3d4', 'type' => 'text', 'label' => 'x', 'children' => []];

		self::assertSame('x', Navigation::decode('{"v":2,"items":[{"id":"a1b2c3d4","type":"text","label":"x","children":[]}]}')[0]['label']);
		self::assertSame('x', Navigation::decode(json_encode([$item]))[0]['label']);
	}

	public function testDecodeDegradesQuietlyWithoutStrict(): void
	{
		self::assertSame([], Navigation::decode(''));
		self::assertSame([], Navigation::decode('{"truncated'));
		self::assertSame([], Navigation::decode('"just a string"'));
	}

	public function testStrictDecodeThrowsOnUnparseableJson(): void
	{
		$this->expectException(rex_functional_exception::class);
		Navigation::decode('{"v":2,"items":[{trunc', true);
	}

	public function testStrictDecodeThrowsOnNonArrayRoot(): void
	{
		$this->expectException(rex_functional_exception::class);
		Navigation::decode('"just a string"', true);
	}

	// ── v1 → v2 mapping ─────────────────────────────────────────────────────────────────────

	public function testV1TypesMapToV2(): void
	{
		$items = Navigation::decode(json_encode([
			['type' => 'intern', 'text' => 'Seite [12]', 'href' => '12', 'children' => []],
			['type' => 'extern', 'text' => 'Extern', 'href' => 'https://example.com', 'children' => []],
			['type' => 'group', 'text' => 'Gruppe', 'children' => []],
		]));

		self::assertSame(['article', 'link', 'text'], array_column($items, 'type'));
		self::assertSame(12, $items[0]['articleId']);
		self::assertArrayNotHasKey('label', $items[0], 'v1 article name cache must not become a label override');
		self::assertSame('https://example.com', $items[1]['url']);
		self::assertSame('Extern', $items[1]['label']);
		self::assertSame('Gruppe', $items[2]['label']);
		self::assertArrayNotHasKey('text', $items[2], 'a legacy group label must not become an HTML body');
	}

	public function testV1NonNumericHrefIsDroppedAndLogged(): void
	{
		$items = Navigation::decode(json_encode([
			['type' => 'intern', 'text' => 'Broken', 'href' => 'not-a-number', 'children' => []],
		]));

		self::assertSame([], $items);
		self::assertStringContainsString('non-numeric article reference', implode(' ', rex_logger::$messages));
	}

	// ── url policy ──────────────────────────────────────────────────────────────────────────

	public function testUnsafeSchemesAreDroppedOrRejected(): void
	{
		$link = static fn (string $url): string => json_encode([['type' => 'link', 'url' => $url, 'label' => 'x', 'children' => []]]);

		self::assertSame([], Navigation::decode($link('javascript:alert(1)')));
		// Control characters must not smuggle a scheme past the check — and must never be stored.
		self::assertSame([], Navigation::decode($link("java\nscript:alert(1)")));
		self::assertSame('https://x.ch', Navigation::decode($link('https://x.ch'))[0]['url']);
		self::assertSame('/kontakt/', Navigation::decode($link('/kontakt/'))[0]['url']);
		self::assertSame('sub.example.ch:8080', Navigation::decode($link('sub.example.ch:8080'))[0]['url'], 'host:port is not a scheme');

		$this->expectException(rex_functional_exception::class);
		Navigation::decode($link('data:text/html,x'), true);
	}

	// ── targets ─────────────────────────────────────────────────────────────────────────────

	public function testTargetIsValidatedForLinkAndMedia(): void
	{
		$items = Navigation::decode(json_encode([
			['type' => 'link', 'url' => '/a', 'target' => '_blank', 'children' => []],
			['type' => 'link', 'url' => '/b', 'target' => 'evil', 'children' => []],
			['type' => 'media', 'file' => 'a.pdf', 'target' => '_blank', 'children' => []],
			['type' => 'media', 'file' => 'b.pdf', 'children' => []],
		]));

		self::assertSame(['_blank', '_self', '_blank', '_self'], array_column($items, 'target'));
	}

	// ── transients, ids, hiddenIn ───────────────────────────────────────────────────────────

	public function testTransientFieldsAreStripped(): void
	{
		$items = Navigation::decode(json_encode([[
			'id' => 'a1b2c3d4', 'type' => 'article', 'articleId' => 5, 'children' => [],
			'_label' => 'X', '_online' => true, '_url' => '/x', '_exists' => true, '_edit' => true,
		]]));

		self::assertSame(['id', 'type', 'articleId', 'clang', 'children'], array_keys($items[0]));
	}

	public function testInvalidOrDuplicateIdsAreRegenerated(): void
	{
		$items = Navigation::decode(json_encode([
			['id' => 'dup', 'type' => 'text', 'label' => 'a', 'children' => []],
			['id' => 'dup', 'type' => 'text', 'label' => 'b', 'children' => []],
			['id' => 'not a valid id!', 'type' => 'text', 'label' => 'c', 'children' => []],
		]));

		$ids = array_column($items, 'id');

		self::assertSame('dup', $ids[0]);
		self::assertNotSame('dup', $ids[1]);
		self::assertCount(3, array_unique($ids));
		self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,32}$/', $ids[2]);
	}

	public function testHiddenInIsWhitelistedAgainstExistingClangs(): void
	{
		$items = Navigation::decode(json_encode([
			['type' => 'text', 'label' => 'x', 'hiddenIn' => [1, 1, 2, 99, 'nope'], 'children' => []],
			['type' => 'text', 'label' => 'y', 'hiddenIn' => [], 'children' => []],
		]));

		self::assertSame([1, 2], $items[0]['hiddenIn']);
		self::assertArrayNotHasKey('hiddenIn', $items[1], 'an empty list is dropped from storage');
	}

	// ── depth ───────────────────────────────────────────────────────────────────────────────

	public function testDepthOf(): void
	{
		self::assertSame(0, Navigation::depthOf([]));

		$nested = ['type' => 'text', 'label' => 'x', 'children' => [
			['type' => 'text', 'label' => 'y', 'children' => [
				['type' => 'text', 'label' => 'z', 'children' => []],
			]],
		]];

		self::assertSame(3, Navigation::depthOf(Navigation::decode(json_encode([$nested]))));
	}

	public function testStrictModeKeepsOverDeepTreesForTheRealDepthCheck(): void
	{
		$item = ['type' => 'text', 'label' => 'deep', 'children' => []];

		for ($i = 0; $i < Navigation::MAX_DEPTH + 2; ++$i) {
			$item = ['type' => 'text', 'label' => 'level', 'children' => [$item]];
		}

		$json = json_encode([$item]);

		// Non-strict (migrations) truncates at the cap and logs; strict keeps everything so
		// save() can reject with the true depth instead of silently flattening.
		self::assertSame(Navigation::MAX_DEPTH, Navigation::depthOf(Navigation::decode($json)));
		self::assertGreaterThan(Navigation::MAX_DEPTH, Navigation::depthOf(Navigation::decode($json, true)));
	}

	public function testItemOverflowIsLogged(): void
	{
		$items = array_fill(0, 1100, ['type' => 'text', 'label' => 'x', 'children' => []]);

		self::assertCount(1000, Navigation::decode(json_encode($items)));
		self::assertStringContainsString('item limit', implode(' ', rex_logger::$messages));
	}

	// ── url addon items ─────────────────────────────────────────────────────────────────────

	public function testUrlItemsNormalize(): void
	{
		$items = Navigation::decode(json_encode([[
			'id' => 'u1a2b3c4', 'type' => 'url', 'profileId' => '3', 'dataId' => 17,
			'label' => 'Override', 'target' => '_blank',
			'_label' => 'transient', '_url' => '/x/', '_exists' => true, '_profile' => 'restaurant',
			'children' => [],
		]]));

		self::assertSame([[
			'id' => 'u1a2b3c4', 'type' => 'url', 'profileId' => 3, 'dataId' => 17,
			'target' => '_blank', 'children' => [], 'label' => 'Override',
		]], $items);
	}

	public function testUrlItemWithoutOverrideStoresNoLabel(): void
	{
		$items = Navigation::decode(json_encode([[
			'id' => 'u1a2b3c4', 'type' => 'url', 'profileId' => 3, 'dataId' => 17, 'children' => [],
		]]));

		self::assertSame('_self', $items[0]['target'], 'missing target must coerce to _self');
		self::assertArrayNotHasKey('label', $items[0], 'no override means no stored label');
	}

	public function testUrlItemWithInvalidReferenceIsDroppedInBothModes(): void
	{
		$broken = json_encode([[
			'id' => 'u1a2b3c4', 'type' => 'url', 'profileId' => 0, 'dataId' => 'x', 'children' => [],
		]]);

		// Mirrors the non-numeric article reference: dropped + logged, never a strict throw —
		// the editor's own clean() cannot post an incomplete url item in the first place.
		self::assertSame([], Navigation::decode($broken));
		self::assertSame([], Navigation::decode($broken, true));
		self::assertStringContainsString('invalid url-addon reference', implode(' ', rex_logger::$messages));
	}

	// ── encode / maxDepth ───────────────────────────────────────────────────────────────────

	public function testEncodeEnvelope(): void
	{
		self::assertSame('{"v":2,"items":[]}', Navigation::encode([]));
		self::assertSame('{"v":2,"maxDepth":3,"items":[]}', Navigation::encode([], 3));
	}

	public function testDecodeMaxDepthClampsAndRejectsJunk(): void
	{
		self::assertNull(Navigation::decodeMaxDepth('{"v":2,"items":[]}'));
		self::assertNull(Navigation::decodeMaxDepth('{"v":2,"maxDepth":0,"items":[]}'));
		self::assertNull(Navigation::decodeMaxDepth('{"v":2,"maxDepth":"nope","items":[]}'));
		self::assertNull(Navigation::decodeMaxDepth('not json'));
		self::assertSame(3, Navigation::decodeMaxDepth('{"v":2,"maxDepth":3,"items":[]}'));
		self::assertSame(Navigation::MAX_DEPTH, Navigation::decodeMaxDepth('{"v":2,"maxDepth":99,"items":[]}'));
	}

	// ── reference lookup (deletion guards) ──────────────────────────────────────────────────

	public function testItemsReferenceMatchesTypeAndFieldAtAnyDepth(): void
	{
		$items = Navigation::decode(json_encode(['v' => 2, 'items' => [
			['id' => 'aaaaaaaa', 'type' => 'link', 'url' => 'https://example.com', 'children' => [
				['id' => 'bbbbbbbb', 'type' => 'article', 'articleId' => 12, 'children' => []],
				['id' => 'cccccccc', 'type' => 'media', 'file' => 'prospekt.pdf', 'children' => []],
			]],
		]]));

		self::assertTrue(Navigation::itemsReference($items, 'article', 'articleId', 12));
		self::assertTrue(Navigation::itemsReference($items, 'media', 'file', 'prospekt.pdf'));
		self::assertFalse(Navigation::itemsReference($items, 'article', 'articleId', 13));
		self::assertFalse(Navigation::itemsReference($items, 'media', 'file', 'other.pdf'));
		// Strict comparison on purpose: an articleId is stored as int, "12" must not match a
		// media file name and vice versa.
		self::assertFalse(Navigation::itemsReference($items, 'article', 'articleId', '12'));
		self::assertFalse(Navigation::itemsReference($items, 'link', 'articleId', 12));
	}
}
