<?php

use examples\Logger;
use Haoa\SwooleMemory\Table\TableCacheManager;

require __DIR__ . '/autoload.php';

$logger = new Logger();
$config = TableCacheManager::$configExample;

TableCacheManager::setLogger($logger);
TableCacheManager::init($config);

TableCacheManager::set('a', 1);
TableCacheManager::set('b', 1.1);
TableCacheManager::set('c', str_repeat('a', 32));
TableCacheManager::set('d', str_repeat('b', 46));
TableCacheManager::set('e', str_repeat('b', 100));

$a = TableCacheManager::get('a');
$b = TableCacheManager::get('b');
$c = TableCacheManager::get('c');
$d = TableCacheManager::get('d');
$e = TableCacheManager::get('e');
var_dump(['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e]);

var_dump(TableCacheManager::stats(), TableCacheManager::getSummary());

