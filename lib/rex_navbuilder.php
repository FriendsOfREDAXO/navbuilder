<?php

class rex_navbuilder
{
    public static function get($name)
    {
		$depth = 1;
		
        $menu = rex_navbuilder_navigation::query()
            ->select('id')
            ->where('name', $name)
            ->orderBy('id')
            ->findOne();

        $items = json_decode($menu->structure, true);

        return self::buildNavigation($items,$depth,$class);
    }

    private static function buildNavigation($items, $depth)
    {
        $list = '';
        foreach ($items as $item) {
			
			$active = ' rex-normal"';
			if($item["href"] == rex_article::getCurrentId()){
				$active = " rex-active";
			}

            if (!empty($item['children'])) {
                if ($item['text'] !== '') {
                    $list .= '<li class="rex-article-' . $item["href"] . $active . '"><a href="' . rex_getUrl($item["href"]) . '" target="' . $item["target"] . '">' . $item['text'] . '</a>' . self::buildNavigation($item['children'], $depth + 1) . '</li>';
                } else if ($item['group'] !== '') {
                    $list .= '<li class="rex-article-' . $item["href"] . $active . '"><span class="group">' . $item['group'] . '</span>' . self::buildNavigation($item['children'], $depth + 1) . '</li>';
                }

            } else {
                $list .= '<li class="rex-article-' . $item["href"] . $active . '"><a href="' . rex_getUrl($item["href"]) . '" target="' . $item["target"] . '">' . $item['text'] . '</a></li>';
            }

        }
        return '<ul class="rex-navi-depth-' . $depth . '">' . $list . '</ul>';
    }
}
