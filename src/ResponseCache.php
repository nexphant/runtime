<?php

namespace nexphant\Runtime;

final class ResponseCache
{
    private static bool $enabled = false;
    private static float $ttl = 0.0;
    private static int $maxEntries = 4096;
    private static bool $shared = false;
    private static array $store = [];
    private static array $order = [];

    public static function configure(array $config): void
    {
        self::$ttl = max(0.0, (float) ($config['hot_path_cache_ttl'] ?? 0.0));
        self::$enabled = self::$ttl > 0.0 && (bool) ($config['hot_path_cache'] ?? true);
        self::$maxEntries = max(1, (int) ($config['hot_path_cache_entries'] ?? self::$maxEntries));
        self::$shared = (bool) ($config['hot_path_cache_shared'] ?? false) && RuntimeCache::shared();
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    public static function key(string $method, string $uri, array $headers = []): string
    {
        $accept = $headers['accept'] ?? '';
        $connection = $headers['connection'] ?? '';
        return $method . ' ' . $uri . "\0" . $accept . "\0" . $connection;
    }

    public static function get(string $key): ?string
    {
        if (!self::$enabled) {
            return null;
        }

        if (self::$shared) {
            $entry = RuntimeCache::get('response:' . hash('xxh3', $key));
            if (!is_array($entry)) {
                return null;
            }
            return (string) ($entry['raw'] ?? '');
        }

        if (!isset(self::$store[$key])) {
            return null;
        }
        $entry = self::$store[$key];
        if ($entry['expires'] < microtime(true)) {
            unset(self::$store[$key]);
            return null;
        }

        return $entry['raw'];
    }

    public static function set(string $key, string $raw, ?float $ttl = null): void
    {
        if (!self::$enabled || $raw === '') {
            return;
        }

        if (self::$shared) {
            RuntimeCache::set('response:' . hash('xxh3', $key), ['raw' => $raw], (int) ceil($ttl ?? self::$ttl));
            return;
        }

        if (!isset(self::$store[$key])) {
            self::$order[] = $key;
            if (count(self::$order) > self::$maxEntries) {
                unset(self::$store[array_shift(self::$order)]);
            }
        }

        self::$store[$key] = [
            'expires' => microtime(true) + ($ttl ?? self::$ttl),
            'raw' => $raw,
        ];
    }

    public static function flush(): void
    {
        self::$store = [];
        self::$order = [];
    }

    public static function invalidatePath(string $path): void
    {
        if (!self::$enabled || empty(self::$store)) {
            return;
        }

        $keysToRemove = [];
        foreach (self::$store as $key => $_) {
            // Key format: "METHOD /path\0accept\0connection"
            $spacePos = strpos($key, ' ');
            if ($spacePos === false) continue;
            $nullPos = strpos($key, "\0", $spacePos);
            $storedPath = $nullPos !== false
                ? substr($key, $spacePos + 1, $nullPos - $spacePos - 1)
                : substr($key, $spacePos + 1);
            if ($storedPath === $path || str_starts_with($storedPath, $path . '/')) {
                $keysToRemove[] = $key;
            }
        }

        foreach ($keysToRemove as $key) {
            unset(self::$store[$key]);
        }

        if (!empty($keysToRemove)) {
            self::$order = array_values(array_diff(self::$order, $keysToRemove));
        }
    }
}
