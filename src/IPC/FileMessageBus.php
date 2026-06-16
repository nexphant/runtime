<?php

namespace nexphant\Runtime\IPC;

class FileMessageBus implements MessageBusInterface
{
    private string $dir;

    public function __construct(string $name)
    {
        $this->dir = sys_get_temp_dir() . '/nexphant-mq-' . md5($name);
        
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    public function send(int $type, string $message): bool
    {
        $file = $this->dir . '/' . $type . '-' . uniqid() . '.msg';
        return file_put_contents($file, $message) !== false;
    }

    public function receive(int $type, int $maxSize = 8192): ?string
    {
        $files = glob($this->dir . '/' . $type . '-*.msg');
        
        if (empty($files)) {
            return null;
        }

        sort($files);
        $file = $files[0];
        
        $message = file_get_contents($file);
        @unlink($file);
        
        return $message !== false ? substr($message, 0, $maxSize) : null;
    }

    public function hasMessage(int $type): bool
    {
        $files = glob($this->dir . '/' . $type . '-*.msg');
        return !empty($files);
    }
}
