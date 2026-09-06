<?php

declare(strict_types=1);

namespace UpdateCore\Support;

class Logger
{
    private string $logFile;
    private ?\PDO $db;
    private string $jobId;
    private bool $dbLogging;
    private Config $config;

    public function __construct(string $jobId, ?\PDO $db = null, ?Config $config = null, bool $dbLogging = true)
    {
        $this->config = $config ?? Config::make();
        $this->db = $db;
        $this->jobId = $jobId;
        $this->dbLogging = $dbLogging && $db !== null;

        $logDir = $this->config->getLogPath();
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/update-' . date('Y-m-d') . '.log';
    }

    public function log(string $message, string $level = 'info', ?string $step = null): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] [{$this->jobId}] {$message}";

        file_put_contents($this->logFile, $logEntry . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($this->dbLogging && $this->db !== null) {
            try {
                $this->ensureLogTable();
                $stmt = $this->db->prepare("INSERT INTO update_logs (job_id, level, message, step, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$this->jobId, $level, $message, $step]);
            } catch (\PDOException $e) {
                error_log("UpdateCore DB logging failed: " . $e->getMessage());
            }
        }
    }

    public function info(string $message, ?string $step = null): void
    {
        $this->log($message, 'info', $step);
    }

    public function warning(string $message, ?string $step = null): void
    {
        $this->log($message, 'warning', $step);
    }

    public function error(string $message, ?string $step = null): void
    {
        $this->log($message, 'error', $step);
    }

    public function success(string $message, ?string $step = null): void
    {
        $this->log($message, 'success', $step);
    }

    public function debug(string $message, ?string $step = null): void
    {
        $this->log($message, 'debug', $step);
    }

    public function getJobLogs(int $limit = 100): array
    {
        if (!$this->dbLogging || $this->db === null) {
            return [];
        }
        try {
            $limit = max(1, min(1000, $limit));
            $stmt = $this->db->prepare("SELECT * FROM update_logs WHERE job_id = ? ORDER BY created_at DESC LIMIT {$limit}");
            $stmt->execute([$this->jobId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function cleanOldLogs(int $keepDays = 30): int
    {
        $deleted = 0;

        if ($this->dbLogging && $this->db !== null) {
            try {
                $stmt = $this->db->prepare("DELETE FROM update_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$keepDays]);
                $deleted += $stmt->rowCount();
            } catch (\PDOException $e) {
                // silent
            }
        }

        $logDir = $this->config->getLogPath();
        if (is_dir($logDir)) {
            $files = glob($logDir . '/update-*.log');
            $threshold = strtotime("-{$keepDays} days");
            foreach ($files as $file) {
                if (filemtime($file) < $threshold) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    public function cleanAllLogs(): int
    {
        $deleted = 0;

        if ($this->dbLogging && $this->db !== null) {
            try {
                $stmt = $this->db->query("DELETE FROM update_logs");
                $deleted += $stmt->rowCount();
            } catch (\PDOException $e) {
                // silent
            }
        }

        $logDir = $this->config->getLogPath();
        if (is_dir($logDir)) {
            $files = glob($logDir . '/update-*.log');
            foreach ($files as $file) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        $statusDir = $this->config->getStatusPath();
        if (is_dir($statusDir)) {
            $files = glob($statusDir . '/*.json');
            foreach ($files as $file) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    public function getStats(): array
    {
        if (!$this->dbLogging || $this->db === null) {
            return ['total_logs' => 0, 'by_job' => [], 'by_date' => [], 'oldest_log' => null];
        }

        try {
            $stats = [];
            $stmt = $this->db->query("SELECT COUNT(*) as total FROM update_logs");
            $stats['total_logs'] = $stmt->fetchColumn();

            $stmt = $this->db->query("SELECT job_id, COUNT(*) as count FROM update_logs GROUP BY job_id ORDER BY count DESC LIMIT 10");
            $stats['by_job'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt = $this->db->query("SELECT DATE(created_at) as date, COUNT(*) as count FROM update_logs GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30");
            $stats['by_date'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $stmt = $this->db->query("SELECT MIN(created_at) as oldest FROM update_logs");
            $stats['oldest_log'] = $stmt->fetchColumn();

            return $stats;
        } catch (\PDOException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function ensureLogTable(): void
    {
        static $ensured = false;
        if ($ensured || $this->db === null) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS update_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(64) NOT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NOT NULL,
            step VARCHAR(50) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_job_id (job_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $this->db->exec($sql);
            $ensured = true;
        } catch (\PDOException $e) {
            // silent
        }
    }
}
