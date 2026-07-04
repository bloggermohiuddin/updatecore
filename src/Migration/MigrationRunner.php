<?php

declare(strict_types=1);

namespace UpdateCore\Migration;

use UpdateCore\Support\Config;
use UpdateCore\Support\Logger;

class MigrationRunner
{
    private \PDO $db;
    private MigrationRepository $repository;
    private Logger $logger;
    private Config $config;

    public function __construct(\PDO $db, ?MigrationRepository $repository = null, ?Logger $logger = null, ?Config $config = null)
    {
        $this->db = $db;
        $this->config = $config ?? Config::make();
        $this->repository = $repository ?? new MigrationRepository($db);
        $this->logger = $logger ?? new Logger('migration');
    }

    public function runAll(): array
    {
        $results = ['executed' => [], 'failed' => [], 'skipped' => []];

        $this->repository->ensureTable();

        $available = $this->getAvailableMigrations();
        if (empty($available)) {
            $this->logger->info('No migrations found', 'migration');
            return $results;
        }

        $pending = $this->repository->getPending($available);
        if (empty($pending)) {
            $this->logger->info('All migrations up to date', 'migration');
            return $results;
        }

        $this->logger->info('Running ' . count($pending) . ' pending migrations', 'migration');

        foreach ($pending as $migration) {
            $name = is_array($migration) ? $migration['name'] : $migration;
            $path = is_array($migration) ? $migration['path'] : ($this->config->get('migrations_path', $this->config->getProjectRoot() . '/database/migrations') . '/' . $migration);

            $result = $this->runSingle($name, $path);

            if ($result['success']) {
                $results['executed'][] = $name;
            } else {
                $results['failed'][] = ['name' => $name, 'error' => $result['error']];
                $this->logger->error("Migration failed: {$name}", 'migration');
                break;
            }
        }

        return $results;
    }

    public function runSingle(string $name, string $path): array
    {
        $startTime = microtime(true);

        if (!file_exists($path)) {
            return ['success' => false, 'error' => "File not found: {$path}"];
        }

        $checksum = hash_file('sha256', $path);

        if ($this->repository->hasExecuted($name)) {
            if ($this->repository->verifyChecksum($name, $checksum)) {
                return ['success' => true, 'skipped' => true];
            }
        }

        try {
            $ext = pathinfo($path, PATHINFO_EXTENSION);

            if ($ext === 'sql') {
                $this->runSqlMigration($path);
            } elseif ($ext === 'php') {
                $this->runPhpMigration($path);
            } else {
                throw new \RuntimeException("Unsupported migration type: {$ext}");
            }

            $executionTime = (int) ((microtime(true) - $startTime) * 1000);
            $this->repository->recordSuccess($name, $checksum, $executionTime);
            $this->logger->success("Migration completed: {$name} ({$executionTime}ms)", 'migration');

            return ['success' => true];
        } catch (\Exception $e) {
            $this->repository->recordFailure($name, $checksum, $e->getMessage());
            $this->logger->error("Migration failed: {$name} - " . $e->getMessage(), 'migration');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getAvailableMigrations(): array
    {
        $migrationsPath = $this->config->get('migrations_path', $this->config->getProjectRoot() . '/database/migrations');
        if (!is_dir($migrationsPath)) {
            return [];
        }

        $files = glob($migrationsPath . '/*.{sql,php}', GLOB_BRACE);
        sort($files);

        return array_map(fn($file) => [
            'name' => basename($file),
            'path' => $file,
            'type' => pathinfo($file, PATHINFO_EXTENSION),
        ], $files);
    }

    public function getStatus(): array
    {
        $available = $this->getAvailableMigrations();
        $executed = $this->repository->getAll();

        return [
            'available' => count($available),
            'executed'  => count($executed),
            'pending'   => count($this->repository->getPending($available)),
            'stats'     => $this->repository->getStats(),
        ];
    }

    private function runSqlMigration(string $path): void
    {
        $sql = file_get_contents($path);
        if (empty($sql)) {
            throw new \RuntimeException("Empty SQL migration");
        }

        $statements = $this->splitSqlStatements($sql);

        $this->db->beginTransaction();
        try {
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $this->db->exec($statement);
                }
            }
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new \RuntimeException("SQL failed: " . $e->getMessage());
        }
    }

    private function runPhpMigration(string $path): void
    {
        $migration = require $path;

        if (!is_object($migration)) {
            throw new \RuntimeException("PHP migration must return an object");
        }

        if (!method_exists($migration, 'up')) {
            throw new \RuntimeException("PHP migration must have an up() method");
        }

        $this->db->beginTransaction();
        try {
            $migration->up($this->db);
            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            // Auto-rollback using down() if available
            if (method_exists($migration, 'down')) {
                try {
                    $this->db->beginTransaction();
                    $migration->down($this->db);
                    if ($this->db->inTransaction()) {
                        $this->db->commit();
                    }
                    $this->logger->info("Migration auto-rolled back via down()", 'migration');
                } catch (\Exception $rollbackEx) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->logger->error("down() rollback also failed: " . $rollbackEx->getMessage(), 'migration');
                }
            }

            throw new \RuntimeException("PHP migration failed: " . $e->getMessage());
        }
    }

    private function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        $statements = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';

        for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
            $char = $sql[$i];

            if (!$inQuote && ($char === '"' || $char === "'" || $char === '`')) {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($inQuote && $char === $quoteChar) {
                $inQuote = false;
            } elseif (!$inQuote && $char === ';') {
                $statements[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (!empty(trim($current))) {
            $statements[] = $current;
        }

        return $statements;
    }
}
