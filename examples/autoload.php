<?php
$rootDir = dirname(__DIR__, 1);
require $rootDir . '/vendor/autoload.php';

spl_autoload_register(function($class) {
    $baseDir = dirname(__DIR__, 1) . '/src';
    $prefix = 'Haoa\\SwooleMemory\\';
    $offset = strlen($prefix);

    if (strpos($class, $prefix) === 0) {
        $path = substr($class, $offset, strlen($class));
        $path = $baseDir . '/' . str_replace('\\', DIRECTORY_SEPARATOR, $path) . '.php';
        require($path);
        return;
    }

    // examples 命名空间
    $examplesPrefix = 'examples\\';
    $examplesDir = dirname(__DIR__, 1) . '/examples';

    if (strpos($class, $examplesPrefix) === 0) {
        $path = substr($class, strlen($examplesPrefix), strlen($class));
        $path = $examplesDir . '/' . str_replace('\\', DIRECTORY_SEPARATOR, $path) . '.php';
        require($path);
    }
});