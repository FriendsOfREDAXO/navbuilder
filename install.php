<?php

declare(strict_types=1);

/** @var rex_addon $this */

$table = rex::getTable('navbuilder_navigation');

rex_sql_table::get($table)
	->ensurePrimaryIdColumn()
	->ensureColumn(new rex_sql_column('name', 'varchar(191)', true))
	// MEDIUMTEXT: a legal tree (1000 items, or one long text body) overflows TEXT's 64KB, and a
	// truncated JSON column decodes as an empty navigation. save() enforces Navigation::MAX_BYTES.
	->ensureColumn(new rex_sql_column('structure', 'mediumtext', true))
	->ensureColumn(new rex_sql_column('structure_legacy', 'mediumtext', true))
	->ensureColumn(new rex_sql_column('updated_at', 'datetime', true))
	->ensure();

// UNIQUE(name) only when the existing data allows it — a legacy install with duplicate
// names must not make the (un)install hard-fail. Navigation::save() guards names anyway.
$duplicates = rex_sql::factory()->getArray(
	'SELECT `name` FROM ' . $table . ' WHERE `name` IS NOT NULL GROUP BY `name` HAVING COUNT(*) > 1',
);

if ([] === $duplicates) {
	rex_sql_table::get($table)
		->ensureIndex(new rex_sql_index('name', ['name'], rex_sql_index::UNIQUE))
		->ensure();
} else {
	rex_logger::factory()->warning('navbuilder: skipped UNIQUE(name) index, duplicate navigation names exist: {names}', ['names' => implode(', ', array_column($duplicates, 'name'))]);
}
