<?php

declare(strict_types=1);

/** @var rex_addon $this */

echo rex_view::title($this->i18n('navbuilder_title'));

rex_be_controller::includeCurrentPageSubPath();
