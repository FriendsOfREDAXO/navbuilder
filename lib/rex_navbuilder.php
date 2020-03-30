<?php

class rex_navbuilder
{
    /**
     * get the structure of nav $name
     * 
     * @param string $name
     * 
     * @return string
     */
     
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

    /**
     * builds a nav of $items for $depth
     * 
     * @param array $items
     * @param int $depth
     * 
     * @return string
     */

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

    /**
     * get the raw structure of nav $name
     * 
     * @param string $name
     * 
     * @return array
     */

    public static function getStructure($name) {

        $menu = rex_navbuilder_navigation::query()
            ->select('id')
            ->where('name', $name)
            ->orderBy('id')
            ->findOne();

        $items = json_decode($menu->structure, true);
       
        return self::buildStructure($items);
    }

    /**
     * builds a raw nav of $items
     * 
     * @param array $items
     * 
     * @return array
     */

    private static function buildStructure($items) {
        $list = [];

        foreach ($items as $item):
        
            $data = [
                "type" => $item["type"]
            ];

            $children = [];
            if (!empty($item['children'])) {
                $data["children"] = self::buildStructure($item['children']);
            }

            if ($item['type'] === 'intern' && rex_article::get($item["href"]) ){
                
                $data["id"] = $item["href"];
                $data["active"] = $item["href"] == rex_article::getCurrentId();
                $data["name"] = rex_article::get($item["href"])->getName();

            } else if ($item['type'] === 'extern') {

                $data["name"] = $item["text"];
                $data["href"] = $item["href"];

            } else if ($item['type'] === 'group') {

                $data["name"] = $item["text"];

            }

            $list[] = $data;
        
        endforeach;

        return $list;
    }
}
