<?php

declare(strict_types=1);

namespace Updater\Core;

use Updater\Support\Config;
use Updater\Support\Logger;
use Updater\Managers\BackupManager;
use Updater\Managers\MigrationManager;
use Updater\Managers\VersionManager;
use function Updater\Support\updater_timestamp;

class Rollback
{
    private Config $config;
    private Logger $logger;
    private BackupManager $backupManager;
    private MigrationManager $migrationManager;
    private VersionManager $versionManager;

    public function __construct(
        ?Config $config = null,
        ?Logger $logger = null
    ) {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
        $this->backupManager = new BackupManager($this->config, $this->logger);
        $this->migrationManager = new MigrationManager($this->config, $this->logger);
        $this->versionManager = new VersionManager($this->config, $this->logger);
    }

    public function rollback(?string $backupId = null, ?callable $connectionFactory = null): bool
    {
        $this->logger->info('Starting rollback process...');

        $backup = $backupId !== null
            ? $this->findBackup($backupId)
            : $this->backupManager->getLatestBackup();

        if ($backup === null) {
            $this->logger->error('No backup found for rollback', ['id' => $backupId]);
            return false;
        }

        $this->logger->info("Rolling back to backup", ['id' => $backup['id'] ?? 'unknown']);

        try {
            $this->restoreFiles($backup);

            if ($connectionFactory !== null) {
                $this->rollbackMigrations($backup, $connectionFactory);
            }

            $this->restoreVersion($backup);

            $this->logger->success("Rollback completed successfully", ['id' => $backup['id'] ?? 'unknown']);
            return true;

        } catch (\Exception $e) {
            $this->logger->error("Rollback failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function rollbackToVersion(string $version, ?callable $connectionFactory = null): bool
    {
        $this->logger->info("Rolling back to version: {$version}");

        $backups = $this->backupManager->getBackups();
        $targetBackup = null;

        foreach ($backups as $backup) {
            if (isset($backup['version_info']['version']) && $backup['version_info']['version'] === $version) {
                $targetBackup = $backup;
                break;
            }
        }

        if ($targetBackup === null) {
            $this->logger->error("No backup found for version", ['version' => $version]);
            return false;
        }

        return $this->rollback($targetBackup['id'] ?? null, $connectionFactory);
    }

    public function undoLastUpdate(?callable $connectionFactory = null): bool
    {
        $this->logger->info('Undoing last update...');
        return $this->rollback(null, $connectionFactory);
    }

    public function getAvailableRollbacks(): array
    {
        return $this->backupManager->getBackups();
    }

    public function canRollback(): bool
    {
        return $this->backupManager->getLatestBackup() !== null;
    }

    private function restoreFiles(array $backup): void
    {
        $this->logger->info('Restoring files from backup...');

        $restored = $this->backupManager->restoreBackup($backup['id']);

        if (!$restored) {
            throw new \RuntimeException('Failed to restore files from backup');
        }

        $this->logger->info('Files restored successfully');
    }

    private function rollbackMigrations(array $backup, callable $connectionFactory): void
    {
        if (!isset($backup['migrations']) || empty($backup['migrations'])) {
            $this->logger->info('No migrations to rollback');
            return;
        }

        $this->logger->info('Rolling back migrations...');

        foreach (array_reverse($backup['migrations']) as $migration) {
            $migrationName = is_array($migration) ? ($migration['name'] ?? '') : $migration;
            if ($migrationName === '') {
                continue;
            }
            $this->migrationManager->rollbackMigration($migrationName, $connectionFactory);
        }
    }

    private function restoreVersion(array $backup): void
    {
        $previousVersion = $backup['version_info']['previous'] ?? null;

        if ($previousVersion !== null) {
            $this->versionManager->setLocalVersion($previousVersion, [
                'rollback_from' => $backup['version_info']['version'] ?? null,
                'rollback_at'   => updater_timestamp(),
            ]);
            $this->logger->info("Version restored to {$previousVersion}");
        } else {
            $this->logger->warning('No previous version information in backup');
        }
    }

    private function findBackup(string $backupId): ?array
    {
        $backups = $this->backupManager->getBackups();

        foreach ($backups as $backup) {
            if (($backup['id'] ?? '') === $backupId) {
                return $backup;
            }
        }

        return null;
    }
}
