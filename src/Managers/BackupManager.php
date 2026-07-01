<?php

declare(strict_types=1);

namespace Updater\Managers;

use Updater\Support\Config;
use Updater\Support\Logger;
use function Updater\Support\updater_normalize_path;
use function Updater\Support\updater_hash_file;
use function Updater\Support\updater_ensure_directory;
use function Updater\Support\updater_timestamp;

class BackupManager
{
    private Config $config;
    private Logger $logger;

    private string $backupPath;

    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
        $this->backupPath = $this->config->getStoragePath('backups');
        updater_ensure_directory($this->backupPath);
    }

    public function createBackup(array $files): ?string
    {
        if (!$this->config->get('backup_enabled', true)) {
            $this->logger->info('Backup disabled in config, skipping');
            return null;
        }

        $backupId = date('Y-m-d_H-i-s') . '_' . substr(uniqid('', true), 0, 8);
        $backupDir = $this->backupPath . '/' . $backupId;

        updater_ensure_directory($backupDir);

        $manifest = [
            'id'         => $backupId,
            'created_at' => updater_timestamp(),
            'files'      => [],
        ];

        foreach ($files as $file) {
            $path = is_array($file) ? $file['path'] : $file;
            $normalizedPath = updater_normalize_path($path);
            $fullPath = $this->config->getBasePath($normalizedPath);

            if (!file_exists($fullPath)) {
                $this->logger->debug("File not found for backup, skipping", ['path' => $normalizedPath]);
                $manifest['files'][] = [
                    'path'     => $normalizedPath,
                    'status'   => 'not_found',
                    'backed_up'=> false,
                ];
                continue;
            }

            $backupFilePath = $backupDir . '/' . str_replace('/', '__', $normalizedPath);
            $backupDirPath = dirname($backupFilePath);
            updater_ensure_directory($backupDirPath);

            $copied = copy($fullPath, $backupFilePath);

            if ($copied) {
                $manifest['files'][] = [
                    'path'      => $normalizedPath,
                    'hash'      => updater_hash_file($fullPath),
                    'size'      => filesize($fullPath),
                    'status'    => 'backed_up',
                    'backup_to' => str_replace($backupDir . '/', '', $backupFilePath),
                ];
            } else {
                $this->logger->error("Failed to backup file", ['path' => $normalizedPath]);
                $manifest['files'][] = [
                    'path'     => $normalizedPath,
                    'status'   => 'backup_failed',
                    'backed_up'=> false,
                ];
            }
        }

        file_put_contents(
            $backupDir . '/backup.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        $this->logger->info("Backup created", [
            'id'    => $backupId,
            'files' => count($manifest['files']),
        ]);

        $this->pruneOldBackups();

        return $backupId;
    }

    public function restoreBackup(string $backupId): bool
    {
        $backupDir = $this->backupPath . '/' . $backupId;
        $manifestFile = $backupDir . '/backup.json';

        if (!file_exists($manifestFile)) {
            $this->logger->error("Backup manifest not found", ['id' => $backupId]);
            return false;
        }

        $manifest = json_decode(
            file_get_contents($manifestFile) ?: '{}',
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $restored = 0;
        $failed = 0;

        foreach ($manifest['files'] as $entry) {
            if (($entry['status'] ?? '') !== 'backed_up') {
                continue;
            }

            $backupFilePath = $backupDir . '/' . $entry['backup_to'];
            $originalPath = $this->config->getBasePath($entry['path']);

            if (!file_exists($backupFilePath)) {
                $this->logger->error("Backup file missing", ['path' => $backupFilePath]);
                $failed++;
                continue;
            }

            $dir = dirname($originalPath);
            if (!is_dir($dir)) {
                updater_ensure_directory($dir);
            }

            $copied = copy($backupFilePath, $originalPath);

            if ($copied) {
                $restored++;
            } else {
                $this->logger->error("Failed to restore file", ['path' => $entry['path']]);
                $failed++;
            }
        }

        $this->logger->info("Backup restore completed", [
            'id'       => $backupId,
            'restored' => $restored,
            'failed'   => $failed,
        ]);

        return $failed === 0;
    }

    public function getBackups(): array
    {
        $backups = [];
        $dirs = glob($this->backupPath . '/*', GLOB_ONLYDIR);

        if ($dirs === false) {
            return [];
        }

        rsort($dirs);

        foreach ($dirs as $dir) {
            $manifestFile = $dir . '/backup.json';
            if (file_exists($manifestFile)) {
                $manifest = json_decode(
                    file_get_contents($manifestFile) ?: '{}',
                    true
                );
                $backups[] = $manifest;
            }
        }

        return $backups;
    }

    public function getLatestBackup(): ?array
    {
        $backups = $this->getBackups();
        return $backups[0] ?? null;
    }

    public function restoreLatest(): bool
    {
        $latest = $this->getLatestBackup();

        if ($latest === null) {
            $this->logger->warning('No backups found for restore');
            return false;
        }

        return $this->restoreBackup($latest['id']);
    }

    public function deleteBackup(string $backupId): bool
    {
        $backupDir = $this->backupPath . '/' . $backupId;

        if (!is_dir($backupDir)) {
            return false;
        }

        $files = glob($backupDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        $deleted = rmdir($backupDir);

        if ($deleted) {
            $this->logger->info("Backup deleted", ['id' => $backupId]);
        }

        return $deleted;
    }

    public function getBackupCount(): int
    {
        $dirs = glob($this->backupPath . '/*', GLOB_ONLYDIR);
        return is_array($dirs) ? count($dirs) : 0;
    }

    private function pruneOldBackups(): void
    {
        $maxBackups = (int) $this->config->get('backup_max', 10);
        $backups = $this->getBackups();

        if (count($backups) <= $maxBackups) {
            return;
        }

        $toDelete = array_slice($backups, $maxBackups);

        foreach ($toDelete as $backup) {
            if (isset($backup['id'])) {
                $this->deleteBackup($backup['id']);
                $this->logger->info("Old backup pruned", ['id' => $backup['id']]);
            }
        }
    }
}
