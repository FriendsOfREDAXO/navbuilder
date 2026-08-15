/**
 * NavBuilder core — the pure, DOM-free part of the backend editor.
 *
 * Loaded before navbuilder.js as a classic script (no build step, no modules) and exposed as a
 * frozen `window.NavBuilderCore`. The same file is require()-able by Node, which is how
 * `tests/js/core.test.mjs` unit-tests it without any bundler.
 *
 * Everything here mirrors a server-side rule and must stay in sync with it:
 *   TARGETS / SCHEMES            → Navigation::TARGETS / Navigation::SCHEMES
 *   isSafeUrl()                  → Navigation::isSafeUrl() (UX pre-check; the server re-validates)
 *   clean()                      → Navigation::normalizeItems() (whitelist serializer — this is
 *                                  what keeps `_`-prefixed transients out of the posted payload)
 */
(function (root) {
	'use strict';

	const TARGETS = ['_self', '_blank', '_top'];

	/** Everything a `link` item may point at. */
	const SCHEMES = ['http', 'https', 'mailto', 'tel'];

	/** Matches the server's id format: [A-Za-z0-9_-]{1,32}. */
	const uid = () => Math.random().toString(36).slice(2, 10);

	/**
	 * `host:8080` parses as host + port, not as a scheme — PHP's parse_url() (the server-side
	 * check) does the same, so the client mirrors it or it would refuse what the server accepts.
	 * A "port" longer than 5 digits or above 65535 is not a port (`tel:0800123456` stays a scheme).
	 */
	function portLike(value) {
		const match = /^[a-z][a-z0-9+.-]*:(\d{1,5})(?:[/?#]|$)/i.exec(value);

		return null !== match && parseInt(match[1], 10) <= 65535;
	}

	/**
	 * UX-only mirror of Navigation::isSafeUrl() — the server re-validates and stays authoritative.
	 * Strips control characters first, same as the server does before checking (and storing).
	 */
	function isSafeUrl(url) {
		const clean = String(url || '').replace(/[\x00-\x1f\x7f]/g, '');

		if (/^[/#?]/.test(clean)) {
			return true;
		}

		const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(clean);

		return !scheme || portLike(clean) || SCHEMES.indexOf(scheme[1].toLowerCase()) >= 0;
	}

	/**
	 * One input for url, email address and phone number.
	 *
	 * The three helpers below are an input aid only: they decide which prefix a bare value gets,
	 * they never decide whether a value is allowed. `mailto:` and `tel:` are in the scheme
	 * allowlist above (and in Navigation::SCHEMES), so whatever buildLink() produces goes through
	 * exactly the same isSafeUrl() pre-check as a hand-typed url — and the server re-validates.
	 */
	function detectKind(value) {
		const clean = String(value || '').trim();
		const scheme = /^(mailto|tel):/i.exec(clean);

		if (scheme) {
			return 'mailto' === scheme[1].toLowerCase() ? 'email' : 'tel';
		}

		// Anything with its own scheme or a relative start is a plain url — no guessing.
		if ('' === clean || (/^[a-z][a-z0-9+.-]*:/i.test(clean) && !portLike(clean)) || /^[/#?]/.test(clean)) {
			return 'url';
		}

		if (clean.indexOf('@') > 0 && clean.indexOf('/') < 0) {
			return 'email';
		}

		// Separators only count as a phone number on something dialable (`+…` or a trunk `0`) —
		// otherwise `192.168.1.10` and `2024/12/31` would ring.
		return /^\+?[\d\s()./-]+$/.test(clean)
			&& clean.replace(/\D/g, '').length >= 5
			&& (!/[/.]/.test(clean) || /^[+0]/.test(clean))
			? 'tel'
			: 'url';
	}

	/**
	 * Strips the scheme for display — but only when the bare remainder still reads as the same
	 * kind, because buildLink() would not put the scheme back otherwise (`tel:0800-REDAXO`,
	 * `mailto:a@b.c?body=see/x`). Those stay visible in full and round-trip untouched.
	 */
	function splitLink(url) {
		const match = /^(mailto|tel):(.*)$/i.exec(String(url || ''));
		const kind = match && ('mailto' === match[1].toLowerCase() ? 'email' : 'tel');

		return match && detectKind(match[2]) === kind ? match[2] : String(url || '');
	}

	function buildLink(value) {
		const clean = String(value || '').trim();

		// Already carries a scheme (including a typed mailto:/tel:) — never prefix twice.
		// A host:port pair is not a scheme (see portLike) and may still earn its https:// below.
		if ('' === clean || (/^[a-z][a-z0-9+.-]*:/i.test(clean) && !portLike(clean))) {
			return clean;
		}

		const kind = detectKind(clean);

		if ('email' === kind) {
			return 'mailto:' + clean;
		}

		// `tel:` breaks in several clients on spaces and separators; keep digits and a leading +.
		if ('tel' === kind) {
			return 'tel:' + clean.replace(/[^\d+]/g, '');
		}

		// A pure hostname (`example.com`, `sub.example.ch:8080`) or an explicit `www.` is a url
		// with the scheme left out. Anything else containing a slash stays byte-untouched — a
		// document-relative path (`downloads/broschuere.pdf`) is valid input here, and turning it
		// into an absolute url would break it. `example.com/path` losing the convenience is the
		// price for that; relative targets and anything with a space are left alone too.
		const host = clean.indexOf('/') < 0 || /^www\./i.test(clean);

		return host && !/^[/#?]/.test(clean) && !/\s/.test(clean) && clean.indexOf('.') > 0 ? 'https://' + clean : clean;
	}

	/** Whitelisting counterpart of Navigation::normalizeItems() — drops `_` transients and half-filled items. */
	function clean(list) {
		const out = [];

		(list || []).forEach((item) => {
			const children = clean(item.children);
			const label = String(item.label || '').trim();
			let mapped = null;

			if ('article' === item.type) {
				const articleId = parseInt(item.articleId, 10);

				if (!(articleId > 0)) {
					return;
				}

				mapped = {
					id: item.id,
					type: 'article',
					articleId: articleId,
					clang: parseInt(item.clang, 10) > 0 ? parseInt(item.clang, 10) : null,
					children: children,
				};

				if ('' !== label) {
					mapped.label = label;
				}
			} else if ('link' === item.type) {
				const url = String(item.url || '').trim();

				if ('' === url) {
					return;
				}

				mapped = {
					id: item.id,
					type: 'link',
					url: url,
					label: '' !== label ? label : url,
					target: TARGETS.indexOf(item.target) >= 0 ? item.target : '_self',
					children: children,
				};
			} else if ('media' === item.type) {
				const file = String(item.file || '').trim();

				if ('' === file) {
					return;
				}

				mapped = {
					id: item.id,
					type: 'media',
					file: file,
					target: TARGETS.indexOf(item.target) >= 0 ? item.target : '_self',
					children: children,
				};

				if ('' !== label) {
					mapped.label = label;
				}
			} else if ('text' === item.type) {
				const text = String(item.text || '').trim();

				mapped = { id: item.id, type: 'text', label: label, children: children };

				if ('' !== text) {
					mapped.text = text;
				}
			} else {
				return;
			}

			// Absent means "visible everywhere" — an empty list would only be noise in storage.
			const hiddenIn = (item.hiddenIn || []).map(Number).filter((id) => id > 0);

			if (hiddenIn.length) {
				mapped.hiddenIn = hiddenIn;
			}

			out.push(mapped);
		});

		return out;
	}

	const core = Object.freeze({
		TARGETS: TARGETS,
		SCHEMES: SCHEMES,
		uid: uid,
		isSafeUrl: isSafeUrl,
		detectKind: detectKind,
		splitLink: splitLink,
		buildLink: buildLink,
		clean: clean,
	});

	root.NavBuilderCore = core;

	// Node (tests) — a classic browser script otherwise.
	if ('undefined' !== typeof module && module.exports) {
		module.exports = core;
	}
}('undefined' !== typeof window ? window : globalThis));
