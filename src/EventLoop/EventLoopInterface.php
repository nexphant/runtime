<?php

namespace Nexphant\Runtime\EventLoop;

interface EventLoopInterface
{
    public function onReadable($stream, callable $callback): void;
    public function removeReadable($stream): void;
    public function onWritable($stream, callable $callback): void;
    public function removeWritable($stream): void;
    public function onSignal(int $signal, callable $callback): int;
    public function removeSignal(int $id): void;
    public function timer(float $seconds, callable $callback, bool $repeat = false): int;
    public function cancelTimer(int $id): void;
    public function run(): void;
    public function stop(): void;
    public function isRunning(): bool;
}
