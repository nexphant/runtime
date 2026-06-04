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
 * In-memory queue driver.
 * 
 * Fast, no persistence. Good for testing and development.
 */
class MemoryDriver implements QueueDriver {
    private array $jobs = [];
    private array $deadLetters = [];
    
    public function push(Job $job): void {
        $this->jobs[$job->id] = $job;
    }
    
    public function pop(): ?Job {
        $now = time();
        
        foreach ($this->jobs as $id => $job) {
            if ($job->status === 'pending' && $job->available_at <= $now) {
                // Mark as taken by changing status
                $job->status = 'reserved';
                return $job;
            }
        }
        
        return null;
    }
    
    public function update(Job $job): void {
        $this->jobs[$job->id] = $job;
    }
    
    public function get(string $id): ?Job {
        return $this->jobs[$id] ?? null;
    }
    
    public function delete(string $id): void {
        unset($this->jobs[$id]);
    }
    
    public function depth(): int {
        $count = 0;
        $now = time();
        
        foreach ($this->jobs as $job) {
            if ($job->status === 'pending' && $job->available_at <= $now) {
                $count++;
            }
        }
        
        return $count;
    }
    
    public function pushDeadLetter(Job $job): void {
        $this->deadLetters[$job->id] = $job;
    }
    
    public function getDeadLetters(int $limit = 100): array {
        return array_values(array_slice($this->deadLetters, 0, $limit));
    }
    
    public function clear(): void {
        $this->jobs = [];
        $this->deadLetters = [];
    }
}
