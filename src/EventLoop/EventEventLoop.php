<?php

namespace Nexphant\Runtime\EventLoop;

use Nexphant\Core\Context\ContextStore;
use Event;
use EventBase;

class EventEventLoop implements EventLoopInterface
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
            throw new \RuntimeException('ext-event is not available');
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

        $event = \Event::signal($this->base, $signal, $callback);
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
        
        $wrappedCallback = function() use ($callback, $context, $timerOwner, $id, $repeat) {
            if (class_exists('\Nexphant\Core\Drain\DrainController')) {
                $drainController = \Nexphant\Core\Drain\DrainController::instance();
                if (!$drainController->isAccepting()) {
                    if ($timerOwner) {
                        $timerOwner->close('timer_skipped_drain');
                    }
                    $this->cancelTimer($id);
                    return;
                }
                
                $token = $drainController->cancellationToken();
                if ($token && $token->isCancelled()) {
                    if ($timerOwner) {
                        $timerOwner->close('timer_cancelled_drain');
                    }
                    $this->cancelTimer($id);
                    return;
                }
            }
            
            if ($context !== null) {
                $store = ContextStore::instance();
                $store->runWith($context, $callback);
            } else {
                $callback();
            }

            if (!$repeat && $timerOwner) {
                $timerOwner->close('timer_completed');
            }
        };

        $flags = Event::TIMEOUT;
        if ($repeat) {
            $flags |= Event::PERSIST;
        }

        $event = new Event($this->base, -1, $flags, $wrappedCallback);
        $event->add($seconds);
        
        $this->timers[$id] = [
            'event' => $event,
            'context' => $context,
            'owner' => $timerOwner,
        ];
        
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
            $timer['event']->del();
            $timer['event']->free();
            
            if ($timer['owner'] ?? null) {
                $timer['owner']->close('timer_cancelled');
            }
            
            unset($this->timers[$id]);
        }
    }

    public function run(): void
    {
        if ($this->running) {
            throw new \RuntimeException('Event loop is already running');
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
