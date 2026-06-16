<?php

namespace nexphant\Runtime\IPC;

interface MessageBusInterface
{
    public function send(int $type, string $message): bool;
    public function receive(int $type, int $maxSize = 8192): ?string;
    public function hasMessage(int $type): bool;
}
