<?php

if (rex::isBackend() && rex::getUser()) {
    rex_view::addJSFile($this->getAssetsUrl('jquery-menu-editor.js'));
    rex_view::addJSFile($this->getAssetsUrl('navbuilder.js'));
}

rex_yform_manager_dataset::setModelClass('rex_navbuilder_navigation', rex_navbuilder_navigation::class);


if (!rex::isBackend()) {
	rex_extension::register('OUTPUT_FILTER', function(rex_extension_point $ep) {
		
		$content = $ep->getSubject();
		
		if (!is_null(rex_article::getCurrent())) {
			preg_match_all("/REX_NAVBUILDER\[name=(.*?)]/", $content, $matches, PREG_SET_ORDER);
			
			foreach($matches as $match){
				$content = str_replace($match[0], rex_navbuilder::get($match[1]), $content);
			}
		}
		
		$ep->setSubject($content);
	});
}
