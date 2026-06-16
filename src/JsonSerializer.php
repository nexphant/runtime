<?php

namespace Nexphant\Runtime;

final class JsonSerializer
{
    public const DEFAULT_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private static array $hot = [];
    private static array $order = [];
    private static int $maxEntries = 1024;

    public static function configure(array $config): void
    {
        self::$maxEntries = max(0, (int) ($config['json_cache_entries'] ?? self::$maxEntries));
    }

    public static function encode(mixed $data, int $flags = self::DEFAULT_FLAGS, int $depth = 512): string
    {
        if (is_array($data)) {
            $key = self::arrayKey($data, $flags, $depth);
            if ($key !== null && isset(self::$hot[$key])) {
                return self::$hot[$key];
            }

            $json = json_encode($data, $flags, $depth);
            if ($json === false) {
                return 'null';
            }
            if ($key !== null) {
                self::store($key, $json);
            }
            return $json;
        }

        $json = json_encode($data, $flags, $depth);
        return $json === false ? 'null' : $json;
    }

    public static function rawResponse(mixed $data, bool $keepAlive = true, int $status = 200, array $headers = []): string
    {
        $body = self::encode($data);
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        $headers['Server'] = $headers['Server'] ?? 'nexphant/1.0';
        $headers['Connection'] = $headers['Connection'] ?? ($keepAlive ? 'keep-alive' : 'close');
        $headers['Content-Length'] = (string) strlen($body);

        static $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ];

        $raw = 'HTTP/1.1 ' . $status . ' ' . ($statusTexts[$status] ?? 'OK') . "\r\n";
        foreach ($headers as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }
        return $raw . "\r\n" . $body;
    }

    private static function arrayKey(array $data, int $flags, int $depth): ?string
    {
        $count = count($data);
        if ($count === 0) {
            return $flags . ':empty';
        }
        if ($count > 128) {
            return null;
        }

        return $flags . ':' . $depth . ':' . md5(serialize($data));
    }

    private static function store(string $key, string $json): void
    {
        if (self::$maxEntries <= 0) {
            return;
        }
        if (!isset(self::$hot[$key])) {
            self::$order[] = $key;
            if (count(self::$order) > self::$maxEntries) {
                unset(self::$hot[array_shift(self::$order)]);
            }
        }
        self::$hot[$key] = $json;
    }
}
