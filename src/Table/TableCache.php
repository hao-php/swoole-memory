<?php

declare(strict_types=1);

namespace Haoa\SwooleMemory\Table;

use Swoole\Table;

class TableCache
{
    private Table $table;

    public function __construct(int $size, int $valueType, int $valueSize = 0, float $conflict_proportion = 0.2)
    {
        $this->table = new Table($size, $conflict_proportion);
        $this->table->column('value', $valueType, $valueSize);
        $this->table->column('expire_time', Table::TYPE_INT);
        $this->table->create();
    }

    public function getTable(): Table
    {
        return $this->table;
    }

    public function getMemorySize(): int
    {
        return $this->table->memorySize;
    }

    public function getSize()
    {
        return $this->table->size;
    }

    public function stats()
    {
        return $this->table->stats();
    }

    public function count(): int
    {
        return $this->table->count();
    }

    public function set(string $key, int|float|string $value, int $ttl): bool
    {
        $data = [
            'value' => $value,
            'expire_time' => ($ttl == 0) ? 0 : time() + $ttl,
        ];
        $rs = $this->table->set($key, $data);
        if (!$rs) {
            $valueType = gettype($value);
            $logContext = [
                'key' => $key,
                'ttl' => $ttl,
                'value_type' => $valueType,
            ];
            if (is_string($value)) {
                $logContext['value_len'] = strlen($value);
            } else {
                $logContext['value'] = $value;
            }
            TableCacheManager::$logger && TableCacheManager::$logger->notice('TableCache set failed', $logContext);
            return false;
        }
        return true;
    }

    public function get(string $key): int|float|string|null
    {
        $result = $this->table->get($key);
        if ($result === false) {
            return null;
        }
        if ($this->isExpired($result)) {
            $this->table->del($key);
            return null;
        }
        return $result['value'];
    }

    public function del(string $key): bool
    {
        return $this->table->del($key);
    }

    public function expire(string $key, int $ttl): bool
    {
        $result = $this->table->get($key);
        if ($result === false) {
            return false;
        }
        $result['expire_time'] = time() + $ttl;
        return $this->table->set($key, $result);
    }

    private function isExpired($result): bool
    {
        $expireTime = $result['expire_time'] ?? 0;
        if ($expireTime > 0 && time() > $expireTime) {
            return true;
        }
        return false;
    }

    private function evict(): void
    {
        $now = time();
        $keys = [];
        // 官方文档说了, 不能在遍历时删除
        foreach ($this->table as $key => $row) {
            $expireTime = $row['expire_time'] ?? 0;
            if ($expireTime > 0 && $now > $expireTime) {
                $keys[] = $key;
            }
        }

        foreach ($keys as $key) {
            $this->table->del($key);
        }
    }
}
