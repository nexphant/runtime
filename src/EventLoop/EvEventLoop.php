<?php

namespace Nexphant\Runtime\EventLoop;

class EvEventLoop implements EventLoopInterface
{
    private array $readers = [];
    private array $writers = [];
    private array $timers = [];
    private array $signals = [];
    private bool $running = false;
    private int $nextTimerId = 1;
    private int $nextSignalId = 1;

    public function __construct()
    {
        if (!extension_loaded('ev')) {
            throw new \RuntimeException('ext-ev is not available');
        }
    }

    public function onReadable($stream, callable $callback): void
    {
        $key = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->readers[$key])) {
            return;
        }
        $this->readers[$key] = \EvIo::createStopped($stream, \Ev::READ, function () use ($callback, $stream) {
            $callback($stream);
        });
        if ($this->running) {
            $this->readers[$key]->start();
        }
    }

    public function removeReadable($stream): void
    {
        $key = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->readers[$key])) {
            $this->readers[$key]->stop();
            unset($this->readers[$key]);
        }
    }

    public function onWritable($stream, callable $callback): void
    {
        $key = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->writers[$key])) {
            return;
        }
        $this->writers[$key] = \EvIo::createStopped($stream, \Ev::WRITE, function () use ($callback, $stream) {
            $callback($stream);
        });
        if ($this->running) {
            $this->writers[$key]->start();
        }
    }

    public function removeWritable($stream): void
    {
        $key = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->writers[$key])) {
            $this->writers[$key]->stop();
            unset($this->writers[$key]);
        }
    }

    public function timer(float $seconds, callable $callback, bool $repeat = false): int
    {
        $id = $this->nextTimerId++;
        if ($repeat) {
            $this->timers[$id] = \EvTimer::createStopped($seconds, $seconds, $callback);
        } else {
            $this->timers[$id] = \EvTimer::createStopped($seconds, 0.0, function () use ($id, $callback) {
                $callback();
                $this->cancelTimer($id);
            });
        }
        if ($this->running) {
            $this->timers[$id]->start();
        }
        return $id;
    }

    public function cancelTimer(int $id): void
    {
        if (isset($this->timers[$id])) {
            $this->timers[$id]->stop();
            unset($this->timers[$id]);
        }
    }

    public function onSignal(int $signal, callable $callback): int
    {
        $id = $this->nextSignalId++;
        $this->signals[$id] = \EvSignal::createStopped($signal, function () use ($callback, $signal) {
            $callback($signal);
        });
        if ($this->running) {
            $this->signals[$id]->start();
        }
        return $id;
    }

    public function removeSignal(int $id): void
    {
        if (isset($this->signals[$id])) {
            $this->signals[$id]->stop();
            unset($this->signals[$id]);
        }
    }

    public function run(): void
    {
        $this->running = true;
        foreach ($this->readers as $watcher) {
            $watcher->start();
        }
        foreach ($this->writers as $watcher) {
            $watcher->start();
        }
        foreach ($this->timers as $watcher) {
            $watcher->start();
        }
        foreach ($this->signals as $watcher) {
            $watcher->start();
        }
        \Ev::run();
    }

    public function stop(): void
    {
        $this->running = false;
        foreach ($this->timers as $watcher) {
            $watcher->stop();
        }
        foreach ($this->readers as $watcher) {
            $watcher->stop();
        }
        foreach ($this->writers as $watcher) {
            $watcher->stop();
        }
        foreach ($this->signals as $watcher) {
            $watcher->stop();
        }
        $this->timers = [];
        \Ev::stop();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
