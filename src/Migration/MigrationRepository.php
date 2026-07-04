<?php

declare(strict_types=1);

namespace UpdateCore\Migration;

class MigrationRepository
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function ensureTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS system_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            checksum VARCHAR(64) NOT NULL,
            executed_at DATETIME NOT NULL,
            status ENUM('success', 'failed', 'rolled_back') NOT NULL DEFAULT 'success',
            error_message TEXT NULL,
            execution_time_ms INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $this->db->exec($sql);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function getAll(): array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM system_migrations ORDER BY executed_at ASC");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function hasExecuted(string $name): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM system_migrations WHERE migration_name = ? AND status = 'success' LIMIT 1");
            $stmt->execute([$name]);
            return $stmt->fetch() !== false;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function recordSuccess(string $name, string $checksum, int $executionTimeMs = 0): bool
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO system_migrations (migration_name, checksum, executed_at, status, execution_time_ms) VALUES (?, ?, NOW(), 'success', ?)");
            return $stmt->execute([$name, $checksum, $executionTimeMs]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function recordFailure(string $name, string $checksum, string $errorMessage): bool
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO system_migrations (migration_name, checksum, executed_at, status, error_message) VALUES (?, ?, NOW(), 'failed', ?)");
            return $stmt->execute([$name, $checksum, $errorMessage]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function markRolledBack(string $name): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE system_migrations SET status = 'rolled_back' WHERE migration_name = ?");
            return $stmt->execute([$name]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function verifyChecksum(string $name, string $checksum): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT checksum FROM system_migrations WHERE migration_name = ? AND status = 'success' LIMIT 1");
            $stmt->execute([$name]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? hash_equals($row['checksum'], $checksum) : false;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function getPending(array $availableMigrations): array
    {
        $pending = [];
        foreach ($availableMigrations as $migration) {
            $name = is_array($migration) ? $migration['name'] : $migration;
            if (!$this->hasExecuted($name)) {
                $pending[] = $migration;
            }
        }
        return $pending;
    }

    public function getStats(): array
    {
        try {
            $stmt = $this->db->query("SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'rolled_back' THEN 1 ELSE 0 END) as rolled_back
            FROM system_migrations");
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return ['total' => 0, 'successful' => 0, 'failed' => 0, 'rolled_back' => 0];
        }
    }

    public function clearAll(): bool
    {
        try {
            $this->db->exec("TRUNCATE TABLE system_migrations");
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }
}
