<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Queue\Drivers;

use Nexph\Queue\QueueDriver;
use Nexph\Queue\Job;

/**
 * File-based queue driver.
 * 
 * Persistent, no dependencies. Good for shared hosting.
 */
class FileDriver implements QueueDriver {
    private string $queueDir;
    private string $deadLetterDir;
    private int $maxPayloadSize;
    private int $maxFileSize;
    
    public function __construct(?string $basePath = null, int $maxPayloadSize = 10485760, int $maxFileSize = 52428800) {
        $basePath = $basePath ?? sys_get_temp_dir() . '/nexph-queue';
        $this->queueDir = $basePath . '/jobs';
        $this->deadLetterDir = $basePath . '/dead-letters';
        $this->maxPayloadSize = $maxPayloadSize;
        $this->maxFileSize = $maxFileSize;
        
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0755, true);
        }
        
        if (!is_dir($this->deadLetterDir)) {
            mkdir($this->deadLetterDir, 0755, true);
        }
    }
    
    public function push(Job $job): void {
        $data = json_encode($job->toArray());
        if (strlen($data) > $this->maxPayloadSize) {
            throw new \RuntimeException("Job payload exceeds max size: " . strlen($data) . " bytes");
        }
        $file = $this->queueDir . '/' . $job->id . '.json';
        $fp = fopen($file, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file: {$file}");
        }
        
        // Track file handle (skip if not object in PHP 8.0)
        if (class_exists('\Nexph\Runtime\Resource\ResourceRegistry') && class_exists('\Nexph\Runtime\Runtime') && \Nexph\Runtime\Runtime::available()) {
            if (is_object($fp)) {
                \Nexph\Runtime\Resource\ResourceRegistry::instance()->track(
                    $fp,
                    'file_handle',
                    \Nexph\Runtime\Runtime::context()->ownerId()
                );
            }
        }
        
        flock($fp, LOCK_EX);
        fwrite($fp, $data);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
    
    public function pop(): ?Job {
        $now = time();
        $files = glob($this->queueDir . '/*.json');
        
        foreach ($files as $file) {
            if (filesize($file) > $this->maxFileSize) {
                $this->quarantineFile($file, 'oversized');
                continue;
            }
            
            $fp = fopen($file, 'r+');
            if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
                if ($fp) fclose($fp);
                continue;
            }
            
            rewind($fp);
            $content = stream_get_contents($fp);
            $data = $this->safeJsonDecode($content);
            
            if ($data === null) {
                flock($fp, LOCK_UN);
                fclose($fp);
                $this->quarantineFile($file, 'corrupted');
                continue;
            }
            
            if ($data && $data['status'] === 'pending' && $data['available_at'] <= $now) {
                $data['status'] = 'reserved';
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data));
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);
                $data['status'] = 'pending';
                return Job::fromArray($data);
            }
            
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        
        return null;
    }
    
    public function update(Job $job): void {
        $file = $this->queueDir . '/' . $job->id . '.json';
        file_put_contents($file, json_encode($job->toArray()), LOCK_EX);
    }
    
    public function get(string $id): ?Job {
        $file = $this->queueDir . '/' . $id . '.json';
        
        if (!file_exists($file)) {
            return null;
        }
        
        if (filesize($file) > $this->maxFileSize) {
            return null;
        }
        
        $content = file_get_contents($file);
        $data = $this->safeJsonDecode($content);
        return $data ? Job::fromArray($data) : null;
    }
    
    public function delete(string $id): void {
        $file = $this->queueDir . '/' . $id . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    public function depth(): int {
        $count = 0;
        $now = time();
        $files = glob($this->queueDir . '/*.json');
        
        foreach ($files as $file) {
            if (filesize($file) > $this->maxFileSize) {
                continue;
            }
            $content = file_get_contents($file);
            $data = $this->safeJsonDecode($content);
            if ($data && $data['status'] === 'pending' && $data['available_at'] <= $now) {
                $count++;
            }
        }
        
        return $count;
    }
    
    public function pushDeadLetter(Job $job): void {
        $file = $this->deadLetterDir . '/' . $job->id . '.json';
        file_put_contents($file, json_encode($job->toArray()), LOCK_EX);
        $this->delete($job->id);
    }
    
    public function getDeadLetters(int $limit = 100): array {
        $files = glob($this->deadLetterDir . '/*.json');
        $jobs = [];
        
        foreach (array_slice($files, 0, $limit) as $file) {
            if (filesize($file) > $this->maxFileSize) {
                continue;
            }
            $content = file_get_contents($file);
            $data = $this->safeJsonDecode($content);
            if ($data) {
                $jobs[] = Job::fromArray($data);
            }
        }
        
        return $jobs;
    }
    
    public function clear(): void {
        $files = glob($this->queueDir . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
        
        $files = glob($this->deadLetterDir . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
    }
    
    private function safeJsonDecode(string $content): ?array {
        if (strlen($content) > $this->maxPayloadSize) {
            return null;
        }
        
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException $e) {
            return null;
        }
    }
    
    private function quarantineFile(string $file, string $reason): void {
        $quarantineDir = dirname($this->queueDir) . '/quarantine';
        if (!is_dir($quarantineDir)) {
            @mkdir($quarantineDir, 0755, true);
        }
        $basename = basename($file);
        $quarantinePath = $quarantineDir . '/' . $reason . '_' . time() . '_' . $basename;
        @rename($file, $quarantinePath);
    }
}
