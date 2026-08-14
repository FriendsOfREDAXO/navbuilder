<?php

declare(strict_types=1);

/** @var rex_addon $this */

rex_sql_table::get(rex::getTable('navbuilder_navigation'))->drop();
