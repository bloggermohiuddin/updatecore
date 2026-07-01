<?php

declare(strict_types=1);

namespace Updater\Managers;

use Updater\Support\Config;
use Updater\Support\Logger;
use function Updater\Support\updater_ensure_directory;

class MigrationManager
{
    private Config $config;
    private Logger $logger;

    private string $migrationPath;

    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
        $this->migrationPath = $this->config->getStoragePath('migrations');
        updater_ensure_directory($this->migrationPath);
    }

    public function runMigrations(array $migrationFiles, callable $connectionFactory): array
    {
        if (empty($migrationFiles)) {
            $this->logger->info('No migrations to run');
            return ['executed' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $results = [
            'executed' => 0,
            'skipped'  => 0,
            'failed'   => 0,
            'details'  => [],
        ];

        $executed = $this->getExecutedMigrations();

        foreach ($migrationFiles as $migration) {
            $migrationName = is_array($migration) ? ($migration['name'] ?? $migration['file'] ?? '') : $migration;

            if ($migrationName === '') {
                continue;
            }

            if (in_array($migrationName, $executed, true)) {
                $this->logger->debug("Migration already executed, skipping", ['migration' => $migrationName]);
                $results['skipped']++;
                $results['details'][] = [
                    'name'   => $migrationName,
                    'status' => 'skipped',
                ];
                continue;
            }

            $success = $this->executeMigration($migrationName, $connectionFactory);

            if ($success) {
                $this->markMigrationExecuted($migrationName);
                $results['executed']++;
                $results['details'][] = [
                    'name'   => $migrationName,
                    'status' => 'executed',
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'name'   => $migrationName,
                    'status' => 'failed',
                ];
                break;
            }
        }

        $this->logger->info("Migrations completed", [
            'executed' => $results['executed'],
            'skipped'  => $results['skipped'],
            'failed'   => $results['failed'],
        ]);

        return $results;
    }

    public function runSqlMigration(string $sqlContent, callable $connectionFactory): bool
    {
        try {
            $pdo = $connectionFactory();

            if (!($pdo instanceof \PDO)) {
                $this->logger->error('Migration connection factory did not return PDO instance');
                return false;
            }

            $statements = $this->parseSqlStatements($sqlContent);
            $pdo->beginTransaction();

            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }

                try {
                    $pdo->exec($statement);
                } catch (\PDOException $e) {
                    $pdo->rollBack();
                    $this->logger->error("Migration SQL statement failed", [
                        'error'   => $e->getMessage(),
                        'partial' => substr($statement, 0, 100),
                    ]);
                    return false;
                }
            }

            $pdo->commit();
            return true;

        } catch (\Exception $e) {
            $this->logger->error("Migration execution failed", ['error' => $e->getMessage()]);
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    public function rollbackMigration(string $migrationName, callable $connectionFactory): bool
    {
        $rollbackFile = $this->getRollbackFile($migrationName);

        if (!file_exists($rollbackFile)) {
            $this->logger->warning("No rollback file found for migration", ['migration' => $migrationName]);
            return false;
        }

        $rollbackSql = file_get_contents($rollbackFile);
        if ($rollbackSql === false) {
            return false;
        }

        $success = $this->runSqlMigration($rollbackSql, $connectionFactory);

        if ($success) {
            $this->removeExecutedMigration($migrationName);
            $this->logger->info("Migration rolled back", ['migration' => $migrationName]);
        }

        return $success;
    }

    public function getExecutedMigrations(): array
    {
        $file = $this->migrationPath . '/executed.json';

        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file) ?: '[]', true);

        return is_array($data) ? $data : [];
    }

    public function markMigrationExecuted(string $migrationName): self
    {
        $executed = $this->getExecutedMigrations();

        if (!in_array($migrationName, $executed, true)) {
            $executed[] = $migrationName;
        }

        file_put_contents(
            $this->migrationPath . '/executed.json',
            json_encode($executed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        $this->logger->info("Migration marked as executed", ['migration' => $migrationName]);

        return $this;
    }

    public function removeExecutedMigration(string $migrationName): self
    {
        $executed = $this->getExecutedMigrations();
        $executed = array_values(array_filter($executed, fn($m) => $m !== $migrationName));

        file_put_contents(
            $this->migrationPath . '/executed.json',
            json_encode($executed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        return $this;
    }

    public function getMigrationStatus(): array
    {
        return [
            'executed' => $this->getExecutedMigrations(),
            'count'    => count($this->getExecutedMigrations()),
        ];
    }

    private function executeMigration(string $migrationName, callable $connectionFactory): bool
    {
        $migrationFile = $this->migrationPath . '/files/' . $migrationName;

        if (!file_exists($migrationFile)) {
            $this->logger->error("Migration file not found", ['file' => $migrationName]);
            return false;
        }

        $content = file_get_contents($migrationFile);
        if ($content === false) {
            $this->logger->error("Failed to read migration file", ['file' => $migrationName]);
            return false;
        }

        $extension = pathinfo($migrationName, PATHINFO_EXTENSION);

        if ($extension === 'sql') {
            return $this->runSqlMigration($content, $connectionFactory);
        }

        if ($extension === 'php') {
            return $this->runPhpMigration($migrationFile, $connectionFactory);
        }

        $this->logger->error("Unsupported migration type", ['file' => $migrationName]);
        return false;
    }

    private function runPhpMigration(string $migrationFile, callable $connectionFactory): bool
    {
        try {
            $pdo = $connectionFactory();

            require_once $migrationFile;

            $className = pathinfo($migrationFile, PATHINFO_FILENAME);

            if (class_exists($className)) {
                $migration = new $className();
                if (method_exists($migration, 'up')) {
                    $migration->up($pdo);
                }
            }

            return true;

        } catch (\Exception $e) {
            $this->logger->error("PHP migration failed", [
                'file'  => $migrationFile,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function parseSqlStatements(string $sql): array
    {
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        return array_values($statements);
    }

    private function getRollbackFile(string $migrationName): string
    {
        $baseName = pathinfo($migrationName, PATHINFO_FILENAME);
        return $this->migrationPath . '/rollback/' . $baseName . '_rollback.sql';
    }
}
