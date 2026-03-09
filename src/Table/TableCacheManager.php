<?php

declare(strict_types=1);

namespace Haoa\SwooleMemory\Table;

use Haoa\Util\Util;
use Psr\Log\LoggerInterface;
use Swoole\Table;

class TableCacheManager
{

    public static ?LoggerInterface $logger;

    public static bool $enableDebug = true;

    private static array $tables = [];
    // 索引表
    private static ?TableCache $indexTable = null;

    // index => type 映射
    private static array $indexToTypeMap = [];
    // type => index 映射
    private static array $typeToIndexMap = [];
    // string type => valueSize 映射
    private static array $stringTypeConfig = [];

    const TYPE_INT = 'int';
    const TYPE_FLOAT = 'float';
    const TYPE_STRINGS = 'strings';

    public static array $configExample = [
        TableCacheManager::TYPE_INT => ['table_size' => 128],
        TableCacheManager::TYPE_FLOAT => ['table_size' => 128],
        TableCacheManager::TYPE_STRINGS => [
            'string32' => ['table_size' => 128, 'value_size' => 32],
            'string64' => ['table_size' => 128, 'value_size' => 64],
            'string128' => ['table_size' => 128, 'value_size' => 128],
            'string256' => ['table_size' => 128, 'value_size' => 256],
            'string1K' => ['table_size' => 128, 'value_size' => 1024],
            'string2K' => ['table_size' => 128, 'value_size' => 2048],
            'string4K' => ['table_size' => 128, 'value_size' => 4096],
        ],
    ];

    public static function init(array $config): void
    {
        $sizeTotal = 0;

        self::$tables = [];
        self::$indexToTypeMap = [];
        self::$typeToIndexMap = [];
        self::$stringTypeConfig = [];

        foreach ($config as $type => $typeConfig) {
            if ($type === self::TYPE_INT) {
                self::$tables[$type] = new TableCache($typeConfig['table_size'], Table::TYPE_INT, 0, $typeConfig['conflict_proportion'] ?? 0.2);
                self::$indexToTypeMap[] = $type;
                $sizeTotal += $typeConfig['table_size'];
                continue;
            }
            if ($type === self::TYPE_FLOAT) {
                self::$tables[$type] = new TableCache($typeConfig['table_size'], Table::TYPE_FLOAT, 0, $typeConfig['conflict_proportion'] ?? 0.2);
                self::$indexToTypeMap[] = $type;
                $sizeTotal += $typeConfig['table_size'];
                continue;
            }
            if ($type === self::TYPE_STRINGS) {
                // 按 value_size 从小到大排序
                uasort($typeConfig, fn($a, $b) => $a['value_size'] <=> $b['value_size']);
                foreach ($typeConfig as $stringType => $stringConfig) {
                    self::$tables[$stringType] = new TableCache($stringConfig['table_size'], Table::TYPE_STRING, $stringConfig['value_size'], 0, $stringConfig['conflict_proportion'] ?? 0.2);
                    self::$indexToTypeMap[] = $stringType;
                    // 保存 string type 的 valueSize 配置
                    self::$stringTypeConfig[$stringType] = $stringConfig['value_size'];
                    $sizeTotal += $stringConfig['table_size'];
                }
            }
        }

        self::$typeToIndexMap = array_flip(self::$indexToTypeMap);

        self::$indexTable = new TableCache($sizeTotal + 128, Table::TYPE_INT);
    }

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function setEnableDebug(bool $enableDebug): void
    {
        self::$enableDebug = $enableDebug;
    }

    public static function getIndexTable(): ?TableCache
    {
        return self::$indexTable;
    }

    public static function getTables(): array
    {
        return self::$tables;
    }

    public static function getIndexToTypeMap(): array
    {
        return self::$indexToTypeMap;
    }

    public static function getStringTypeConfig(): array
    {
        return self::$stringTypeConfig;
    }

    public static function set(string $key, mixed $value, int $ttl = 10): bool
    {
        $type = TableRouter::detectType($value);
        $valueType = gettype($value);
        $logContext = [
            'key' => $key,
            'table' => $type,
            'value_type' => $valueType,
        ];
        if (is_string($value)) {
            $len = strlen($value);
            $logContext['value_len'] = $len;
        } else {
            $logContext['value'] = $value;
        }

        if ($type === '') {
            TableCacheManager::$logger && TableCacheManager::$logger->notice("TableCacheManager set, detectType failed", $logContext);
            return false;
        }

        $index = self::$typeToIndexMap[$type];
        $table = self::$tables[$type];

        // 先设置索引，再设置值，保证一致性
        $indexSet = TableRouter::setIndex($key, $index, $ttl);
        if (!$indexSet) {
            TableCacheManager::$logger && TableCacheManager::$logger->notice("TableCacheManager set, index set failed", $logContext);
            return false;
        }

        $valueSet = $table->set($key, $value, $ttl);
        if (!$valueSet) {
            // 回滚索引
            TableRouter::delIndex($key);
            TableCacheManager::$logger && TableCacheManager::$logger->notice("TableCacheManager set, value set failed", $logContext);
            return false;
        }

        TableCacheManager::$enableDebug && TableCacheManager::$logger && TableCacheManager::$logger->debug('TableCacheManager set successful', $logContext);

        return true;
    }

    public static function get(string $key): mixed
    {
        $table = TableRouter::getTableByKey($key);
        if ($table === null) {
            return null;
        }
        return $table->get($key);
    }

    public static function del(string $key): bool
    {
        $table = TableRouter::getTableByKey($key);
        if ($table === null) {
            return false;
        }
        // 同时删除数据和索引
        $dataDeleted = $table->del($key);
        $indexDeleted = TableRouter::delIndex($key);
        return $dataDeleted && $indexDeleted;
    }

    public static function getSummary(): array
    {
        $data = [
            'size'   => 0, // 总槽位数
            'count'  => 0, // 总记录数
            'memory_size' => 0, // 总内存（字节）
        ];
        foreach (self::$tables as $type => $table) {
            /** @var TableCache $table */

            $data['size'] += $table->getSize();
            $data['count'] += $table->count();
            $data['memory_size'] += $table->getMemorySize();
        }
        $data['size'] += self::$indexTable->getSize();
        $data['count'] += self::$indexTable->count();
        $data['memory_size'] += self::$indexTable->getMemorySize();


        $data['memory'] = Util::formatBytes($data['memory_size']);

        return $data;
    }

    public static function stats(): array
    {
        $indexStats = self::$indexTable->stats();
        $indexStats['size'] = self::$indexTable->getSize();
        $indexStats['count'] = self::$indexTable->count();
        $indexStats['memory_size'] = self::$indexTable->getMemorySize();
        $indexStats['memory'] = Util::formatBytes($indexStats['memory_size']);
        $data = [
            'index' => $indexStats,
        ];
        foreach (self::$tables as $type => $table) {
            /** @var TableCache $table */

            $stats = $table->stats();
            if ($stats) {
                $stats['size'] = $table->getSize();
                $stats['count'] = $table->count();
                $stats['memory_size'] = $table->getMemorySize();
                $stats['memory'] = Util::formatBytes($stats['memory_size']);
            }

            $data[$type] = $stats;
        }
        return $data;
    }
}
