<?php

declare(strict_types=1);

use FriendsOfRedaxo\NavBuilder\Navigation;

/** @var rex_addon $this */

// During install/update the addon classes are not necessarily in the autoload map yet.
require_once __DIR__ . '/lib/Navigation.php';

// ── Name preflight, before install.php touches the schema ───────────────────────────────────
// v1 accepted arbitrary varchar(255) names; v2 narrows the column to 191 chars and only saves
// slugs. A too-long name would make the ALTER fail (or truncate) mid-update — abort cleanly
// instead. Non-slug names keep working via render('name'), but their `REX_NAVBUILDER[…]`
// snippet can no longer match; that is a warning, not a blocker.
$names = array_column(
	rex_sql::factory()->getArray('SELECT `name` FROM ' . rex::getTable('navbuilder_navigation') . ' WHERE `name` IS NOT NULL'),
	'name',
);

$tooLong = array_filter($names, static fn (string $name): bool => mb_strlen($name) > 191);

if ([] !== $tooLong) {
	throw new rex_functional_exception(rex_i18n::rawMsg('navbuilder_error_update_names_too_long', implode('", "', $tooLong)));
}

$nonSlug = array_filter($names, static fn (string $name): bool => 1 !== preg_match('/^[a-z0-9_-]+$/', $name));

if ([] !== $nonSlug) {
	rex_logger::factory()->warning(
		'navbuilder: navigation names are not slugs and cannot be addressed via REX_NAVBUILDER[name=…] (rename them in the backend): "'
		. implode('", "', $nonSlug) . '"',
	);
}

// ── Schema (idempotent), then the JSON migration ─────────────────────────────────────────────
// `migrateAll()` also re-encodes v2 rows that still carry the renamed `group` type, so re-running
// this script on the same version is a valid (and cheap) way to normalize existing data —
// `bin/console package:run-update-script navbuilder`.
$this->includeFile(__DIR__ . '/install.php');

$result = Navigation::migrateAll();

$messages = [];

if ($result['migrated'] > 0) {
	$messages[] = rex_i18n::rawMsg('navbuilder_msg_migrated', (string) $result['migrated']);
}

if ($result['dropped'] > 0) {
	$messages[] = rex_i18n::rawMsg('navbuilder_msg_migration_dropped', (string) $result['dropped']);
}

if ([] !== $messages) {
	$this->setProperty('successmsg', implode(' ', $messages));
}
