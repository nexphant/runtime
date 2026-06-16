<?php

namespace nexphant\Runtime\IPC;

class SysvMessageBus implements MessageBusInterface
{
    private $queue;

    public function __construct(int $key)
    {
        if (!extension_loaded('sysvmsg')) {
            throw new \RuntimeException('ext-sysvmsg is not available');
        }

        $this->queue = msg_get_queue($key, 0666);

        if ($this->queue === false) {
            throw new \RuntimeException('Failed to create message queue');
        }
    }

    public function send(int $type, string $message): bool
    {
        return @msg_send($this->queue, $type, $message, true, false);
    }

    public function receive(int $type, int $maxSize = 8192): ?string
    {
        $msgType = 0;
        $message = '';
        
        if (@msg_receive($this->queue, $type, $msgType, $maxSize, $message, true, MSG_IPC_NOWAIT)) {
            return $message;
        }

        return null;
    }

    public function hasMessage(int $type): bool
    {
        $stats = msg_stat_queue($this->queue);
        return $stats && $stats['msg_qnum'] > 0;
    }

    public function destroy(): void
    {
        if ($this->queue) {
            @msg_remove_queue($this->queue);
            $this->queue = null;
        }
    }

    public function __destruct()
    {
    }
}
