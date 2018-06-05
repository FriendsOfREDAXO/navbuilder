CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%navbuilder_navigation` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` text DEFAULT '',
  `structure` text DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;