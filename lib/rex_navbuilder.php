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

        return self::buildNavigation($items, $depth);
    }

    private static function buildNavigation($items, $depth)
    {
        $list = '';
        foreach ($items as $item) {

            $children = '';
            if (!empty($item['children'])) {
                $children = self::buildNavigation($item['children'], $depth + 1);
            }

            if ($item['type'] == 'intern' && rex_article::get($item["href"]) ){
                $active = '';
                if ($item["href"] == rex_article::getCurrentId()) {
                    $active = " rex-active";
                }
                $list .= '<li class="rex-link-internal rex-article-' . $item["href"] . $active . '"><a href="' . rex_getUrl($item["href"]) . '">' . rex_article::get($item["href"])->getName() . '</a>' . $children . '</li>';
            } else if ($item['type'] == 'extern') {
                $list .= '<li class="rex-link-external"><a href="' . $item["href"] . '">' . $item["text"] . '</a>' . $children . '</li>';
            } else if ($item['type'] == 'group') {
                $list .= '<li class="rex-link-group">' . $item["text"] . $children . '</li>';
            }

        }
        return '<ul class="rex-navi-depth-' . $depth . '">' . $list . '</ul>';
    }
}
