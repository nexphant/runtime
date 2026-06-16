<?php

namespace nexphant\Runtime\Config;

final class RuntimeProfile
{
    public function __construct(
        public readonly string $name,
        public readonly int $workers,
        public readonly string $eventLoop,
        public readonly string $socketDriver,
        public readonly int $maxAcceptPerTick,
        public readonly int $maxReadCallbacksPerTick,
        public readonly int $maxWriteCallbacksPerTick,
        public readonly int $maxDeferredPerTick,
        public readonly int $keepAliveTimeout,
        public readonly int $keepAliveMaxRequests,
        public readonly array $runtimeFeatures,
    ) {}

    public function toArray(): array
    {
        return [
            'runtime_mode' => $this->name,
            'workers' => $this->workers,
            'event_loop' => $this->eventLoop,
            'socket_driver' => $this->socketDriver,
            'max_accept_per_tick' => $this->maxAcceptPerTick,
            'max_read_callbacks_per_tick' => $this->maxReadCallbacksPerTick,
            'max_write_callbacks_per_tick' => $this->maxWriteCallbacksPerTick,
            'max_deferred_per_tick' => $this->maxDeferredPerTick,
            'keep_alive_timeout' => $this->keepAliveTimeout,
            'keep_alive_max_requests' => $this->keepAliveMaxRequests,
            'runtime_features' => $this->runtimeFeatures,
        ];
    }
}
