<?php

declare(strict_types=1);

namespace Haoa\SwooleMemory\Table;

class TableRouter
{
    public static function detectType(int|float|string $value): string
    {
        $tables = TableCacheManager::getTables();

        // int 类型
        if (isset($tables[TableCacheManager::TYPE_INT]) && is_int($value)) {
            return TableCacheManager::TYPE_INT;
        }
        // float 类型
        if (isset($tables[TableCacheManager::TYPE_FLOAT]) && is_float($value)) {
            return TableCacheManager::TYPE_FLOAT;
        }
        // string 类型：遍历所有 string table，找到第一个能容纳该长度的
        if (is_string($value)) {
            $len = strlen($value);
            $stringTypeConfig = TableCacheManager::getStringTypeConfig();
            foreach ($stringTypeConfig as $type => $valueSize) {
                if ($len <= $valueSize) {
                    return $type;
                }
            }
        }
        return '';
    }

    public static function getIndex(string $key): ?int
    {
        $indexTable = TableCacheManager::getIndexTable();
        $result = $indexTable->get($key);
        if ($result === false) {
            TableCacheManager::$enableDebug && TableCacheManager::$logger && TableCacheManager::$logger->debug("TableRouter get index failed: {$key}");
            return null;
        }
        return $result;
    }

    public static function setIndex(string $key, int $index, int $ttl): bool
    {
        return TableCacheManager::getIndexTable()->set($key, $index, $ttl);
    }

    public static function delIndex(string $key): bool
    {
        return TableCacheManager::getIndexTable()->del($key);
    }

    public static function getTableByType(string $type): ?TableCache
    {
        return TableCacheManager::getTables()[$type] ?? null;
    }

    public static function getTableByKey(string $key): ?TableCache
    {
        $index = self::getIndex($key);
        if ($index === null) {
            return null;
        }
        $type = TableCacheManager::getIndexToTypeMap()[$index] ?? null;
        if ($type === null) {
            return null;
        }
        return self::getTableByType($type);
    }
}
