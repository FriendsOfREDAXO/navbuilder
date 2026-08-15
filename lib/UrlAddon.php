<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\NavBuilder;

use rex;
use rex_addon;
use rex_clang;
use rex_logger;
use rex_sql;

/**
 * Read-side bridge to the `url` addon (FriendsOfRedaxo/url) — an optional peer, never a
 * requirement. All lookups go straight to the generator tables, which are the addon's
 * de-facto read API; every write stays the url addon's business.
 *
 * `url` navigation items store (profileId, dataId) only. Generator row ids are rewritten on
 * every regeneration and the URL string changes with slugs, so neither is a stable reference —
 * this class resolves the pair to a URL and a name at render time.
 *
 * `rex_url_generator_url` also carries `is_user_path`/`is_structure` rows appended for a
 * dataset's sub-paths (e.g. a gallery page under a detail page); every query here excludes
 * them so only the canonical, one-per-(profile,data,clang) dataset URL resolves or appears.
 */
final class UrlAddon
{
	public const LIMIT = 30;

	/** The "addon missing" skip happens per item — the log line must not. */
	private static bool $warned = false;

	/** @var array<int, string>|null lazy profile-id → namespace map for enrich() badges */
	private static ?array $namespaces = null;

	public static function available(): bool
	{
		return rex_addon::get('url')->isAvailable();
	}

	/**
	 * Resolves one generated URL — null when the addon is missing or no row matches (the
	 * profile does not generate this clang, or the dataset is gone).
	 *
	 * @return array{path: string, absolute: string, label: string}|null
	 */
	public static function find(int $profileId, int $dataId, int $clang): ?array
	{
		if ($profileId <= 0 || $dataId <= 0 || !self::guard()) {
			return null;
		}

		$rows = rex_sql::factory()->getArray(
			'SELECT `url`, `seo` FROM ' . rex::getTable('url_generator_url')
			. ' WHERE `profile_id` = :profile AND `data_id` = :data AND `clang_id` = :clang'
			. ' AND `is_user_path` = 0 AND `is_structure` = 0 LIMIT 1',
			['profile' => $profileId, 'data' => $dataId, 'clang' => $clang],
		);

		return [] !== $rows ? self::shape($rows[0]) : null;
	}

	/**
	 * Autocomplete search across every generated URL of one clang, optionally narrowed to a
	 * profile namespace. Matches the URL and the raw `seo` JSON (title *and* description —
	 * good enough for a picker, and it spares a per-row JSON_EXTRACT).
	 *
	 * @return list<array{profileId: int, dataId: int, name: string, profile: string, url: string}>
	 */
	public static function search(string $q, ?int $clang = null, string $profile = '', int $limit = self::LIMIT): array
	{
		if (!self::available()) {
			return [];
		}

		$clang ??= rex_clang::getCurrentId();
		$q = trim($q);

		$where = 'u.`clang_id` = :clang AND u.`is_user_path` = 0 AND u.`is_structure` = 0';
		$params = ['clang' => $clang];

		if ('' !== $profile) {
			$where .= ' AND p.`namespace` = :profile';
			$params['profile'] = $profile;
		}

		if ('' !== $q) {
			$where .= ' AND (u.`url` LIKE :q OR u.`seo` LIKE :q)';
			$params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
		}

		$rows = rex_sql::factory()->getArray(
			'SELECT u.`profile_id`, u.`data_id`, u.`url`, u.`seo`, p.`namespace` FROM ' . rex::getTable('url_generator_url') . ' u'
			. ' JOIN ' . rex::getTable('url_generator_profile') . ' p ON p.`id` = u.`profile_id`'
			. ' WHERE ' . $where . ' ORDER BY u.`url` ASC LIMIT ' . max(1, min(200, $limit)),
			$params,
		);

		$items = [];

		foreach ($rows as $row) {
			$shaped = self::shape($row);

			$items[] = [
				'profileId' => (int) $row['profile_id'],
				'dataId' => (int) $row['data_id'],
				'name' => '' !== $shaped['label'] ? $shaped['label'] : $shaped['path'],
				'profile' => (string) $row['namespace'],
				'url' => $shaped['path'],
			];
		}

		return $items;
	}

	/**
	 * @return list<array{id: int, namespace: string}>
	 */
	public static function profiles(): array
	{
		if (!self::available()) {
			return [];
		}

		return array_map(
			static fn (array $row): array => ['id' => (int) $row['id'], 'namespace' => (string) $row['namespace']],
			rex_sql::factory()->getArray(
				'SELECT `id`, `namespace` FROM ' . rex::getTable('url_generator_profile') . ' ORDER BY `namespace` ASC',
			),
		);
	}

	/** The badge shown next to a picked dataset — resolvable even when the dataset itself is gone. */
	public static function namespaceOf(int $profileId): string
	{
		self::$namespaces ??= array_column(self::profiles(), 'namespace', 'id');

		return self::$namespaces[$profileId] ?? '';
	}

	/** available(), but the frontend render path logs the miss once instead of going dark. */
	private static function guard(): bool
	{
		if (self::available()) {
			return true;
		}

		if (!self::$warned) {
			self::$warned = true;
			rex_logger::factory()->warning('navbuilder: url items skipped — the url addon is not available');
		}

		return false;
	}

	/**
	 * The generator stores absolute URLs; a setup storing paths gets the configured server as
	 * its only absolute base (same rule as Renderer::mediaUrl()). Handling both shapes here
	 * means no caller cares which one the installed url-addon version writes.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @return array{path: string, absolute: string, label: string}
	 */
	private static function shape(array $row): array
	{
		$stored = (string) ($row['url'] ?? '');
		$parts = parse_url($stored);
		$parts = false !== $parts ? $parts : [];
		$path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
		$seo = json_decode((string) ($row['seo'] ?? ''), true);

		return [
			'path' => $path,
			'absolute' => isset($parts['host']) ? $stored : rtrim(rex::getServer(), '/') . $path,
			'label' => is_array($seo) ? trim((string) ($seo['title'] ?? '')) : '',
		];
	}
}
