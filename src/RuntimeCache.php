<?php

namespace nexphant\Runtime;

final class RuntimeCache
{
    private static bool $configured = false;
    private static bool $shared = false;
    private static string $prefix = 'nexphant:runtime:';
    private static int $maxEntries = 8192;
    private static array $store = [];
    private static array $order = [];

    public static function configure(array $config): void
    {
        self::$prefix = (string) ($config['runtime_cache_prefix'] ?? self::$prefix);
        self::$maxEntries = max(1, (int) ($config['runtime_cache_entries'] ?? self::$maxEntries));
        self::$shared = (bool) ($config['runtime_shared_cache'] ?? true) &&
            function_exists('apcu_fetch') &&
            apcu_enabled();
        self::$configured = true;
    }

    public static function shared(): bool
    {
        self::boot();
        return self::$shared;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::boot();
        $key = self::$prefix . $key;
        if (self::$shared) {
            $value = apcu_fetch($key, $ok);
            return $ok ? $value : $default;
        }

        if (!isset(self::$store[$key])) {
            return $default;
        }

        $entry = self::$store[$key];
        if ($entry['expires'] > 0.0 && $entry['expires'] < microtime(true)) {
            unset(self::$store[$key]);
            return $default;
        }
        return $entry['value'];
    }

    public static function set(string $key, mixed $value, int|float $ttl = 0): bool
    {
        self::boot();
        $key = self::$prefix . $key;
        if (self::$shared) {
            return apcu_store($key, $value, (int) max(0, $ttl));
        }

        if (!isset(self::$store[$key])) {
            self::$order[] = $key;
            if (count(self::$order) > self::$maxEntries) {
                unset(self::$store[array_shift(self::$order)]);
            }
        }
        self::$store[$key] = [
            'expires' => $ttl > 0 ? microtime(true) + (float) $ttl : 0.0,
            'value' => $value,
        ];
        return true;
    }

    public static function delete(string $key): bool
    {
        self::boot();
        $key = self::$prefix . $key;
        if (self::$shared) {
            return apcu_delete($key);
        }
        unset(self::$store[$key]);
        return true;
    }

    public static function clearLocal(): void
    {
        self::$store = [];
        self::$order = [];
    }

    private static function boot(): void
    {
        if (!self::$configured) {
            self::configure([]);
        }
    }
}
