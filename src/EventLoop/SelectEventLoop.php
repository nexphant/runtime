<?php

namespace Nexphant\Runtime\EventLoop;

use Nexphant\Core\Context\ContextStore;

class SelectEventLoop implements EventLoopInterface
{
    private array $timers = [];
    private \SplPriorityQueue $timerHeap;
    private bool $running = false;
    private int $nextTimerId = 1;
    private array $readStreams = [];
    private array $readCallbacks = [];
    private array $writeStreams = [];
    private array $writeCallbacks = [];
    private array $signalHandlers = [];
    private int $nextSignalId = 1;

    public function __construct()
    {
        $this->timerHeap = new \SplPriorityQueue();
        $this->timerHeap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    private function streamId($stream): int
    {
        return is_resource($stream) ? (int) $stream : spl_object_id($stream);
    }

    private function isValidStream($stream): bool
    {
        return is_resource($stream) || $stream instanceof \Socket;
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = $this->streamId($stream);
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $callback;
    }

    public function removeReadable($stream): void
    {
        $id = $this->streamId($stream);
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
        if (is_resource($stream)) {
            @fclose($stream);
        }
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = $this->streamId($stream);
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $callback;
    }

    public function removeWritable($stream): void
    {
        $id = $this->streamId($stream);
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
        if (is_resource($stream)) {
            @fclose($stream);
        }
    }

    public function onSignal(int $signal, callable $callback): int
    {
        if (!function_exists('pcntl_signal')) {
            throw new \RuntimeException('pcntl extension required for signal handling');
        }

        $id = $this->nextSignalId++;
        $this->signalHandlers[$id] = ['signal' => $signal, 'callback' => $callback];
        
        pcntl_signal($signal, function($signo) use ($id) {
            if (isset($this->signalHandlers[$id])) {
                ($this->signalHandlers[$id]['callback'])($signo);
            }
        });
        
        return $id;
    }

    public function removeSignal(int $id): void
    {
        if (isset($this->signalHandlers[$id])) {
            $signal = $this->signalHandlers[$id]['signal'];
            pcntl_signal($signal, SIG_DFL);
            unset($this->signalHandlers[$id]);
        }
    }

    public function timer(float $seconds, callable $callback, bool $repeat = false): int
    {
        $context = ContextStore::instance()->current();
        $parentOwnerId = $context->ownerId();
        
        $timerOwner = class_exists('\Nexphant\Runtime\Runtime') && \Nexphant\Runtime\Runtime::available()
            ? \Nexphant\Runtime\Runtime::owners()->open(
                \Nexphant\Core\Ownership\OwnerType::TIMER,
                $parentOwnerId ? \Nexphant\Runtime\Runtime::owners()->get($parentOwnerId)?->id() : null,
                ['interval' => $seconds, 'repeat' => $repeat]
            )
            : null;
        
        $id = $this->nextTimerId++;
        $next = microtime(true) + $seconds;
        $this->timers[$id] = [
            'callback' => $callback,
            'interval' => $seconds,
            'next' => $next,
            'repeat' => $repeat,
            'context' => $context,
            'owner' => $timerOwner,
        ];
        $this->timerHeap->insert($id, -$next);
        
        if (class_exists('\Nexphant\Core\Resource\ResourceRegistry') && $timerOwner) {
            \Nexphant\Core\Resource\ResourceRegistry::instance()->track(
                (object)['timer_id' => $id],
                'timer',
                $timerOwner->id()
            );
        }
        
        return $id;
    }

    public function cancelTimer(int $id): void
    {
        if (isset($this->timers[$id])) {
            $timer = $this->timers[$id];
            if ($timer['owner'] ?? null) {
                $timer['owner']->close('timer_cancelled');
            }
        }
        unset($this->timers[$id]);
    }

    public function run(): void
    {
        if ($this->running) {
            throw new \RuntimeException('Event loop is already running');
        }

        $this->running = true;

        while ($this->running && $this->hasWork()) {
            $this->tick();
        }

        $this->running = false;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    private function tick(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        $now = microtime(true);
        $this->processTimers($now);
        $this->pollIO();

        if (empty($this->readStreams) && empty($this->writeStreams)) {
            $nextWake = $this->nextWakeTime();
            $sleepTime = $nextWake !== null ? max(0, $nextWake - microtime(true)) : 0;
            if ($sleepTime > 0) {
                usleep((int) min($sleepTime * 1_000_000, 10_000));
            }
        }
    }

    private function processTimers(float $now): void
    {
        while (!$this->timerHeap->isEmpty()) {
            $id = $this->timerHeap->top();

            if (!isset($this->timers[$id])) {
                $this->timerHeap->extract();
                continue;
            }

            $timer = $this->timers[$id];
            if ($timer['next'] > $now) {
                break;
            }

            $this->timerHeap->extract();
            
            if (class_exists('\Nexphant\Core\Drain\DrainController')) {
                $drainController = \Nexphant\Core\Drain\DrainController::instance();
                if (!$drainController->isAccepting()) {
                    if ($timer['owner'] ?? null) {
                        $timer['owner']->close('timer_skipped_drain');
                    }
                    unset($this->timers[$id]);
                    continue;
                }
                
                $token = $drainController->cancellationToken();
                if ($token && $token->isCancelled()) {
                    if ($timer['owner'] ?? null) {
                        $timer['owner']->close('timer_cancelled_drain');
                    }
                    unset($this->timers[$id]);
                    continue;
                }
            }
            
            $context = $timer['context'] ?? null;
            if ($context !== null) {
                $store = ContextStore::instance();
                $store->runWith($context, $timer['callback']);
            } else {
                ($timer['callback'])();
            }

            if (!isset($this->timers[$id])) {
                continue;
            }

            if ($timer['repeat']) {
                $next = $now + $timer['interval'];
                $this->timers[$id]['next'] = $next;
                $this->timerHeap->insert($id, -$next);
            } else {
                if ($timer['owner'] ?? null) {
                    $timer['owner']->close('timer_completed');
                }
                unset($this->timers[$id]);
            }
        }
    }

    private function pollIO(): void
    {
        if (empty($this->readStreams) && empty($this->writeStreams)) {
            return;
        }

        $this->cleanInvalidStreams();

        if (empty($this->readStreams) && empty($this->writeStreams)) {
            return;
        }

        $readStreams = [];
        $writeStreams = [];
        $readSockets = [];
        $writeSockets = [];

        foreach ($this->readStreams as $stream) {
            if ($stream instanceof \Socket) {
                $readSockets[] = $stream;
            } elseif (is_resource($stream)) {
                $readStreams[] = $stream;
            }
        }

        foreach ($this->writeStreams as $stream) {
            if ($stream instanceof \Socket) {
                $writeSockets[] = $stream;
            } elseif (is_resource($stream)) {
                $writeStreams[] = $stream;
            }
        }

        $this->pollStreams($readStreams, $writeStreams);
        $this->pollSockets($readSockets, $writeSockets);
    }

    private function pollStreams(array $read, array $write): void
    {
        if (empty($read) && empty($write)) {
            return;
        }

        $except = null;
        try {
            $result = @stream_select($read, $write, $except, 0, 10000);
        } catch (\TypeError|\ValueError) {
            $this->cleanInvalidStreams();
            return;
        }

        if ($result === false || $result === 0) {
            return;
        }

        $this->dispatchReadable($read);
        $this->dispatchWritable($write);
    }

    private function pollSockets(array $read, array $write): void
    {
        if (empty($read) && empty($write)) {
            return;
        }

        $except = [];
        try {
            $result = @socket_select($read, $write, $except, 0, 10000);
        } catch (\TypeError|\ValueError) {
            $this->cleanInvalidStreams();
            return;
        }

        if ($result === false || $result === 0) {
            return;
        }

        $this->dispatchReadable($read);
        $this->dispatchWritable($write);
    }

    private function dispatchReadable(array $streams): void
    {
        foreach ($streams as $stream) {
            $id = $this->streamId($stream);
            if (isset($this->readCallbacks[$id])) {
                ($this->readCallbacks[$id])($stream);
            }
        }
    }

    private function dispatchWritable(array $streams): void
    {
        foreach ($streams as $stream) {
            $id = $this->streamId($stream);
            if (isset($this->writeCallbacks[$id])) {
                ($this->writeCallbacks[$id])($stream);
            }
        }
    }

    private function cleanInvalidStreams(): void
    {
        foreach ($this->readStreams as $id => $stream) {
            if (!$this->isValidStream($stream)) {
                unset($this->readStreams[$id], $this->readCallbacks[$id]);
            }
        }
        foreach ($this->writeStreams as $id => $stream) {
            if (!$this->isValidStream($stream)) {
                unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
            }
        }
    }

    private function nextWakeTime(): ?float
    {
        $next = null;

        while (!$this->timerHeap->isEmpty()) {
            $id = $this->timerHeap->top();
            if (!isset($this->timers[$id])) {
                $this->timerHeap->extract();
                continue;
            }
            $timerNext = $this->timers[$id]['next'];
            $next = $next === null ? $timerNext : min($next, $timerNext);
            break;
        }

        return $next;
    }

    private function hasWork(): bool
    {
        return !empty($this->timers)
            || !empty($this->readStreams)
            || !empty($this->writeStreams);
    }
}
