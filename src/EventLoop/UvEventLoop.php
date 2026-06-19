<?php

namespace Nexphant\Runtime\EventLoop;

class UvEventLoop implements EventLoopInterface
{
    private $loop;
    private array $readers = [];
    private array $writers = [];
    private array $timers = [];
    private array $signals = [];
    private bool $running = false;
    private int $nextTimerId = 1;
    private int $nextSignalId = 1;

    public function __construct()
    {
        if (!extension_loaded('uv')) {
            throw new \RuntimeException('ext-uv not available (experimental)');
        }
        $this->loop = uv_loop_new();
    }

    public function __destruct()
    {
        if ($this->loop !== null) {
            uv_loop_delete($this->loop);
            $this->loop = null;
        }
    }

    public function onReadable($stream, callable $callback): void
    {
        $key = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->readers[$key])) {
            return;
        }
        $poll = uv_poll_init($this->loop, $stream);
        uv_poll_start($poll, \UV::READABLE, function () use ($callback, $stream) {
            $callback($stream);
        });
        $this->readers[$key] = $poll;
    }

    public function removeReadable($stream): void
    {
        $key = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->readers[$key])) {
            uv_poll_stop($this->readers[$key]);
            unset($this->readers[$key]);
        }
    }

    public function onWritable($stream, callable $callback): void
    {
        $key = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->writers[$key])) {
            return;
        }
        $poll = uv_poll_init($this->loop, $stream);
        uv_poll_start($poll, \UV::WRITABLE, function () use ($callback, $stream) {
            $callback($stream);
        });
        $this->writers[$key] = $poll;
    }

    public function removeWritable($stream): void
    {
        $key = is_resource($stream) ? (int) $stream : spl_object_id($stream);
        if (isset($this->writers[$key])) {
            uv_poll_stop($this->writers[$key]);
            unset($this->writers[$key]);
        }
    }

    public function timer(float $seconds, callable $callback, bool $repeat = false): int
    {
        $id = $this->nextTimerId++;
        $ms = (int) ($seconds * 1000);
        $timer = uv_timer_init($this->loop);
        if ($repeat) {
            uv_timer_start($timer, $ms, $ms, $callback);
        } else {
            uv_timer_start($timer, $ms, 0, function () use ($id, $callback) {
                $callback();
                $this->cancelTimer($id);
            });
        }
        $this->timers[$id] = $timer;
        return $id;
    }

    public function cancelTimer(int $id): void
    {
        if (isset($this->timers[$id])) {
            uv_timer_stop($this->timers[$id]);
            unset($this->timers[$id]);
        }
    }

    public function onSignal(int $signal, callable $callback): int
    {
        $id = $this->nextSignalId++;
        $sig = uv_signal_init($this->loop);
        uv_signal_start($sig, function () use ($callback, $signal) {
            $callback($signal);
        }, $signal);
        $this->signals[$id] = $sig;
        return $id;
    }

    public function removeSignal(int $id): void
    {
        if (isset($this->signals[$id])) {
            uv_signal_stop($this->signals[$id]);
            unset($this->signals[$id]);
        }
    }

    public function run(): void
    {
        $this->running = true;
        uv_run($this->loop);
    }

    public function stop(): void
    {
        $this->running = false;
        uv_stop($this->loop);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
