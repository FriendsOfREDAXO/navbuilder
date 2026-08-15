/**
 * NavBuilder 2.0 — backend navigation editor.
 *
 * Vue 3 global build (shipped in this addon, no CDN), template strings, Composition API,
 * no build step, no jQuery. The contract with the server — `window.NavBuilderInit` and the
 * hidden input — is documented at the top of `pages/menus.php`.
 *
 * Two rules that are easy to break and expensive to debug:
 *
 *  - Everything the user sees comes from `NavBuilderInit.i18n`. No string literal in this file
 *    is ever rendered.
 *  - `_`-prefixed item fields are transient display data from the server. {@see clean} is a
 *    whitelist, so they can never leak back into the hidden input — the server drops them too,
 *    but the posted payload should be honest on its own.
 */
(function () {
	'use strict';

	// The pure, Node-testable half lives in navbuilder-core.js (loaded first by boot.php).
	const { uid, isSafeUrl, detectKind, splitLink, buildLink, clean } = window.NavBuilderCore;

	/** Item fields the editor may change — used for the edit-form snapshot (cancel = revert). */
	const EDITABLE = ['type', 'articleId', 'clang', 'label', 'url', 'target', 'file', 'text', 'hiddenIn', 'profileId', 'dataId', '_label', '_online', '_url', '_exists', '_profile'];

	const SEARCH_DELAY = 250;

	function ready(fn) {
		if ('loading' === document.readyState) {
			document.addEventListener('DOMContentLoaded', fn);

			return;
		}

		fn();
	}

	// The addon's scripts run in <head>, `window.NavBuilderInit` is written at the end of <body>.
	ready(function () {
		const init = window.NavBuilderInit;
		const mount = init ? document.getElementById(init.mountId) : null;

		if (init && mount && window.Vue) {
			start(window.Vue, init, mount);
		}
	});

	function start(Vue, init, mount) {
		const { createApp, reactive, ref, computed, watch, nextTick, onBeforeUnmount } = Vue;

		const t = init.i18n || {};
		const api = init.api || {};
		const linkmap = init.linkmap || {};
		const mediapool = init.mediapool || {};
		const clangs = Array.isArray(init.clangs) ? init.clangs : [];
		const features = init.features || {};
		const urlProfiles = Array.isArray(init.urlProfiles) ? init.urlProfiles : [];
		const output = document.getElementById(init.outputId);
		const form = document.getElementById(init.formId) || (output ? output.closest('form') : null);
		const items = reactive(normalize((init.structure || {}).items));
		const dnd = reactive({ item: null, list: null, over: null, pos: '' });

		/** Open edit forms by item id → their apply function. Save applies them all first. */
		const openForms = new Map();

		/** Navigation-wide settings living on the structure root, not on an item. */
		const HARD_DEPTH = parseInt(init.maxDepthLimit, 10) > 0 ? parseInt(init.maxDepthLimit, 10) : 10;
		const settings = reactive({ maxDepth: parseInt((init.structure || {}).maxDepth, 10) > 0 ? parseInt((init.structure || {}).maxDepth, 10) : '' });

		/** The cap currently in force — the navigation's own, or the global one. */
		const limit = () => (parseInt(settings.maxDepth, 10) > 0 ? Math.min(parseInt(settings.maxDepth, 10), HARD_DEPTH) : HARD_DEPTH);

		/** Levels an item occupies including its deepest descendant. */
		function height(item) {
			return 1 + (item.children || []).reduce((max, child) => Math.max(max, height(child)), 0);
		}

		// ── Model ───────────────────────────────────────────────────────────────────────────

		function normalize(raw) {
			return (Array.isArray(raw) ? raw : []).map((item) => Object.assign({}, item, {
				id: item.id || uid(),
				children: normalize(item.children),
				_edit: false,
			}));
		}

		function makeItem(type) {
			const item = { id: uid(), type: type, label: '', hiddenIn: [], children: [], _edit: true };

			if ('article' === type) {
				item.articleId = null;
				item.clang = null;
			} else if ('link' === type) {
				item.url = '';
				item.target = '_self';
			} else if ('media' === type) {
				item.file = '';
				item.target = '_self';
			} else if ('url' === type) {
				item.profileId = null;
				item.dataId = null;
				item.target = '_self';
			} else if ('text' === type) {
				item.text = '';
			}

			return item;
		}

		function cloneItem(item) {
			const copy = JSON.parse(JSON.stringify(item));

			copy.id = uid();
			copy._edit = false;
			copy.children = (copy.children || []).map(cloneItem);

			return copy;
		}

		function isIncomplete(item) {
			if ('article' === item.type) {
				return !(parseInt(item.articleId, 10) > 0);
			}

			if ('media' === item.type) {
				return '' === String(item.file || '').trim();
			}

			if ('url' === item.type) {
				return !(parseInt(item.profileId, 10) > 0 && parseInt(item.dataId, 10) > 0);
			}

			return 'link' === item.type && '' === String(item.url || '').trim();
		}

		function contains(parent, needle) {
			return (parent.children || []).some((child) => child === needle || contains(child, needle));
		}

		// ── Serialization ───────────────────────────────────────────────────────────────────

		function serialize() {
			if (!output) {
				return;
			}

			const maxDepth = parseInt(settings.maxDepth, 10);
			const payload = { v: 2 };

			if (maxDepth > 0) {
				payload.maxDepth = Math.min(maxDepth, HARD_DEPTH);
			}

			payload.items = clean(items);
			output.value = JSON.stringify(payload);
		}

		// On submit (the actual save) and on every mutation, so the input never lags behind the tree.
		if (form) {
			form.addEventListener('submit', (event) => {
				// Apply every open edit form first — Save must not post half-edited state, and a
				// form whose apply would fail (bad url, empty conversion target) blocks the submit
				// so its inline error is seen instead of a full-page rejection.
				for (const apply of Array.from(openForms.values())) {
					if (false === apply()) {
						event.preventDefault();

						return;
					}
				}

				serialize();
			});
		}

		watch(items, serialize, { deep: true });
		watch(settings, serialize);

		// ── Article lookup ──────────────────────────────────────────────────────────────────

		/** Everything the endpoint (or the seed list) ever reported, by article id — the picker's `online` source. */
		const known = new Map();

		function remember(articles) {
			articles.forEach((article) => known.set(article.id, article));

			return articles;
		}

		/** The seeded article list — what the combobox shows as suggestions before any search. */
		function seed() {
			return Array.isArray(init.articles) ? init.articles.slice() : [];
		}

		remember(seed());

		function fetchArticles(q) {
			const url = api.autocompleteUrl
				+ (api.autocompleteUrl.indexOf('?') < 0 ? '?' : '&')
				+ 'q=' + encodeURIComponent(q)
				+ '&' + encodeURIComponent(api.csrfField || '_csrf_token') + '=' + encodeURIComponent(api.csrfToken || '');

			return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then((response) => response.json())
				.then((data) => remember(Array.isArray(data.items) ? data.items : []))
				.catch(() => []);
		}

		/** Seed suggestions for the url picker — same caveat as `seed()`: first 30, never complete. */
		function urlSeed() {
			return Array.isArray(init.urls) ? init.urls.slice() : [];
		}

		function fetchUrls(q, profile) {
			const url = api.urlAutocompleteUrl
				+ (api.urlAutocompleteUrl.indexOf('?') < 0 ? '?' : '&')
				+ 'q=' + encodeURIComponent(q)
				+ '&profile=' + encodeURIComponent(profile || '')
				+ '&' + encodeURIComponent(api.csrfField || '_csrf_token') + '=' + encodeURIComponent(api.urlCsrfToken || '');

			return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then((response) => response.json())
				.then((data) => (Array.isArray(data.items) ? data.items : []))
				.catch(() => []);
		}

		// The linkmap popup (structure/pages/linkmap.php) triggers `rex:selectLink` — but on the
		// *popup's* window (`opener.jQuery(window)` inside the popup), never on the opener. So the
		// handler must be bound, with our jQuery, to the window object `openLinkMap()` returns —
		// binding to our own window looks plausible and silently never fires. Core's
		// `rex_retain_popup_event_handlers()` re-binds these handlers across in-popup navigation
		// (mform and tinymce use the same pattern). No preventDefault: core's own write into the
		// hidden widget + self.close() is exactly what should happen, this only reads the args.
		let pendingLinkmap = null;

		const onSelectLink = (event, link, name) => {
			const callback = pendingLinkmap;
			const id = parseInt(String(link).replace('redaxo://', ''), 10);

			pendingLinkmap = null;

			if (callback && id > 0) {
				callback(id, name);
			}
		};

		function openLinkmap(callback) {
			if ('function' !== typeof window.openLinkMap || !window.jQuery) {
				return;
			}

			pendingLinkmap = callback;
			const popup = window.openLinkMap(linkmap.fieldId, linkmap.openParams || '');

			if (popup) {
				// A same-named popup can be reused; off() keeps the handler from stacking.
				window.jQuery(popup).off('rex:selectLink', onSelectLink).on('rex:selectLink', onSelectLink);
			}
		}

		// Same shape for the mediapool: `selectMedia()` (mediapool/assets/mediapool.js) triggers
		// `rex:selectMedia` with [filename, title] on the popup window returned by `openREXMedia()`.
		let pendingMedia = null;

		const onSelectMedia = (event, filename) => {
			const callback = pendingMedia;

			pendingMedia = null;

			if (callback && filename) {
				callback(String(filename));
			}
		};

		function openMediapool(callback) {
			if ('function' !== typeof window.openREXMedia || !window.jQuery) {
				return;
			}

			pendingMedia = callback;
			// `openREXMedia()` prefixes `REX_MEDIA_` itself, hence the bare opener id.
			const popup = window.openREXMedia(mediapool.openerId, mediapool.openParams || '');

			if (popup) {
				window.jQuery(popup).off('rex:selectMedia', onSelectMedia).on('rex:selectMedia', onSelectMedia);
			}
		}

		// ── Tree component ──────────────────────────────────────────────────────────────────

		const NavItem = {
			name: 'NavItem',
			props: {
				item: { type: Object, required: true },
				list: { type: Array, required: true },
				index: { type: Number, required: true },
				parentList: { type: Array, default: null },
				parentIndex: { type: Number, default: 0 },
				depth: { type: Number, default: 1 },
			},
			setup(props) {
				const item = props.item;
				const query = ref('');
				const results = ref(seed());
				const urlError = ref('');
				const articleError = ref('');
				const mediaError = ref('');
				const adding = ref(false);

				// Which form the user is editing in. Equals `item.type` outside an open form —
				// a conversion is only committed on apply. Meaningless for `group` items.
				const mode = ref(item.type);

				// Article combobox: closed until the field is focused, closed again on pick.
				const open = ref(false);
				const active = ref(-1);
				const listId = 'nb-lb-' + item.id;

				// Url-dataset combobox — the article combobox's twin with its own state, because
				// the two are visible in different modes but must not share half-typed queries.
				const uQuery = ref('');
				const uResults = ref(urlSeed());
				const uProfile = ref('');
				const uOpen = ref(false);
				const uActive = ref(-1);
				const uListId = 'nb-ulb-' + item.id;
				const urlItemError = ref('');
				let uTimer = 0;
				let uLastRequest = 0;

				// Link form: one field holding the bare value, kept in sync with `item.url`
				// (the only stored field) and with the live kind indicator next to it.
				const address = ref(splitLink(item.url));
				const kind = computed(() => detectKind(address.value));

				watch(address, () => {
					if ('link' === mode.value) {
						item.url = buildLink(address.value);
						urlError.value = '';

						// A target only means anything for a real url.
						if ('url' !== kind.value) {
							item.target = '_self';
						}
					}
				});

				watch(mode, (next) => {
					urlError.value = '';
					articleError.value = '';
					mediaError.value = '';
					urlItemError.value = '';

					// `address` only ever mirrors the stored url. Reseeding it on every switch keeps
					// the field showing what an apply would really save — a value typed during an
					// aborted conversion must not survive as text the watcher no longer writes.
					if ('link' === next) {
						address.value = 'link' === item.type ? splitLink(item.url) : '';
					}
				});

				let timer = 0;
				let lastRequest = 0;
				let snapshot = null;

				const broken = computed(() => (('article' === item.type && parseInt(item.articleId, 10) > 0)
					|| ('media' === item.type && '' !== String(item.file || ''))
					|| ('url' === item.type && parseInt(item.dataId, 10) > 0)) && false === item._exists);
				const offline = computed(() => 'article' === item.type && !broken.value && false === item._online);

				// An existing url item survives the addon's removal untouched — flagged, not lost.
				const urlUnavailable = computed(() => 'url' === item.type && !features.url);

				const title = computed(() => {
					const label = String(item.label || '').trim();

					if ('' !== label) {
						return label;
					}

					if ('article' === item.type) {
						return item._label || (parseInt(item.articleId, 10) > 0 ? '#' + item.articleId : t.choose_article);
					}

					if ('media' === item.type) {
						return item.file || t.choose_media;
					}

					if ('text' === item.type) {
						// A peek at the body, never its markup — the row is not a place for HTML source.
						// Tags become a space so `<p>a</p><p>b</p>` does not read as "ab"; the second
						// pass takes that space back off punctuation it landed in front of.
						const plain = String(item.text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').replace(/\s+([,.;:!?])/g, '$1').trim();

						return plain ? (plain.length > 40 ? plain.slice(0, 40) + '…' : plain) : '—';
					}

					if ('url' === item.type) {
						return item._label || (parseInt(item.dataId, 10) > 0 ? '#' + item.dataId : t.choose_url);
					}

					return item.url || t[item.type] || item.type;
				});

				const hint = computed(() => ('article' === item.type || 'media' === item.type || 'url' === item.type ? item._url || '' : item.url || ''));

				// ── Per-language visibility ───────────────────────────────────────────────
				const visibleIn = (id) => (item.hiddenIn || []).indexOf(id) < 0;

				/** Always a fresh array: the cancel snapshot holds the old one by reference. */
				function toggleClang(id) {
					const hidden = (item.hiddenIn || []).filter((other) => other !== id);

					item.hiddenIn = visibleIn(id) ? hidden.concat([id]) : hidden;
				}

				const allHidden = computed(() => clangs.length > 0 && clangs.every((clang) => !visibleIn(clang.id)));

				/** Row indicator: the codes of the languages this item is hidden in. */
				const hiddenHint = computed(() => clangs
					.filter((clang) => !visibleIn(clang.id))
					.map((clang) => clang.code)
					.join(', '));

				// ── Depth limit ───────────────────────────────────────────────────────────
				/** Deepest level this item's subtree would occupy when it sits at `at`. */
				const fits = (at) => at + height(item) - 1 <= limit();

				// Indent pushes the whole subtree one level down, under the previous sibling.
				const tooDeep = computed(() => !fits(props.depth + 1));

				// Leaving a text item that has a body: the body is the one thing a conversion cannot
				// carry over, so say so while the other mode is selected. Reads `item.text` rather
				// than the open-form snapshot on purpose — that is the value apply would drop.
				const textDiscarded = computed(() => 'text' === item.type && 'text' !== mode.value && '' !== String(item.text || '').trim());

				const kindIcon = computed(() => ({ url: 'fa-link', email: 'fa-envelope-o', tel: 'fa-phone' }[kind.value]));

				const dropZone = computed(() => (dnd.over === item.id ? dnd.pos : ''));

				// ── Keyboard path ─────────────────────────────────────────────────────────
				function moveUp() {
					if (props.index > 0) {
						props.list.splice(props.index - 1, 0, props.list.splice(props.index, 1)[0]);
					}
				}

				function moveDown() {
					if (props.index < props.list.length - 1) {
						props.list.splice(props.index + 1, 0, props.list.splice(props.index, 1)[0]);
					}
				}

				function indent() {
					if (props.index > 0 && !tooDeep.value) {
						const previous = props.list[props.index - 1];

						previous.children = previous.children || [];
						previous.children.push(props.list.splice(props.index, 1)[0]);
					}
				}

				function outdent() {
					if (props.parentList) {
						props.parentList.splice(props.parentIndex + 1, 0, props.list.splice(props.index, 1)[0]);
					}
				}

				// ── Item actions ──────────────────────────────────────────────────────────
				function removeSelf() {
					const at = props.list.indexOf(item);

					if (at >= 0) {
						props.list.splice(at, 1);
					}

					openForms.delete(item.id);
				}

				// A row deleted (or unmounted for any reason) must not leave a stale apply behind.
				onBeforeUnmount(() => openForms.delete(item.id));

				// A freshly added item mounts with its form already open (makeItem sets `_edit`),
				// so it registers here instead of via toggleEdit.
				if (item._edit) {
					openForms.set(item.id, () => close(false));
				}

				function remove() {
					if (window.confirm(t.confirm_remove)) {
						removeSelf();
					}
				}

				function duplicate() {
					props.list.splice(props.index + 1, 0, cloneItem(item));
				}

				/** Same flow as the toolbar's add buttons, just inserted after this item instead of appended. */
				function addBelow(type) {
					adding.value = false;
					props.list.splice(props.index + 1, 0, makeItem(type));
				}

				function toggleEdit() {
					if (item._edit) {
						close(false);

						return;
					}

					// A cancelled edit reverted `url` behind the form's back — re-read it. The
					// checkbox is hidden for email/phone, so drop a `_blank` it could not show.
					mode.value = item.type;

					if ('link' === item.type) {
						address.value = splitLink(item.url);

						if ('url' !== kind.value) {
							item.target = '_self';
						}
					}

					snapshot = {};
					EDITABLE.forEach((key) => {
						snapshot[key] = key in item ? item[key] : null;
					});
					urlError.value = '';
					articleError.value = '';
					mediaError.value = '';
					urlItemError.value = '';
					item._edit = true;
					openForms.set(item.id, () => close(false));
				}

				/** @returns {boolean} false when validation kept the form open */
				function close(revert) {
					// UX-only pre-check ahead of the server's strict rejection — keep the form open so
					// the user can fix the url instead of losing it to a full-page error.
					if (!revert && 'link' === mode.value) {
						const url = String(item.url || '').trim();

						if ('' !== url && !isSafeUrl(url)) {
							urlError.value = t.url_scheme;

							return false;
						}
					}

					// Converting without filling the new type's target would drop the item (and its
					// children) via the incomplete-item path below — block the apply instead. A new
					// item the user never filled in still self-deletes, as it always did.
					if (!revert && mode.value !== item.type) {
						if ('article' === mode.value && !(parseInt(item.articleId, 10) > 0)) {
							articleError.value = t.article_required;

							return false;
						}

						if ('link' === mode.value && '' === String(address.value).trim()) {
							urlError.value = t.url_required;

							return false;
						}

						if ('media' === mode.value && '' === String(item.file || '').trim()) {
							mediaError.value = t.media_required;

							return false;
						}

						if ('url' === mode.value && !(parseInt(item.dataId, 10) > 0)) {
							urlItemError.value = t.url_item_required;

							return false;
						}
					}

					if (revert && snapshot) {
						Object.assign(item, snapshot);
						mode.value = item.type;
						address.value = splitLink(item.url);
					} else if (mode.value !== item.type) {
						// Convert in place: `id`, `label`, `hiddenIn` and `children` stay, every other
						// type's fields go. `clean()` would drop them anyway; the item should not
						// carry them either. The guards above already made sure the new type has a target.
						item.type = mode.value;

						if ('article' !== mode.value) {
							item.articleId = null;
							item.clang = null;
							item._online = null;
						}

						if ('article' !== mode.value && 'url' !== mode.value) {
							item._label = null;
						}

						if ('link' !== mode.value) {
							item.url = null;
						}

						// Both link and media open a real URL — a target survives converting between them.
						if ('link' !== mode.value && 'media' !== mode.value && 'url' !== mode.value) {
							item.target = null;
						}

						if ('media' !== mode.value) {
							item.file = null;
						}

						// The body cannot travel to any other type — the inline hint said so.
						if ('text' !== mode.value) {
							item.text = null;
						}

						if ('url' !== mode.value) {
							item.profileId = null;
							item.dataId = null;
							item._profile = null;
						}

						// `_exists`/`_url` are shared by the types that resolve server-side.
						if ('article' !== mode.value && 'media' !== mode.value && 'url' !== mode.value) {
							item._exists = null;
							item._url = null;
						}
					}

					urlError.value = '';
					articleError.value = '';
					mediaError.value = '';
					urlItemError.value = '';
					snapshot = null;
					item._edit = false;
					openForms.delete(item.id);

					// A picker the user never filled in would only be dropped on save anyway.
					if (isIncomplete(item)) {
						removeSelf();
					}

					return true;
				}

				// ── Article combobox ──────────────────────────────────────────────────────
				function closeList() {
					open.value = false;
					active.value = -1;
					// A debounced search still in flight would repopulate a closed list.
					window.clearTimeout(timer);
				}

				/** ArrowUp/Down; opening on the first key press is what a combobox is expected to do. */
				function move(step) {
					if (!open.value) {
						open.value = true;

						return;
					}

					const count = results.value.length;

					active.value = count ? (active.value + step + count) % count : -1;
				}

				function pickActive() {
					// Enter without a highlight takes the top hit — but only once the user has typed.
					// On the untouched suggestion list that would be an accidental pick.
					const at = active.value < 0 && '' !== query.value.trim() ? 0 : active.value;

					if (open.value && results.value[at]) {
						choose(results.value[at]);
					}
				}

				function onQuery() {
					open.value = true;
					active.value = -1;
					window.clearTimeout(timer);
					timer = window.setTimeout(() => {
						// Clearing the timer cannot cancel an in-flight fetch — only the latest
						// request may write, or a slow early response overtakes a newer one.
						const request = ++lastRequest;

						fetchArticles(query.value).then((found) => {
							if (request === lastRequest) {
								results.value = found;
								active.value = -1;
							}
						});
					}, SEARCH_DELAY);
				}

				function choose(article) {
					item.articleId = article.id;
					item._exists = true;
					item._label = article.name;
					item._online = article.online;
					item._url = '';
					articleError.value = '';
					// Back to the suggestion state, so re-focusing does not reopen a stale search.
					query.value = '';
					results.value = seed();
					closeList();
				}

				function uCloseList() {
					uOpen.value = false;
					uActive.value = -1;
					window.clearTimeout(uTimer);
				}

				function uMove(step) {
					if (!uOpen.value) {
						uOpen.value = true;

						return;
					}

					const count = uResults.value.length;

					uActive.value = count ? (uActive.value + step + count) % count : -1;
				}

				function uPickActive() {
					const at = uActive.value < 0 && '' !== uQuery.value.trim() ? 0 : uActive.value;

					if (uOpen.value && uResults.value[at]) {
						chooseUrl(uResults.value[at]);
					}
				}

				function uSearch() {
					window.clearTimeout(uTimer);
					uTimer = window.setTimeout(() => {
						const request = ++uLastRequest;

						fetchUrls(uQuery.value, uProfile.value).then((found) => {
							if (request === uLastRequest) {
								uResults.value = found;
								uActive.value = -1;
							}
						});
					}, SEARCH_DELAY);
				}

				function uOnQuery() {
					uOpen.value = true;
					uActive.value = -1;
					uSearch();
				}

				function chooseUrl(entry) {
					item.profileId = entry.profileId;
					item.dataId = entry.dataId;
					item._exists = true;
					item._label = entry.name;
					item._url = entry.url;
					item._profile = entry.profile;
					urlItemError.value = '';
					uQuery.value = '';
					uResults.value = urlSeed();
					uCloseList();
				}

				// A changed filter re-runs the search immediately — with the list open, an
				// unchanged result set would look like the filter did nothing.
				watch(uProfile, () => {
					uResults.value = [];
					uSearch();
				});

				function pickViaLinkmap() {
					closeList();
					openLinkmap((id, name) => {
						// The linkmap hands over name + id only. `online` comes from the article cache when
						// the picker has seen the article; unknown ones stay optimistic until the next save
						// re-enriches them server-side.
						const cached = known.get(id);

						choose({
							id: id,
							name: cached ? cached.name : String(name).replace(/\s*\[\d+\]\s*$/, ''),
							online: cached ? cached.online : true,
						});
					});
				}

				function pickViaMediapool() {
					openMediapool((filename) => {
						item.file = filename;
						// The popup hands over the file name only. `_exists` is optimistic until the
						// next save re-enriches it server-side — same deal as the linkmap pick.
						item._exists = true;
						item._url = '';
						mediaError.value = '';
					});
				}

				// ── Drag & drop ───────────────────────────────────────────────────────────
				function onDragStart(event) {
					dnd.item = item;
					dnd.list = props.list;
					event.dataTransfer.effectAllowed = 'move';
					event.dataTransfer.setData('text/plain', item.id);
				}

				function onDragOver(event) {
					// Illegal target (itself or one of its descendants): no drop, and no indicator left
					// painted from the row the pointer came from.
					if (!dnd.item || dnd.item === item || contains(dnd.item, item)) {
						resetOver();

						return;
					}

					const box = event.currentTarget.getBoundingClientRect();
					const ratio = (event.clientY - box.top) / (box.height || 1);
					const pos = ratio < 0.3 ? 'before' : (ratio > 0.7 ? 'after' : 'inside');
					// Where the dragged subtree would start, and how deep it reaches from there.
					const at = 'inside' === pos ? props.depth + 1 : props.depth;

					// Same treatment as an illegal target: no indicator, no drop, no stale highlight.
					if (at + height(dnd.item) - 1 > limit()) {
						resetOver();

						return;
					}

					event.preventDefault();
					event.stopPropagation();
					event.dataTransfer.dropEffect = 'move';
					dnd.over = item.id;
					dnd.pos = pos;
				}

				function onDrop(event) {
					if (!dropZone.value) {
						return;
					}

					event.preventDefault();
					event.stopPropagation();

					const source = dnd.item;
					const from = dnd.list;
					const at = from.indexOf(source);

					if (at < 0) {
						resetDnd();

						return;
					}

					from.splice(at, 1);

					if ('inside' === dnd.pos) {
						item.children = item.children || [];
						item.children.push(source);
					} else {
						props.list.splice(props.list.indexOf(item) + ('after' === dnd.pos ? 1 : 0), 0, source);
					}

					resetDnd();
				}

				return {
					t: t, dnd: dnd, query: query, results: results, urlError: urlError, articleError: articleError, mode: mode,
					mediaError: mediaError, textClass: init.textClass || '', textDiscarded: textDiscarded,
					adding: adding, kind: kind, kindIcon: kindIcon, address: address,
					open: open, active: active, listId: listId,
					clangs: clangs, visibleIn: visibleIn, toggleClang: toggleClang, allHidden: allHidden, hiddenHint: hiddenHint,
					tooDeep: tooDeep,
					closeList: closeList, move: move, pickActive: pickActive,
					broken: broken, offline: offline, title: title, hint: hint, dropZone: dropZone,
					moveUp: moveUp, moveDown: moveDown, indent: indent, outdent: outdent,
					remove: remove, duplicate: duplicate, addBelow: addBelow, toggleEdit: toggleEdit, close: close,
					onQuery: onQuery, choose: choose, pickViaLinkmap: pickViaLinkmap, pickViaMediapool: pickViaMediapool,
					onDragStart: onDragStart, onDragOver: onDragOver, onDrop: onDrop, onDragEnd: resetDnd,
					features: features, urlProfiles: urlProfiles, urlItemError: urlItemError, urlUnavailable: urlUnavailable,
					uQuery: uQuery, uResults: uResults, uProfile: uProfile, uOpen: uOpen, uActive: uActive, uListId: uListId,
					uCloseList: uCloseList, uMove: uMove, uPickActive: uPickActive, uOnQuery: uOnQuery, chooseUrl: chooseUrl,
				};
			},
			template: `
	<li class="nb-item" :class="{ 'nb-item-dragging': dnd.item === item }">
		<div class="nb-row" :class="[ 'nb-type-' + item.type, dropZone ? 'nb-drop-' + dropZone : '' ]"
			draggable="true"
			@dragstart="onDragStart" @dragover="onDragOver" @drop="onDrop" @dragend="onDragEnd">
			<span class="nb-grip rex-icon fa-arrows" aria-hidden="true"></span>
			<span class="nb-kind">{{ t[item.type] }}</span>
			<span class="nb-title" :class="{ 'nb-title-broken': broken }">{{ title }}</span>
			<span v-if="broken" class="nb-badge nb-badge-danger">{{ t.deleted }}</span>
			<span v-else-if="urlUnavailable" class="nb-badge nb-badge-warning">{{ t.url_addon_missing }}</span>
			<span v-else-if="offline" class="nb-badge nb-badge-warning">{{ t.offline }}</span>
			<span v-if="hiddenHint" class="nb-hidden" :title="t.hidden_in"><i class="rex-icon fa-eye-slash" aria-hidden="true"></i> {{ hiddenHint }}</span>
			<code v-if="hint" class="nb-hint">{{ hint }}</code>
			<span class="nb-actions">
				<button type="button" class="btn btn-xs btn-default" :aria-label="t.move_up" :title="t.move_up" :disabled="index === 0" @click="moveUp"><i class="rex-icon fa-arrow-up" aria-hidden="true"></i></button>
				<button type="button" class="btn btn-xs btn-default" :aria-label="t.move_down" :title="t.move_down" :disabled="index === list.length - 1" @click="moveDown"><i class="rex-icon fa-arrow-down" aria-hidden="true"></i></button>
				<button type="button" class="btn btn-xs btn-default" :aria-label="t.move_in" :title="t.move_in" :disabled="index === 0 || tooDeep" @click="indent"><i class="rex-icon fa-arrow-right" aria-hidden="true"></i></button>
				<button type="button" class="btn btn-xs btn-default" :aria-label="t.move_out" :title="t.move_out" :disabled="!parentList" @click="outdent"><i class="rex-icon fa-arrow-left" aria-hidden="true"></i></button>
				<span class="nb-sep" aria-hidden="true"></span>
				<button type="button" class="btn btn-xs btn-default" :aria-label="t.add_below" :title="t.add_below" :aria-expanded="adding ? 'true' : 'false'" @click="adding = !adding"><i class="rex-icon fa-plus" aria-hidden="true"></i></button>
				<button type="button" class="btn btn-xs btn-default" :aria-label="t.edit" :title="t.edit" :aria-expanded="item._edit ? 'true' : 'false'" @click="toggleEdit"><i class="rex-icon fa-pencil" aria-hidden="true"></i></button>
				<button type="button" class="btn btn-xs btn-default" :aria-label="t.duplicate" :title="t.duplicate" @click="duplicate"><i class="rex-icon fa-copy" aria-hidden="true"></i></button>
				<button type="button" class="btn btn-xs btn-default nb-remove" :aria-label="t.remove" :title="t.remove" @click="remove"><i class="rex-icon rex-icon-delete" aria-hidden="true"></i></button>
			</span>
		</div>

		<div v-if="item._edit" class="nb-form">
			<!-- Type switch: all four types convert into each other in place, keeping id, label,
				visibility and children. Only the previous type's own payload is dropped. -->
			<div class="form-group">
				<label class="control-label">{{ t.item_type }}</label>
				<div class="btn-group nb-switch" role="group" :aria-label="t.item_type">
					<button type="button" class="btn btn-sm btn-default" :class="{ active: mode === 'article' }"
						:aria-pressed="mode === 'article' ? 'true' : 'false'" @click="mode = 'article'"><i class="rex-icon rex-icon-article" aria-hidden="true"></i> {{ t.article }}</button>
					<button type="button" class="btn btn-sm btn-default" :class="{ active: mode === 'link' }"
						:aria-pressed="mode === 'link' ? 'true' : 'false'" @click="mode = 'link'"><i class="rex-icon fa-link" aria-hidden="true"></i> {{ t.link }}</button>
					<button type="button" class="btn btn-sm btn-default" :class="{ active: mode === 'media' }"
						:aria-pressed="mode === 'media' ? 'true' : 'false'" @click="mode = 'media'"><i class="rex-icon rex-icon-media" aria-hidden="true"></i> {{ t.media }}</button>
					<button v-if="features.url || 'url' === item.type" type="button" class="btn btn-sm btn-default" :class="{ active: mode === 'url' }"
						:aria-pressed="mode === 'url' ? 'true' : 'false'" @click="mode = 'url'"><i class="rex-icon fa-database" aria-hidden="true"></i> {{ t.url }}</button>
					<button type="button" class="btn btn-sm btn-default" :class="{ active: mode === 'text' }"
						:aria-pressed="mode === 'text' ? 'true' : 'false'" @click="mode = 'text'"><i class="rex-icon fa-font" aria-hidden="true"></i> {{ t.text }}</button>
				</div>
				<span v-if="textDiscarded" class="help-block nb-warn">{{ t.text_discarded }}</span>
			</div>

			<template v-if="'article' === mode">
				<div class="nb-grid">
					<!-- Combobox: the listbox floats over the form, so it reads as results and not
						as more form. Blur closes it — that is the click-outside path too, which is
						why the options use @mousedown.prevent to survive it: a deliberate trade of
						text selection inside a result row for not needing a document-wide listener. -->
					<div class="form-group nb-combo" :class="{ 'has-error': articleError }">
						<label class="control-label" :for="'nb-q-' + item.id">{{ t.search }}</label>
						<div class="input-group">
							<input class="form-control" type="search" :id="'nb-q-' + item.id" v-model="query" autocomplete="off" :placeholder="t.search_placeholder"
								role="combobox" aria-autocomplete="list" :aria-controls="listId" :aria-expanded="open ? 'true' : 'false'"
								:aria-activedescendant="active >= 0 ? listId + '-' + active : null"
								@input="onQuery" @focus="open = true" @blur="closeList"
								@keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="pickActive" @keydown.esc="closeList">
							<span class="input-group-btn">
								<button class="btn btn-default" type="button" @click="pickViaLinkmap"><i class="rex-icon rex-icon-open-linkmap" aria-hidden="true"></i> {{ t.choose_article }}</button>
							</span>
						</div>
						<div v-if="open" class="nb-listbox">
							<p v-if="!query" class="nb-listbox-head">{{ t.suggestions }}</p>
							<ul class="nb-results" role="listbox" :id="listId" :aria-label="t.search">
								<li v-for="(article, i) in results" :key="article.id" :id="listId + '-' + i" role="option"
									class="nb-result" :class="{ 'nb-result-current': article.id === item.articleId, 'nb-result-active': i === active }"
									:aria-selected="article.id === item.articleId ? 'true' : 'false'"
									@mousedown.prevent="choose(article)" @mouseenter="active = i">
									<span class="nb-result-name">{{ article.name }}</span>
									<span v-if="!article.online" class="nb-badge nb-badge-warning">{{ t.offline }}</span>
									<span class="nb-result-path">{{ article.path }}</span>
								</li>
								<li v-if="!results.length" class="nb-note">{{ t.no_results }}</li>
							</ul>
						</div>
						<span v-if="articleError" class="help-block">{{ articleError }}</span>
					</div>
					<div class="form-group">
						<label class="control-label" :for="'nb-o-' + item.id">{{ t.label_override }}</label>
						<input class="form-control" type="text" :id="'nb-o-' + item.id" v-model="item.label" :placeholder="item._label">
					</div>
				</div>
			</template>

			<template v-else-if="'link' === mode">
				<!-- One field for url / email / phone. The input type stays plain text: relative
					links are valid here, and a stricter type would fail the form's constraint check. -->
				<div class="form-group" :class="{ 'has-error': urlError }">
					<label class="control-label" :for="'nb-u-' + item.id">{{ t.link_value }}</label>
					<div class="input-group">
						<input class="form-control" type="text" :id="'nb-u-' + item.id" v-model="address" :placeholder="t.link_placeholder" autocomplete="off">
						<span class="input-group-addon nb-detect" :title="t.link_kind">
							<i class="rex-icon" :class="kindIcon" aria-hidden="true"></i> {{ t['kind_' + kind] }}
						</span>
					</div>
					<span v-if="urlError" class="help-block">{{ urlError }}</span>
				</div>
				<div class="form-group">
					<label class="control-label" :for="'nb-l-' + item.id">{{ t.label }}</label>
					<input class="form-control" type="text" :id="'nb-l-' + item.id" v-model="item.label">
				</div>
				<div v-if="kind === 'url'" class="checkbox">
					<label><input type="checkbox" :checked="item.target === '_blank'" @change="item.target = $event.target.checked ? '_blank' : '_self'"> {{ t.new_window }}</label>
				</div>
			</template>

			<template v-else-if="'media' === mode">
				<!-- The file name is the whole model; the mediapool popup is the only way to set it,
					so the field is readonly instead of a second, unvalidated way in. -->
				<div class="nb-grid">
					<div class="form-group" :class="{ 'has-error': mediaError }">
						<label class="control-label" :for="'nb-f-' + item.id">{{ t.file }}</label>
						<div class="input-group">
							<input class="form-control" type="text" :id="'nb-f-' + item.id" :value="item.file" readonly>
							<span class="input-group-btn">
								<button class="btn btn-default" type="button" @click="pickViaMediapool"><i class="rex-icon rex-icon-open-mediapool" aria-hidden="true"></i> {{ t.choose_media }}</button>
							</span>
						</div>
						<span v-if="mediaError" class="help-block">{{ mediaError }}</span>
					</div>
					<div class="form-group">
						<!-- The placeholder is the file name, which is also the fallback — no need for
							the article form's "empty = article name" hint here. -->
						<label class="control-label" :for="'nb-m-' + item.id">{{ t.label }}</label>
						<input class="form-control" type="text" :id="'nb-m-' + item.id" v-model="item.label" :placeholder="item.file">
					</div>
				</div>
				<!-- Unlike the link form there is no mailto/tel case — a media url is always real. -->
				<div class="checkbox">
					<label><input type="checkbox" :checked="item.target === '_blank'" @change="item.target = $event.target.checked ? '_blank' : '_self'"> {{ t.new_window }}</label>
				</div>
			</template>

			<template v-else-if="'url' === mode">
				<p v-if="!features.url" class="nb-note">{{ t.url_addon_missing }}</p>
				<template v-else>
					<div v-if="urlProfiles.length > 1" class="form-group">
						<label class="control-label" :for="'nb-up-' + item.id">{{ t.url_profile }}</label>
						<select class="form-control" :id="'nb-up-' + item.id" v-model="uProfile">
							<option value="">{{ t.url_all_profiles }}</option>
							<option v-for="profile in urlProfiles" :key="profile.id" :value="profile.namespace">{{ profile.namespace }}</option>
						</select>
					</div>
					<div class="nb-grid">
						<!-- Same combobox trade-offs as the article picker above: blur closes, options
							survive it via @mousedown.prevent. -->
						<div class="form-group nb-combo" :class="{ 'has-error': urlItemError }">
							<label class="control-label" :for="'nb-uq-' + item.id">{{ t.search }}</label>
							<input class="form-control" type="search" :id="'nb-uq-' + item.id" v-model="uQuery" autocomplete="off" :placeholder="t.search_placeholder"
								role="combobox" aria-autocomplete="list" :aria-controls="uListId" :aria-expanded="uOpen ? 'true' : 'false'"
								:aria-activedescendant="uActive >= 0 ? uListId + '-' + uActive : null"
								@input="uOnQuery" @focus="uOpen = true" @blur="uCloseList"
								@keydown.down.prevent="uMove(1)" @keydown.up.prevent="uMove(-1)" @keydown.enter.prevent="uPickActive" @keydown.esc="uCloseList">
							<div v-if="uOpen" class="nb-listbox">
								<p v-if="!uQuery" class="nb-listbox-head">{{ t.suggestions }}</p>
								<ul class="nb-results" role="listbox" :id="uListId" :aria-label="t.search">
									<li v-for="(entry, i) in uResults" :key="entry.profileId + '-' + entry.dataId" :id="uListId + '-' + i" role="option"
										class="nb-result" :class="{ 'nb-result-current': entry.profileId === item.profileId && entry.dataId === item.dataId, 'nb-result-active': i === uActive }"
										:aria-selected="entry.profileId === item.profileId && entry.dataId === item.dataId ? 'true' : 'false'"
										@mousedown.prevent="chooseUrl(entry)" @mouseenter="uActive = i">
										<span class="nb-result-name">{{ entry.name }}</span>
										<span class="nb-badge">{{ entry.profile }}</span>
										<span class="nb-result-path">{{ entry.url }}</span>
									</li>
									<li v-if="!uResults.length" class="nb-note">{{ t.no_results }}</li>
								</ul>
							</div>
							<span v-if="urlItemError" class="help-block">{{ urlItemError }}</span>
						</div>
						<div class="form-group">
							<label class="control-label" :for="'nb-uo-' + item.id">{{ t.label_override }}</label>
							<input class="form-control" type="text" :id="'nb-uo-' + item.id" v-model="item.label" :placeholder="item._label">
						</div>
					</div>
					<div class="checkbox">
						<label><input type="checkbox" :checked="item.target === '_blank'" @change="item.target = $event.target.checked ? '_blank' : '_self'"> {{ t.new_window }}</label>
					</div>
				</template>
			</template>

			<template v-else>
				<div class="form-group">
					<label class="control-label" :for="'nb-g-' + item.id">{{ t.label }}</label>
					<input class="form-control" type="text" :id="'nb-g-' + item.id" v-model="item.label">
				</div>
				<!-- Editor-trusted HTML, like a module textarea. textClass comes from the
					NAVBUILDER_INIT extension point, so a project can attach its own editor. -->
				<div class="form-group">
					<label class="control-label" :for="'nb-t-' + item.id">{{ t.text_content }}</label>
					<textarea class="form-control navbuilder-text-input" :class="textClass" :id="'nb-t-' + item.id" rows="4" v-model="item.text"></textarea>
				</div>
			</template>

			<!-- Only worth showing when there is more than one language to hide from — plus the one
				case where an install dropped back to a single language while an item was still
				hidden in it, which would otherwise be invisible *and* unclearable. -->
			<div v-if="clangs.length > 1 || hiddenHint" class="form-group nb-clangs" :class="{ 'has-warning': allHidden }">
				<label class="control-label">{{ t.visible_in }}</label>
				<div class="nb-chips">
					<button v-for="clang in clangs" :key="clang.id" type="button"
						class="btn btn-xs nb-chip" :class="visibleIn(clang.id) ? 'btn-primary' : 'btn-default nb-chip-off'"
						:aria-pressed="visibleIn(clang.id) ? 'true' : 'false'" @click="toggleClang(clang.id)">{{ clang.name }}</button>
				</div>
				<span v-if="allHidden" class="help-block">{{ t.hidden_everywhere }}</span>
			</div>

			<div class="nb-form-actions">
				<button type="button" class="btn btn-primary btn-sm" @click="close(false)">{{ t.apply }}</button>
				<button type="button" class="btn btn-default btn-sm" @click="close(true)">{{ t.cancel }}</button>
				<code class="nb-id" :title="t.item_id">{{ item.id }}</code>
			</div>
		</div>

		<ul v-if="item.children.length" class="nb-list">
			<nav-item v-for="(child, i) in item.children" :key="child.id"
				:item="child" :list="item.children" :index="i" :parent-list="list" :parent-index="index" :depth="depth + 1"></nav-item>
		</ul>

		<!-- Below the children on purpose: that is exactly where the new sibling lands. -->
		<div v-if="adding" class="nb-addbar">
			<span class="nb-addbar-label">{{ t.add_below }}</span>
			<button type="button" class="btn btn-xs btn-default" @click="addBelow('article')"><i class="rex-icon rex-icon-article" aria-hidden="true"></i> {{ t.add_article }}</button>
			<button type="button" class="btn btn-xs btn-default" @click="addBelow('link')"><i class="rex-icon fa-link" aria-hidden="true"></i> {{ t.add_link }}</button>
			<button type="button" class="btn btn-xs btn-default" @click="addBelow('media')"><i class="rex-icon rex-icon-media" aria-hidden="true"></i> {{ t.add_media }}</button>
			<button v-if="features.url" type="button" class="btn btn-xs btn-default" @click="addBelow('url')"><i class="rex-icon fa-database" aria-hidden="true"></i> {{ t.add_url }}</button>
			<button type="button" class="btn btn-xs btn-default" @click="addBelow('text')"><i class="rex-icon fa-font" aria-hidden="true"></i> {{ t.add_text }}</button>
		</div>
	</li>`,
		};

		// ── Root ────────────────────────────────────────────────────────────────────────────

		function resetOver() {
			dnd.over = null;
			dnd.pos = '';
		}

		function resetDnd() {
			dnd.item = null;
			dnd.list = null;
			resetOver();
		}

		function add(type) {
			items.push(makeItem(type));
		}

		function onRootDragOver(event) {
			if (dnd.item) {
				event.preventDefault();
				dnd.over = 'root';
				dnd.pos = 'end';
			}
		}

		function onRootDrop(event) {
			const source = dnd.item;
			const at = source ? dnd.list.indexOf(source) : -1;

			if (at >= 0) {
				event.preventDefault();
				dnd.list.splice(at, 1);
				items.push(source);
			}

			resetDnd();
		}

		const app = createApp({
			setup() {
				return {
					t: t, items: items, dnd: dnd,
					settings: settings, isAdmin: !!init.isAdmin, maxDepthLimit: HARD_DEPTH, features: features,
					add: add, onRootDragOver: onRootDragOver, onRootDrop: onRootDrop, onDragEnd: resetDnd,
				};
			},
			template: `
	<div class="nb" @dragend="onDragEnd">
		<!-- Admins only: a per-navigation nesting cap. Non-admins never see it, but the value
			still round-trips through serialize(), so editing cannot silently drop it. -->
		<div v-if="isAdmin" class="form-group nb-maxdepth">
			<label class="control-label" for="nb-maxdepth">{{ t.max_depth }}</label>
			<input class="form-control" type="number" id="nb-maxdepth" min="1" :max="maxDepthLimit"
				:placeholder="t.max_depth_hint" v-model="settings.maxDepth">
		</div>

		<div class="nb-toolbar btn-group">
			<button type="button" class="btn btn-default" @click="add('article')"><i class="rex-icon rex-icon-article" aria-hidden="true"></i> {{ t.add_article }}</button>
			<button type="button" class="btn btn-default" @click="add('link')"><i class="rex-icon fa-link" aria-hidden="true"></i> {{ t.add_link }}</button>
			<button type="button" class="btn btn-default" @click="add('media')"><i class="rex-icon rex-icon-media" aria-hidden="true"></i> {{ t.add_media }}</button>
			<button v-if="features.url" type="button" class="btn btn-default" @click="add('url')"><i class="rex-icon fa-database" aria-hidden="true"></i> {{ t.add_url }}</button>
			<button type="button" class="btn btn-default" @click="add('text')"><i class="rex-icon fa-font" aria-hidden="true"></i> {{ t.add_text }}</button>
		</div>

		<p v-if="!items.length" class="nb-note">{{ t.empty }}</p>

		<ul v-else class="nb-list nb-list-root">
			<nav-item v-for="(item, i) in items" :key="item.id" :item="item" :list="items" :index="i"></nav-item>
		</ul>

		<div class="nb-dropzone" :class="{ 'nb-dropzone-active': dnd.over === 'root' }" v-show="dnd.item"
			@dragover="onRootDragOver" @drop="onRootDrop"></div>
	</div>`,
		});

		app.component('nav-item', NavItem);
		app.mount(mount);

		nextTick(serialize);
	}
}());
