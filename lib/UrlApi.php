<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\NavBuilder;

use rex;
use rex_api_function;
use rex_api_result;
use rex_clang;
use rex_csrf_token;
use rex_request;
use rex_response;

/**
 * Dataset-URL autocomplete for the backend app — the url-addon sibling of {@see Api}.
 *
 *     GET index.php?rex-api-call=navbuilder_urls&q=…&clang=…&profile=…&_csrf_token=…
 *     → {"items":[{"profileId":3,"dataId":17,"name":"Pizzeria Toni","profile":"restaurant","url":"/restaurants/pizzeria-toni/"}]}
 *
 * Same contract as {@see Api}: JSON in every case including the failure cases, token id is
 * the class name. `profile` filters by profile namespace; empty means all profiles.
 */
final class UrlApi extends rex_api_function
{
	/** Backend only — the endpoint exposes every generated URL, unpublished datasets included. */
	protected $published = false;

	public static function tokenId(): string
	{
		return self::class;
	}

	public function execute(): rex_api_result
	{
		// CSRF is not authorization — same permission as the backend page it serves.
		if (!rex::isBackend() || true !== rex::getUser()?->hasPerm('navbuilder[]')) {
			self::sendJson(['error' => 'access_denied'], rex_response::HTTP_FORBIDDEN);
		}

		if (!rex_csrf_token::factory(self::tokenId())->isValid()) {
			self::sendJson(['error' => 'csrf_invalid'], rex_response::HTTP_FORBIDDEN);
		}

		self::sendJson([
			'items' => UrlAddon::search(
				(string) rex_request('q', 'string', ''),
				rex_request('clang', 'int', rex_clang::getCurrentId()),
				(string) rex_request('profile', 'string', ''),
			),
		]);
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
