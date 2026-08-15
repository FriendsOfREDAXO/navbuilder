import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const { uid, isSafeUrl, detectKind, splitLink, buildLink, clean, TARGETS, SCHEMES } = createRequire(import.meta.url)('../../assets/navbuilder-core.js');

test('uid matches the server id format', () => {
	for (let i = 0; i < 100; i++) {
		assert.match(uid(), /^[a-z0-9]{1,32}$/);
	}
});

test('isSafeUrl mirrors the server scheme allowlist', () => {
	assert.equal(isSafeUrl('https://example.com'), true);
	assert.equal(isSafeUrl('mailto:a@b.ch'), true);
	assert.equal(isSafeUrl('tel:+41791234567'), true);
	assert.equal(isSafeUrl('/kontakt/'), true);
	assert.equal(isSafeUrl('#top'), true);
	assert.equal(isSafeUrl('?p=1'), true);
	assert.equal(isSafeUrl('downloads/broschuere.pdf'), true);
	assert.equal(isSafeUrl('javascript:alert(1)'), false);
	assert.equal(isSafeUrl('data:text/html,x'), false);
	// Control characters must not smuggle a scheme past the check.
	assert.equal(isSafeUrl('java\nscript:alert(1)'), false);
	// host:port is not a scheme — PHP parse_url() on the server agrees.
	assert.equal(isSafeUrl('sub.example.ch:8080'), true);
	assert.equal(isSafeUrl('tel:0800123456'), true);
});

test('detectKind classifies input', () => {
	assert.equal(detectKind('foo@bar.ch'), 'email');
	assert.equal(detectKind('+41 79 123 45 67'), 'tel');
	assert.equal(detectKind('0800 123 456'), 'tel');
	assert.equal(detectKind('example.com'), 'url');
	assert.equal(detectKind('192.168.1.10'), 'url');
	assert.equal(detectKind('2024/12/31'), 'url');
	assert.equal(detectKind('mailto:x@y.z'), 'email');
	assert.equal(detectKind('tel:123456'), 'tel');
	assert.equal(detectKind('/kontakt/'), 'url');
	assert.equal(detectKind(''), 'url');
});

test('buildLink prefixes bare values, leaves the rest alone', () => {
	assert.equal(buildLink('foo@bar.ch'), 'mailto:foo@bar.ch');
	assert.equal(buildLink('+41 79 123 45 67'), 'tel:+41791234567');
	assert.equal(buildLink('example.com'), 'https://example.com');
	assert.equal(buildLink('sub.example.ch:8080'), 'https://sub.example.ch:8080');
	assert.equal(buildLink('www.example.com/path'), 'https://www.example.com/path');
	assert.equal(buildLink('downloads/broschuere.pdf'), 'downloads/broschuere.pdf');
	assert.equal(buildLink('example.com/path'), 'example.com/path');
	assert.equal(buildLink('/kontakt/'), '/kontakt/');
	assert.equal(buildLink('https://x.ch'), 'https://x.ch');
	assert.equal(buildLink('mailto:a@b.ch'), 'mailto:a@b.ch');
});

test('splitLink strips schemes only when buildLink would restore them', () => {
	assert.equal(splitLink('mailto:a@b.ch'), 'a@b.ch');
	assert.equal(splitLink('tel:+41791234567'), '+41791234567');
	// Would not round-trip — stays visible in full.
	assert.equal(splitLink('tel:0800-REDAXO'), 'tel:0800-REDAXO');
	assert.equal(splitLink('https://x.ch'), 'https://x.ch');
	assert.equal(buildLink(splitLink('mailto:a@b.ch')), 'mailto:a@b.ch');
});

test('clean whitelists items and strips transients', () => {
	const items = clean([
		{ id: 'a1', type: 'article', articleId: '12', clang: null, label: ' ', _edit: true, _label: 'Kontakt', _online: true, _url: '/x', children: [] },
		{ id: 'l1', type: 'link', url: 'https://x.ch', target: '_blank', hiddenIn: [2], children: [] },
		{ id: 'm1', type: 'media', file: 'a.pdf', target: 'evil', children: [] },
		{ id: 't1', type: 'text', label: 'Service', text: '<p>x</p>', hiddenIn: [], children: [] },
	]);

	assert.deepEqual(items[0], { id: 'a1', type: 'article', articleId: 12, clang: null, children: [] });
	assert.deepEqual(items[1], { id: 'l1', type: 'link', url: 'https://x.ch', label: 'https://x.ch', target: '_blank', children: [], hiddenIn: [2] });
	// Invalid target sanitizes, exactly like Navigation::normalizeItems().
	assert.equal(items[2].target, '_self');
	assert.deepEqual(items[3], { id: 't1', type: 'text', label: 'Service', text: '<p>x</p>', children: [] });
});

test('clean drops half-filled and unknown items, keeps nested children', () => {
	const items = clean([
		{ id: 'x1', type: 'article', articleId: null, children: [] },
		{ id: 'x2', type: 'link', url: '  ', children: [] },
		{ id: 'x3', type: 'media', file: '', children: [] },
		{ id: 'x4', type: 'wat', children: [] },
		{ id: 'p1', type: 'text', label: 'p', children: [{ id: 'c1', type: 'link', url: '/a', children: [] }] },
	]);

	assert.equal(items.length, 1);
	assert.equal(items[0].children[0].id, 'c1');
});

test('constants stay in sync with the server', () => {
	assert.deepEqual(TARGETS, ['_self', '_blank', '_top']);
	assert.deepEqual(SCHEMES, ['http', 'https', 'mailto', 'tel']);
});
