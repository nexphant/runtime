<?php

/**
 * This file is part of the nexphant Framework.
 *
 * (c) nexphant <https://github.com/nexphant>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace nexphant\Runtime;

/**
 * Socket wrapper for async I/O operations.
 * 
 * Only available when sockets extension loaded.
 * Falls back to blocking operations otherwise.
 */
class Socket
{
    private $socket;
    private bool $nonBlocking = false;

    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    /**
     * Create TCP socket.
     */
    public static function tcp(): ?self
    {
        if (!function_exists('socket_create')) {
            return null;
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return null;
        }

        return new self($socket);
    }

    /**
     * Create UDP socket.
     */
    public static function udp(): ?self
    {
        if (!function_exists('socket_create')) {
            return null;
        }

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return null;
        }

        return new self($socket);
    }

    /**
     * Set non-blocking mode.
     */
    public function setNonBlocking(bool $enable = true): bool
    {
        if (!function_exists('socket_set_nonblock')) {
            return false;
        }

        $result = $enable
            ? socket_set_nonblock($this->socket)
            : socket_set_block($this->socket);

        if ($result) {
            $this->nonBlocking = $enable;
        }

        return $result;
    }

    /**
     * Bind socket to address.
     */
    public function bind(string $address, int $port): bool
    {
        if (!function_exists('socket_bind')) {
            return false;
        }

        return socket_bind($this->socket, $address, $port);
    }

    /**
     * Listen for connections.
     */
    public function listen(int $backlog = 128): bool
    {
        if (!function_exists('socket_listen')) {
            return false;
        }

        return socket_listen($this->socket, $backlog);
    }

    /**
     * Accept connection.
     */
    public function accept(): ?self
    {
        if (!function_exists('socket_accept')) {
            return null;
        }

        $client = socket_accept($this->socket);
        if ($client === false) {
            return null;
        }

        return new self($client);
    }

    /**
     * Connect to remote address.
     */
    public function connect(string $address, int $port): bool
    {
        if (!function_exists('socket_connect')) {
            return false;
        }

        return socket_connect($this->socket, $address, $port);
    }

    /**
     * Read data from socket.
     */
    public function read(int $length = 8192): string|false
    {
        if (!function_exists('socket_read')) {
            return false;
        }

        return socket_read($this->socket, $length);
    }

    /**
     * Write data to socket.
     */
    public function write(string $data): int|false
    {
        if (!function_exists('socket_write')) {
            return false;
        }

        return socket_write($this->socket, $data);
    }

    /**
     * Close socket.
     */
    public function close(): void
    {
        if (function_exists('socket_close') && $this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Get last error.
     */
    public function getError(): string
    {
        if (!function_exists('socket_strerror') || !function_exists('socket_last_error')) {
            return 'Socket functions not available';
        }

        return socket_strerror(socket_last_error($this->socket));
    }
}
