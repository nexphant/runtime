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

class ApcuDriver implements QueueDriver {
    private string $prefix;
    private string $queueKey;
    private string $deadLetterKey;
    private int $maxPayloadSize;
    
    public function __construct(string $prefix = 'nexph_queue', int $maxPayloadSize = 10485760) {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new \RuntimeException('APCu extension not available');
        }
        $this->prefix = $prefix;
        $this->queueKey = $prefix . ':queue';
        $this->deadLetterKey = $prefix . ':dead_letters';
        $this->maxPayloadSize = $maxPayloadSize;
    }
    
    public function push(Job $job): void {
        $data = json_encode($job->toArray());
        if (strlen($data) > $this->maxPayloadSize) {
            throw new \RuntimeException("Job payload exceeds max size: " . strlen($data) . " bytes");
        }
        
        $key = $this->prefix . ':job:' . $job->id;
        apcu_store($key, $data);
        
        $queue = apcu_fetch($this->queueKey) ?: [];
        $queue[$job->id] = $job->available_at;
        apcu_store($this->queueKey, $queue);
    }
    
    public function pop(): ?Job {
        $now = time();
        $queue = apcu_fetch($this->queueKey) ?: [];
        
        foreach ($queue as $id => $availableAt) {
            if ($availableAt <= $now) {
                $key = $this->prefix . ':job:' . $id;
                $data = apcu_fetch($key);
                
                if ($data) {
                    try {
                        $jobData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($jobData) && $jobData['status'] === 'pending') {
                            unset($queue[$id]);
                            apcu_store($this->queueKey, $queue);
                            return Job::fromArray($jobData);
                        }
                    } catch (\JsonException $e) {
                        apcu_delete($key);
                    }
                }

                unset($queue[$id]);
                apcu_store($this->queueKey, $queue);
            }
        }
        
        return null;
    }
    
    public function update(Job $job): void {
        $key = $this->prefix . ':job:' . $job->id;
        $data = json_encode($job->toArray());
        apcu_store($key, $data);
        
        if ($job->status === 'pending') {
            $queue = apcu_fetch($this->queueKey) ?: [];
            $queue[$job->id] = $job->available_at;
            apcu_store($this->queueKey, $queue);
        }
    }
    
    public function get(string $id): ?Job {
        $key = $this->prefix . ':job:' . $id;
        $data = apcu_fetch($key);
        
        if (!$data) {
            return null;
        }
        
        try {
            $jobData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            return is_array($jobData) ? Job::fromArray($jobData) : null;
        } catch (\JsonException $e) {
            return null;
        }
    }
    
    public function delete(string $id): void {
        $key = $this->prefix . ':job:' . $id;
        apcu_delete($key);
        
        $queue = apcu_fetch($this->queueKey) ?: [];
        unset($queue[$id]);
        apcu_store($this->queueKey, $queue);
    }
    
    public function depth(): int {
        $now = time();
        $queue = apcu_fetch($this->queueKey) ?: [];
        $count = 0;
        
        foreach ($queue as $availableAt) {
            if ($availableAt <= $now) {
                $count++;
            }
        }
        
        return $count;
    }
    
    public function pushDeadLetter(Job $job): void {
        $key = $this->prefix . ':job:' . $job->id;
        apcu_store($key, json_encode($job->toArray()));
        
        $deadLetters = apcu_fetch($this->deadLetterKey) ?: [];
        $deadLetters[$job->id] = $job->failed_at ?? time();
        apcu_store($this->deadLetterKey, $deadLetters);
        
        $queue = apcu_fetch($this->queueKey) ?: [];
        unset($queue[$job->id]);
        apcu_store($this->queueKey, $queue);
    }
    
    public function getDeadLetters(int $limit = 100): array {
        $deadLetters = apcu_fetch($this->deadLetterKey) ?: [];
        arsort($deadLetters);
        $jobs = [];
        
        foreach (array_slice(array_keys($deadLetters), 0, $limit, true) as $id) {
            $key = $this->prefix . ':job:' . $id;
            $data = apcu_fetch($key);
            
            if ($data) {
                try {
                    $jobData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($jobData)) {
                        $jobs[] = Job::fromArray($jobData);
                    }
                } catch (\JsonException $e) {
                    continue;
                }
            }
        }
        
        return $jobs;
    }
    
    public function clear(): void {
        $queue = apcu_fetch($this->queueKey) ?: [];
        foreach (array_keys($queue) as $id) {
            apcu_delete($this->prefix . ':job:' . $id);
        }
        
        $deadLetters = apcu_fetch($this->deadLetterKey) ?: [];
        foreach (array_keys($deadLetters) as $id) {
            apcu_delete($this->prefix . ':job:' . $id);
        }
        
        apcu_delete($this->queueKey);
        apcu_delete($this->deadLetterKey);
    }
}
