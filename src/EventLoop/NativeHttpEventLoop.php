<?php

namespace nexphant\Runtime\EventLoop;

use Event;
use EventBase;

class NativeHttpEventLoop implements EventLoopInterface
{
    private EventBase $base;
    private array $timers = [];
    private array $readWatchers = [];
    private array $writeWatchers = [];
    private array $signalWatchers = [];
    private bool $running = false;
    private int $nextTimerId = 1;

    public function __construct()
    {
        if (!extension_loaded('event')) {
            throw new \RuntimeException('ext-event required');
        }
        $this->base = new EventBase();
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->readWatchers[$id])) {
            $this->readWatchers[$id]->free();
        }

        $event = new Event($this->base, $stream, Event::READ | Event::PERSIST, $callback);
        $event->add();
        $this->readWatchers[$id] = $event;
    }

    public function removeReadable($stream): void
    {
        $id = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->readWatchers[$id])) {
            $this->readWatchers[$id]->del();
            $this->readWatchers[$id]->free();
            unset($this->readWatchers[$id]);
        }
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->writeWatchers[$id])) {
            $this->writeWatchers[$id]->free();
        }

        $event = new Event($this->base, $stream, Event::WRITE | Event::PERSIST, $callback);
        $event->add();
        $this->writeWatchers[$id] = $event;
    }

    public function removeWritable($stream): void
    {
        $id = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->writeWatchers[$id])) {
            $this->writeWatchers[$id]->del();
            $this->writeWatchers[$id]->free();
            unset($this->writeWatchers[$id]);
        }
    }

    public function onSignal(int $signal, callable $callback): int
    {
        $id = $signal;
        if (isset($this->signalWatchers[$id])) {
            $this->signalWatchers[$id]->free();
        }

        $event = Event::signal($this->base, $signal, $callback);
        $event->add();
        $this->signalWatchers[$id] = $event;
        
        return $id;
    }

    public function removeSignal(int $id): void
    {
        if (isset($this->signalWatchers[$id])) {
            $this->signalWatchers[$id]->del();
            $this->signalWatchers[$id]->free();
            unset($this->signalWatchers[$id]);
        }
    }

    public function timer(float $seconds, callable $callback, bool $repeat = false): int
    {
        $id = $this->nextTimerId++;
        
        $flags = Event::TIMEOUT;
        if ($repeat) {
            $flags |= Event::PERSIST;
        }

        $event = new Event($this->base, -1, $flags, $callback);
        $event->add($seconds);
        
        $this->timers[$id] = $event;
        
        return $id;
    }

    public function cancelTimer(int $id): void
    {
        if (isset($this->timers[$id])) {
            $this->timers[$id]->del();
            $this->timers[$id]->free();
            unset($this->timers[$id]);
        }
    }

    public function run(): void
    {
        if ($this->running) {
            throw new \RuntimeException('Loop already running');
        }

        $this->running = true;
        $this->base->loop();
        $this->running = false;
    }

    public function stop(): void
    {
        $this->running = false;
        $this->base->stop();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
