<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\NavBuilder;

use rex;
use rex_api_function;
use rex_api_result;
use rex_category;
use rex_clang;
use rex_csrf_token;
use rex_request;
use rex_response;
use rex_sql;

/**
 * Article autocomplete for the backend app.
 *
 *     GET index.php?rex-api-call=navbuilder_articles&q=…&clang=…&_csrf_token=…
 *     → {"items":[{"id":12,"name":"Kontakt","path":"Home / Service","online":true}]}
 *
 * The endpoint answers JSON in every case, including the failure cases — the core CSRF hook
 * ({@see rex_api_function::requiresCsrfProtection()}) would render an HTML backend page on a
 * bad token, which an XHR client cannot use. The token id stays the class name so it matches
 * the core convention.
 */
final class Api extends rex_api_function
{
	/** Backend only — the endpoint exposes article names including offline ones. */
	protected $published = false;

	private const LIMIT = 30;

	public static function tokenId(): string
	{
		return self::class;
	}

	public function execute(): rex_api_result
	{
		// CSRF is not authorization — the endpoint exposes article names (incl. offline ones),
		// so it requires the same permission as the backend page it serves.
		if (!rex::isBackend() || true !== rex::getUser()?->hasPerm('navbuilder[]')) {
			self::sendJson(['error' => 'access_denied'], rex_response::HTTP_FORBIDDEN);
		}

		if (!rex_csrf_token::factory(self::tokenId())->isValid()) {
			self::sendJson(['error' => 'csrf_invalid'], rex_response::HTTP_FORBIDDEN);
		}

		self::sendJson([
			'items' => self::searchArticles(
				(string) rex_request('q', 'string', ''),
				rex_request('clang', 'int', rex_clang::getCurrentId()),
			),
		]);
	}

	/**
	 * @return list<array{id: int, name: string, path: string, online: bool}>
	 */
	public static function searchArticles(string $q, ?int $clang = null, int $limit = self::LIMIT): array
	{
		$clang ??= rex_clang::getCurrentId();
		$q = trim($q);

		$where = '`clang_id` = :clang';
		$params = ['clang' => $clang];

		if ('' !== $q) {
			$where .= ' AND `name` LIKE :q';
			$params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
		}

		$rows = rex_sql::factory()->getArray(
			'SELECT `id`, `name`, `parent_id`, `status` FROM ' . rex::getTable('article')
			. ' WHERE ' . $where . ' ORDER BY `name` ASC LIMIT ' . max(1, min(200, $limit)),
			$params,
		);

		$articles = [];

		foreach ($rows as $row) {
			$articles[] = [
				'id' => (int) $row['id'],
				'name' => (string) $row['name'],
				'path' => self::path((int) $row['parent_id'], $clang),
				'online' => 1 === (int) $row['status'],
			];
		}

		return $articles;
	}

	private static function path(int $categoryId, int $clang): string
	{
		$names = [];

		for ($category = rex_category::get($categoryId, $clang); null !== $category; $category = $category->getParent()) {
			array_unshift($names, $category->getName());
		}

		return implode(' / ', $names);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function sendJson(array $data, string $status = rex_response::HTTP_OK): never
	{
		rex_response::setStatus($status);
		rex_response::sendJson($data);

		exit;
	}
}
