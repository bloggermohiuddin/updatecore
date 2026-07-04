<?php

declare(strict_types=1);

namespace UpdateCore\Backup;

use UpdateCore\Support\Config;
use UpdateCore\Support\Helpers;

class BackupService
{
    private Config $config;
    private string $jobId;
    private string $backupDir;

    public function __construct(string $jobId, ?Config $config = null)
    {
        $this->config = $config ?? Config::make();
        $this->jobId = $jobId;
        $this->backupDir = $this->config->getBackupPath() . '/' . $jobId;

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    public function createBackup(array $changedFiles = []): array
    {
        $backupData = [
            'job_id'      => $this->jobId,
            'created_at'  => date('Y-m-d H:i:s'),
            'files'       => [],
            'total_size'  => 0,
            'total_files' => 0,
        ];

        $filesToBackup = empty($changedFiles) ? $this->getAllProjectFiles() : $changedFiles;

        foreach ($filesToBackup as $file) {
            $filePath = is_array($file) ? ($file['path'] ?? '') : $file;
            if (empty($filePath) || $this->config->isExcluded($filePath)) {
                continue;
            }

            $sourcePath = $this->config->getProjectRoot() . '/' . $filePath;
            if (!file_exists($sourcePath)) {
                continue;
            }

            $backupPath = $this->backupDir . '/files/' . $filePath;
            $dir = dirname($backupPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            if (copy($sourcePath, $backupPath)) {
                $size = filesize($sourcePath);
                $backupData['files'][] = [
                    'path'     => $filePath,
                    'size'     => $size,
                    'hash'     => hash_file('sha256', $sourcePath),
                    'modified' => filemtime($sourcePath),
                ];
                $backupData['total_size'] += $size;
                $backupData['total_files']++;
            }
        }

        file_put_contents(
            $this->backupDir . '/manifest.json',
            json_encode($backupData, JSON_PRETTY_PRINT)
        );

        $metadata = [
            'job_id'       => $this->jobId,
            'created_at'   => $backupData['created_at'],
            'from_version' => $this->getVersion('version'),
            'from_commit'  => $this->getVersion('commit'),
            'total_files'  => $backupData['total_files'],
            'total_size'   => $backupData['total_size'],
            'php_version'  => PHP_VERSION,
        ];
        file_put_contents(
            $this->backupDir . '/metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT)
        );

        return $backupData;
    }

    public function restore(): bool
    {
        $manifestPath = $this->backupDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            throw new \RuntimeException('Backup manifest not found');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest || !isset($manifest['files'])) {
            throw new \RuntimeException('Invalid backup manifest');
        }

        foreach ($manifest['files'] as $file) {
            $backupPath = $this->backupDir . '/files/' . $file['path'];
            $targetPath = $this->config->getProjectRoot() . '/' . $file['path'];

            if (!file_exists($backupPath)) {
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (!copy($backupPath, $targetPath)) {
                throw new \RuntimeException("Failed to restore file: {$file['path']}");
            }

            touch($targetPath, $file['modified']);
        }

        return true;
    }

    public function verify(): bool
    {
        $manifestPath = $this->backupDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            return false;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest || !isset($manifest['files'])) {
            return false;
        }

        foreach ($manifest['files'] as $file) {
            $backupPath = $this->backupDir . '/files/' . $file['path'];
            if (!file_exists($backupPath)) {
                return false;
            }
            if (hash_file('sha256', $backupPath) !== $file['hash']) {
                return false;
            }
        }

        return true;
    }

    public function delete(): bool
    {
        if (!is_dir($this->backupDir)) {
            return true;
        }
        return Helpers::recursiveDelete($this->backupDir);
    }

    public function getManifest(): ?array
    {
        $path = $this->backupDir . '/manifest.json';
        return file_exists($path) ? json_decode(file_get_contents($path), true) : null;
    }

    public function getMetadata(): ?array
    {
        $path = $this->backupDir . '/metadata.json';
        return file_exists($path) ? json_decode(file_get_contents($path), true) : null;
    }

    public static function listBackups(?Config $config = null): array
    {
        $config = $config ?? Config::make();
        $backupsDir = $config->getBackupPath();

        if (!is_dir($backupsDir)) {
            return [];
        }

        $backups = [];
        $dirs = glob($backupsDir . '/*', GLOB_ONLYDIR);

        foreach ($dirs as $dir) {
            $metadataPath = $dir . '/metadata.json';
            if (file_exists($metadataPath)) {
                $metadata = json_decode(file_get_contents($metadataPath), true);
                if ($metadata) {
                    $backups[] = $metadata;
                }
            }
        }

        usort($backups, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
        return $backups;
    }

    public static function pruneOld(int $keep = 10, ?Config $config = null): int
    {
        $config = $config ?? Config::make();
        $backups = self::listBackups($config);
        $deleted = 0;

        if (count($backups) > $keep) {
            $toDelete = array_slice($backups, $keep);
            foreach ($toDelete as $backup) {
                $backupDir = $config->getBackupPath() . '/' . $backup['job_id'];
                if (is_dir($backupDir)) {
                    Helpers::recursiveDelete($backupDir);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function getAllProjectFiles(): array
    {
        $files = [];
        $projectRoot = realpath($this->config->getProjectRoot());

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $path = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $path = str_replace('\\', '/', $path);
            if (!$this->config->isExcluded($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function getVersion(string $key): string
    {
        $versionFile = $this->config->getProjectRoot() . '/version.json';
        if (!file_exists($versionFile)) {
            return $key === 'version' ? '0.0.0' : 'unknown';
        }
        $data = json_decode(file_get_contents($versionFile), true);
        return $data[$key] ?? ($key === 'version' ? '0.0.0' : 'unknown');
    }
}
