<?php

namespace Nexph\Runtime\Config;

class RuntimeConfig
{
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    public function merge(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function all(): array
    {
        return $this->config;
    }
}
