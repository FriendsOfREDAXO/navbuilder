<?php

rex_sql_table::get(rex::getTable('navbuilder_navigation'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('name', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('structure', 'text', true))
    ->ensure();
