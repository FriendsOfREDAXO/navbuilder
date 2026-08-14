<?php

declare(strict_types=1);

/** @var rex_addon $this */

// Schema first (idempotent), then the JSON migration. `migrateAll()` also re-encodes v2 rows that
// still carry the renamed `group` type, so re-running this script on the same version is a valid
// (and cheap) way to normalize existing data — `bin/console package:run-update-script navbuilder`.
$this->includeFile(__DIR__ . '/install.php');

// During install/update the addon classes are not necessarily in the autoload map yet.
require_once __DIR__ . '/lib/Navigation.php';

$result = FriendsOfRedaxo\NavBuilder\Navigation::migrateAll();

if ($result['migrated'] > 0) {
	$this->setProperty('successmsg', rex_i18n::rawMsg('navbuilder_msg_migrated', (string) $result['migrated']));
}
