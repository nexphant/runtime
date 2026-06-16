<?php

namespace Nexphant\Runtime;

use Nexphant\Database\DB;
use Nexphant\Database\Drivers\DriverInterface;

class ConnectionPool
{
    private static array $pools = [];
    private static array $config = [];
    private static int $maxConnections = 10;
    private static int $idleTimeout = 300;
    private static int $sequence = 0;

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$maxConnections = max(1, (int) ($config['max_connections'] ?? 10));
        self::$idleTimeout = max(1, (int) ($config['idle_timeout'] ?? 300));
    }

    public static function get(string $name = 'default'): DriverInterface
    {
        self::cleanup($name);
        self::$pools[$name] ??= [];

        foreach (self::$pools[$name] as &$entry) {
            if (!$entry['in_use']) {
                $entry['in_use'] = true;
                $entry['last_used'] = time();
                $connection = $entry['connection'];
                unset($entry);
                return $connection;
            }
        }
        unset($entry);

        if (count(self::$pools[$name]) >= self::$maxConnections) {
            throw new \RuntimeException("Connection pool exhausted for '{$name}'");
        }

        $poolName = self::poolName($name);
        $connection = DB::connect(self::connectionConfig($name), $poolName);
        self::$pools[$name][] = [
            'name' => $poolName,
            'connection' => $connection,
            'in_use' => true,
            'created' => time(),
            'last_used' => time(),
        ];
        return $connection;
    }

    public static function release(object $connection, string $name = 'default'): void
    {
        if (!isset(self::$pools[$name])) {
            return;
        }
        foreach (self::$pools[$name] as &$entry) {
            if ($entry['connection'] === $connection) {
                $entry['in_use'] = false;
                $entry['last_used'] = time();
                unset($entry);
                return;
            }
        }
        unset($entry);
    }

    public static function stats(string $name = 'default'): array
    {
        self::cleanup($name);
        $pool = self::$pools[$name] ?? [];
        $total = count($pool);
        $inUse = count(array_filter($pool, fn($e) => $e['in_use']));
        return [
            'total' => $total,
            'in_use' => $inUse,
            'idle' => $total - $inUse,
            'max' => self::$maxConnections,
            'driver' => self::connectionConfig($name)['driver'] ?? null,
            'runtime_db' => true,
        ];
    }

    public static function closeAll(): void
    {
        foreach (self::$pools as $pool) {
            foreach ($pool as $entry) {
                DB::disconnect($entry['name']);
            }
        }
        self::$pools = [];
    }

    private static function cleanup(string $name): void
    {
        if (!isset(self::$pools[$name])) {
            return;
        }

        $now = time();
        foreach (self::$pools[$name] as $i => $entry) {
            if ($entry['in_use'] || ($now - $entry['last_used']) < self::$idleTimeout) {
                continue;
            }
            DB::disconnect($entry['name']);
            unset(self::$pools[$name][$i]);
        }
        self::$pools[$name] = array_values(self::$pools[$name]);
    }

    private static function connectionConfig(string $name): array
    {
        return self::$config['connections'][$name] ?? self::$config;
    }

    private static function poolName(string $name): string
    {
        return 'runtime_pool_' . $name . '_' . getmypid() . '_' . (++self::$sequence);
    }
}
