<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Queue;

use Nexph\Queue\Drivers\MemoryDriver;
use Nexph\Queue\Drivers\FileDriver;
use Nexph\Queue\Drivers\DatabaseDriver;
use Nexph\Queue\Drivers\ApcuDriver;
use Nexph\Queue\Drivers\ApcuRingDriver;
use Nexph\Queue\Drivers\RedisDriver;

class QueueFactory {
    public static function create(string|array $config = [], array $options = []): Queue {
        if (is_string($config)) {
            $config = ['driver' => $config] + $options;
        } else {
            $config = $config + $options;
        }
        
        $config = self::normalizeConfig($config);
        $driver = self::createDriver($config);
        return new Queue($driver, $config);
    }
    
    public static function createWithDriver(string $driverName, array $config = []): Queue {
        return self::create(['driver' => $driverName] + $config);
    }
    
    private static function normalizeConfig(array $config): array {
        $normalized = $config;
        
        if (isset($config['pdo'])) {
            $normalized['database'] = $config['pdo'];
            unset($normalized['pdo']);
        }
        
        if (isset($config['path'])) {
            $normalized['file_path'] = $config['path'];
            unset($normalized['path']);
        }
        
        return $normalized;
    }
    
    private static function createDriver(array $config): QueueDriver {
        $driverName = $config['driver'] ?? null;
        
        if ($driverName) {
            return self::createDriverByName($driverName, $config);
        }
        
        if (extension_loaded('redis') && !empty($config['redis'])) {
            return self::createRedisDriver($config);
        }
        
        if (!empty($config['database'])) {
            return self::createDatabaseDriver($config);
        }
        
        if (extension_loaded('apcu') && apcu_enabled()) {
            return self::createApcuDriver($config);
        }
        
        if (is_writable(sys_get_temp_dir())) {
            return self::createFileDriver($config);
        }
        
        return self::createMemoryDriver($config);
    }
    
    private static function createDriverByName(string $name, array $config): QueueDriver {
        return match($name) {
            'memory' => self::createMemoryDriver($config),
            'file' => self::createFileDriver($config),
            'database' => self::createDatabaseDriver($config),
            'apcu' => self::createApcuDriver($config),
            'apcu_ring' => self::createApcuRingDriver($config),
            'lock_free' => self::createApcuRingDriver($config),
            'redis' => self::createRedisDriver($config),
            default => throw new \InvalidArgumentException("Unknown driver: {$name}"),
        };
    }
    
    private static function createMemoryDriver(array $config): QueueDriver {
        return new MemoryDriver();
    }
    
    private static function createFileDriver(array $config): QueueDriver {
        $path = $config['file_path'] ?? null;
        $maxPayloadSize = $config['max_payload_size'] ?? 10485760;
        $maxFileSize = $config['max_file_size'] ?? 52428800;
        return new FileDriver($path, $maxPayloadSize, $maxFileSize);
    }
    
    private static function createDatabaseDriver(array $config): QueueDriver {
        if (empty($config['database'])) {
            throw new \InvalidArgumentException(
                'Database driver requires "database" key with PDO instance. ' .
                'Usage: create("database", ["pdo" => $pdo]) or create(["driver" => "database", "database" => $pdo])'
            );
        }
        
        $pdo = $config['database'];
        
        if (!($pdo instanceof \PDO)) {
            throw new \InvalidArgumentException('Database must be a PDO instance');
        }
        
        $table = $config['table'] ?? 'queue_jobs';
        $deadLetterTable = $config['dead_letter_table'] ?? 'queue_dead_letters';
        
        return new DatabaseDriver($pdo, $table, $deadLetterTable);
    }
    
    private static function createApcuDriver(array $config): QueueDriver {
        $prefix = $config['prefix'] ?? 'nexph_queue';
        $maxPayloadSize = $config['max_payload_size'] ?? 10485760;
        return new ApcuDriver($prefix, $maxPayloadSize);
    }

    private static function createApcuRingDriver(array $config): QueueDriver {
        $prefix = $config['prefix'] ?? 'nexph_queue_ring';
        $maxPayloadSize = $config['max_payload_size'] ?? 10485760;
        $scanLimit = $config['scan_limit'] ?? 1024;
        return new ApcuRingDriver($prefix, $maxPayloadSize, $scanLimit);
    }
    
    private static function createRedisDriver(array $config): QueueDriver {
        if (empty($config['redis'])) {
            throw new \InvalidArgumentException(
                'Redis driver requires "redis" key with Redis instance. ' .
                'Usage: create("redis", ["redis" => $redis]) or create(["driver" => "redis", "redis" => $redis])'
            );
        }
        
        $redis = $config['redis'];
        
        if (!($redis instanceof \Redis)) {
            throw new \InvalidArgumentException('Redis must be a Redis instance');
        }
        
        $prefix = $config['prefix'] ?? 'nexph_queue';
        $maxPayloadSize = $config['max_payload_size'] ?? 10485760;
        
        return new RedisDriver($redis, $prefix, $maxPayloadSize);
    }
}
