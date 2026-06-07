<?php

namespace Nexph\Runtime\IPC;

use Nexph\Support\Extension\ExtensionDetector;

class SupervisorCommandBus
{
    private MessageBusInterface $bus;

    public function __construct(?MessageBusInterface $bus = null)
    {
        $this->bus = $bus ?? $this->createBus();
    }

    private function createBus(): MessageBusInterface
    {
        if (ExtensionDetector::has('sysvmsg')) {
            return new SysvMessageBus(ftok(__FILE__, 's'));
        }
        return new FileMessageBus('/tmp/nexph-supervisor.queue');
    }

    public function sendCommand(string $command, array $data = []): void
    {
        $this->bus->send(['command' => $command, 'data' => $data]);
    }

    public function receiveCommand(float $timeout = 0.0): ?array
    {
        return $this->bus->receive($timeout);
    }

    public function broadcast(string $command, array $data = []): void
    {
        $this->bus->broadcast(['command' => $command, 'data' => $data]);
    }

    public function sendReload(): void
    {
        $this->sendCommand('reload');
    }

    public function sendShutdown(): void
    {
        $this->sendCommand('shutdown');
    }

    public function sendDrain(): void
    {
        $this->sendCommand('drain');
    }

    public function sendWorkerStatus(int $workerId, string $status): void
    {
        $this->sendCommand('worker_status', ['worker_id' => $workerId, 'status' => $status]);
    }

    public function cleanup(): void
    {
        $this->bus->cleanup();
    }
}
