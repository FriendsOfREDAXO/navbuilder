<?php

declare(strict_types=1);

/**
 * Minimal REDAXO stubs — just enough for Navigation's pure schema surface
 * (decode/normalizeItems/encode/depthOf/decodeMaxDepth). Anything touching the database,
 * articles or media is exercised in a real REDAXO instance instead, not here.
 */
class rex_functional_exception extends Exception
{
}

class rex_i18n
{
	public static function msg(string $key, string ...$replacements): string
	{
		return $key . ([] === $replacements ? '' : ':' . implode(',', $replacements));
	}

	public static function rawMsg(string $key, string ...$replacements): string
	{
		return self::msg($key, ...$replacements);
	}
}

class rex_logger
{
	/** @var list<string> */
	public static array $messages = [];

	public static function factory(): self
	{
		return new self();
	}

	/**
	 * @param array<string, string> $context
	 */
	public function warning(string $message, array $context = []): void
	{
		self::$messages[] = strtr($message, array_combine(
			array_map(static fn (string $key): string => '{' . $key . '}', array_keys($context)),
			$context,
		));
	}
}

class rex_clang
{
	/** @var list<int> */
	public static array $ids = [1, 2];

	public static function exists(int $id): bool
	{
		return in_array($id, self::$ids, true);
	}

	public static function getCurrentId(): int
	{
		return 1;
	}
}

require __DIR__ . '/../../lib/Navigation.php';
