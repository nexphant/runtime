<?php

namespace nexphant\Runtime\Config;

class RuntimeConfigResolver
{
    private static function cpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $cores = @file_get_contents('/proc/cpuinfo');
            if ($cores && preg_match_all('/^processor/m', $cores, $matches)) {
                return count($matches[0]);
            }
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            $cores = @shell_exec('sysctl -n hw.ncpu');
            if ($cores) {
                return (int)trim($cores);
            }
        }
        return 4;
    }

    private static function hasNativeExtensions(): bool
    {
        return extension_loaded('sockets') && (extension_loaded('ev') || extension_loaded('event') || extension_loaded('uv'));
    }

    private static function autoEventLoop(): string
    {
        if (extension_loaded('event')) return 'event';
        if (extension_loaded('ev')) return 'ev';
        if (extension_loaded('uv')) return 'uv';
        return 'select';
    }

    private static function autoSocketDriver(): string
    {
        return extension_loaded('sockets') ? 'native' : 'stream';
    }

    public static function resolveProfile(array $userConfig = []): RuntimeProfile
    {
        $cores = self::cpuCores();
        $mode = $userConfig['runtime_mode'] ?? 'auto';
        $hasNative = self::hasNativeExtensions();
        
        if ($mode === 'auto') {
            $mode = $hasNative ? 'low_latency' : 'balanced';
        }

        $profiles = [
            'low_latency' => [
                'workers' => $cores,
                'max_accept_per_tick' => 8,
                'max_read_callbacks_per_tick' => 256,
                'max_write_callbacks_per_tick' => 256,
                'max_deferred_per_tick' => 256,
                'keep_alive_timeout' => 5,
                'keep_alive_max_requests' => 10000,
                'features' => [
                    'metrics' => false,
                    'object_tracking' => false,
                    'histogram' => false,
                    'route_latency' => false,
                    'stats_file_writes' => false,
                ],
            ],
            'balanced' => [
                'workers' => max(4, $cores),
                'max_accept_per_tick' => 16,
                'max_read_callbacks_per_tick' => 512,
                'max_write_callbacks_per_tick' => 512,
                'max_deferred_per_tick' => 512,
                'keep_alive_timeout' => 10,
                'keep_alive_max_requests' => 500,
                'features' => [
                    'metrics' => true,
                    'object_tracking' => false,
                    'histogram' => false,
                    'route_latency' => false,
                    'stats_file_writes' => true,
                ],
            ],
            'throughput' => [
                'workers' => $cores * 2,
                'max_accept_per_tick' => 32,
                'max_read_callbacks_per_tick' => 1024,
                'max_write_callbacks_per_tick' => 1024,
                'max_deferred_per_tick' => 1024,
                'keep_alive_timeout' => 30,
                'keep_alive_max_requests' => 10000,
                'features' => [
                    'metrics' => true,
                    'object_tracking' => false,
                    'histogram' => true,
                    'route_latency' => true,
                    'stats_file_writes' => true,
                ],
            ],
            'benchmark' => [
                'workers' => $cores,
                'max_accept_per_tick' => 8,
                'max_read_callbacks_per_tick' => 128,
                'max_write_callbacks_per_tick' => 128,
                'max_deferred_per_tick' => 128,
                'keep_alive_timeout' => 2,
                'keep_alive_max_requests' => 10000,
                'features' => [
                    'metrics' => false,
                    'object_tracking' => false,
                    'histogram' => false,
                    'route_latency' => false,
                    'stats_file_writes' => false,
                ],
            ],
        ];

        $preset = $profiles[$mode] ?? $profiles['balanced'];
        
        $eventLoop = $userConfig['event_loop'] ?? getenv('nexphant_LOOP') ?: 'auto';
        $socketDriver = $userConfig['socket_driver'] ?? getenv('nexphant_SOCKET') ?: 'auto';
        
        if ($eventLoop === 'auto') $eventLoop = self::autoEventLoop();
        if ($socketDriver === 'auto') $socketDriver = self::autoSocketDriver();
        
        $features = array_merge($preset['features'], $userConfig['runtime_features'] ?? []);

        return new RuntimeProfile(
            name: $mode,
            workers: $userConfig['workers'] ?? $preset['workers'],
            eventLoop: $eventLoop,
            socketDriver: $socketDriver,
            maxAcceptPerTick: $userConfig['max_accept_per_tick'] ?? $preset['max_accept_per_tick'],
            maxReadCallbacksPerTick: $userConfig['max_read_callbacks_per_tick'] ?? $preset['max_read_callbacks_per_tick'],
            maxWriteCallbacksPerTick: $userConfig['max_write_callbacks_per_tick'] ?? $preset['max_write_callbacks_per_tick'],
            maxDeferredPerTick: $userConfig['max_deferred_per_tick'] ?? $preset['max_deferred_per_tick'],
            keepAliveTimeout: $userConfig['keep_alive_timeout'] ?? $preset['keep_alive_timeout'],
            keepAliveMaxRequests: $userConfig['keep_alive_max_requests'] ?? $preset['keep_alive_max_requests'],
            runtimeFeatures: $features,
        );
    }

    public static function resolve(array $userConfig = []): RuntimeConfig
    {
        $profile = self::resolveProfile($userConfig);
        return new RuntimeConfig($profile->toArray());
    }
}
