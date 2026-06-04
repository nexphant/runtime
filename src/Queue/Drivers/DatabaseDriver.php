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
 * Database queue driver.
 * 
 * Persistent, transactional. Good for production.
 */
class DatabaseDriver implements QueueDriver {
    private \PDO $pdo;
    private string $table;
    private string $deadLetterTable;
    
    public function __construct(\PDO $pdo, string $table = 'queue_jobs', string $deadLetterTable = 'queue_dead_letters') {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->deadLetterTable = $deadLetterTable;
        $this->ensureTables();
    }
    
    public function push(Job $job): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} 
            (id, name, payload, status, attempts, max_attempts, timeout, created_at, available_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $job->id,
            $job->name,
            json_encode($job->payload),
            $job->status,
            $job->attempts,
            $job->max_attempts,
            $job->timeout,
            $job->created_at,
            $job->available_at,
        ]);
    }
    
    public function pop(): ?Job {
        $now = time();
        
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                SELECT * FROM {$this->table}
                WHERE status = 'pending' AND available_at <= ?
                ORDER BY available_at ASC
                LIMIT 1
            ");
            
            $stmt->execute([$now]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$row) {
                $this->pdo->rollBack();
                return null;
            }
            
            $updateStmt = $this->pdo->prepare("
                UPDATE {$this->table}
                SET status = 'reserved'
                WHERE id = ? AND status = 'pending'
            ");
            $updateStmt->execute([$row['id']]);
            
            if ($updateStmt->rowCount() === 0) {
                $this->pdo->rollBack();
                return null;
            }
            
            $this->pdo->commit();
            
            $row['status'] = 'pending';
            return $this->rowToJob($row);
            
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return null;
        }
    }
    
    public function update(Job $job): void {
        $stmt = $this->pdo->prepare("
            UPDATE {$this->table}
            SET status = ?, attempts = ?, available_at = ?, started_at = ?, 
                completed_at = ?, failed_at = ?, error = ?, result = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $job->status,
            $job->attempts,
            $job->available_at,
            $job->started_at,
            $job->completed_at,
            $job->failed_at,
            $job->error,
            $job->result !== null ? json_encode($job->result) : null,
            $job->id,
        ]);
    }
    
    public function get(string $id): ?Job {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->rowToJob($row) : null;
    }
    
    public function delete(string $id): void {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
    }
    
    public function depth(): int {
        $now = time();
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE status = 'pending' AND available_at <= ?
        ");
        $stmt->execute([$now]);
        return (int)$stmt->fetchColumn();
    }
    
    public function pushDeadLetter(Job $job): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->deadLetterTable}
            (id, name, payload, status, attempts, max_attempts, timeout, created_at, 
             available_at, started_at, completed_at, failed_at, error, result)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $job->id,
            $job->name,
            json_encode($job->payload),
            $job->status,
            $job->attempts,
            $job->max_attempts,
            $job->timeout,
            $job->created_at,
            $job->available_at,
            $job->started_at,
            $job->completed_at,
            $job->failed_at,
            $job->error,
            $job->result !== null ? json_encode($job->result) : null,
        ]);
        
        $this->delete($job->id);
    }
    
    public function getDeadLetters(int $limit = 100): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->deadLetterTable}
            ORDER BY failed_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        
        $jobs = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $jobs[] = $this->rowToJob($row);
        }
        
        return $jobs;
    }
    
    public function clear(): void {
        $this->pdo->exec("DELETE FROM {$this->table}");
        $this->pdo->exec("DELETE FROM {$this->deadLetterTable}");
    }
    
    private function rowToJob(array $row): Job {
        return new Job([
            'id' => $row['id'],
            'name' => $row['name'],
            'payload' => json_decode($row['payload'], true) ?? [],
            'status' => $row['status'],
            'attempts' => (int)$row['attempts'],
            'max_attempts' => (int)$row['max_attempts'],
            'timeout' => (int)$row['timeout'],
            'created_at' => (int)$row['created_at'],
            'available_at' => (int)$row['available_at'],
            'started_at' => $row['started_at'] ? (int)$row['started_at'] : null,
            'completed_at' => $row['completed_at'] ? (int)$row['completed_at'] : null,
            'failed_at' => $row['failed_at'] ? (int)$row['failed_at'] : null,
            'error' => $row['error'] ?? null,
            'result' => isset($row['result']) ? json_decode($row['result'], true) : null,
        ]);
    }
    
    private function ensureTables(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id VARCHAR(32) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                payload TEXT,
                status VARCHAR(20) NOT NULL,
                attempts INT DEFAULT 0,
                max_attempts INT DEFAULT 3,
                timeout INT DEFAULT 300,
                created_at INT NOT NULL,
                available_at INT NOT NULL,
                started_at INT NULL,
                completed_at INT NULL,
                failed_at INT NULL,
                error TEXT NULL,
                result TEXT NULL
            )
        ");
        
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_status_available ON {$this->table} (status, available_at)
        ");
        
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->deadLetterTable} (
                id VARCHAR(32) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                payload TEXT,
                status VARCHAR(20) NOT NULL,
                attempts INT DEFAULT 0,
                max_attempts INT DEFAULT 3,
                timeout INT DEFAULT 300,
                created_at INT NOT NULL,
                available_at INT NOT NULL,
                started_at INT NULL,
                completed_at INT NULL,
                failed_at INT NULL,
                error TEXT NULL,
                result TEXT NULL
            )
        ");
        
        $this->pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_failed_at ON {$this->deadLetterTable} (failed_at)
        ");
    }
}
