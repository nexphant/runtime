<?php

namespace Nexphant\Runtime;

class CompiledHotPath
{
    private static ?array $routes = null;
    private static ?array $middleware = null;
    private static ?array $container = null;
    private static ?array $config = null;
    private static ?array $schedule = null;

    public static function load(string $compiledPath): void
    {
        $routesFile = $compiledPath . '/routes.php';
        $middlewareFile = $compiledPath . '/middleware.php';
        $containerFile = $compiledPath . '/container.php';
        $configFile = $compiledPath . '/config.php';
        $scheduleFile = $compiledPath . '/schedule.php';

        if (file_exists($routesFile)) {
            self::$routes = require $routesFile;
        }
        
        if (file_exists($middlewareFile)) {
            self::$middleware = require $middlewareFile;
        }
        
        if (file_exists($containerFile)) {
            self::$container = require $containerFile;
        }
        
        if (file_exists($configFile)) {
            self::$config = require $configFile;
        }

        if (file_exists($scheduleFile)) {
            self::$schedule = require $scheduleFile;
        }
    }

    public static function routes(): ?array
    {
        return self::$routes;
    }

    public static function middleware(): ?array
    {
        return self::$middleware;
    }

    public static function container(): ?array
    {
        return self::$container;
    }

    public static function config(): ?array
    {
        return self::$config;
    }

    public static function schedule(): ?array
    {
        return self::$schedule;
    }
}
