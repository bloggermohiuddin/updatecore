<?php

declare(strict_types=1);

namespace UpdateCore\Replace;

use UpdateCore\Support\Config;
use UpdateCore\Support\Logger;
use UpdateCore\Support\Helpers;

class ReplaceService
{
    private Config $config;
    private Logger $logger;
    private string $tempDir;

    public function __construct(?Logger $logger = null, ?Config $config = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger('replace');
        $this->tempDir = $this->config->getExtractPath() . '/' . uniqid();
    }

    public function extractZip(string $zipPath): bool
    {
        if (!file_exists($zipPath)) {
            throw new \RuntimeException('ZIP file not found: ' . $zipPath);
        }

        Helpers::ensureDir($this->tempDir);

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new \RuntimeException('Failed to open ZIP file: error code ' . $result);
        }

        if (!$zip->extractTo($this->tempDir)) {
            $zip->close();
            throw new \RuntimeException('Failed to extract ZIP file');
        }

        $zip->close();
        $this->logger->info('ZIP extracted to: ' . $this->tempDir, 'extract');
        return true;
    }

    public function getExtractedPath(): string
    {
        $dirs = glob($this->tempDir . '/*', GLOB_ONLYDIR);
        return count($dirs) === 1 ? $dirs[0] : $this->tempDir;
    }

    public function replaceFiles(array $changedFiles, string $sourceDir): array
    {
        $results = ['replaced' => [], 'added' => [], 'skipped' => [], 'failed' => []];

        foreach ($changedFiles as $file) {
            $filePath = $file['path'] ?? $file;

            if ($this->config->isExcluded($filePath) || $this->config->isPreserved($filePath)) {
                $results['skipped'][] = $filePath;
                continue;
            }

            $sourcePath = $sourceDir . '/' . $filePath;
            $targetPath = $this->config->getProjectRoot() . '/' . $filePath;

            if (!file_exists($sourcePath)) {
                $results['skipped'][] = $filePath;
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (copy($sourcePath, $targetPath)) {
                $status = $file['status'] ?? '';
                $results[$status === 'added' ? 'added' : 'replaced'][] = $filePath;
                $this->logger->info("Replaced: {$filePath}", 'replace');
            } else {
                $results['failed'][] = $filePath;
                $this->logger->error("Failed to replace: {$filePath}", 'replace');
            }
        }

        return $results;
    }

    public function deleteRemovedFiles(array $removedFiles): array
    {
        $results = ['deleted' => [], 'skipped' => [], 'failed' => []];

        foreach ($removedFiles as $file) {
            $filePath = is_array($file) ? ($file['path'] ?? '') : $file;

            if (empty($filePath) || $this->config->isExcluded($filePath) || $this->config->isPreserved($filePath)) {
                $results['skipped'][] = $filePath;
                continue;
            }

            $targetPath = $this->config->getProjectRoot() . '/' . $filePath;

            if (!file_exists($targetPath)) {
                $results['skipped'][] = $filePath;
                continue;
            }

            $deleted = is_dir($targetPath)
                ? Helpers::recursiveDelete($targetPath)
                : unlink($targetPath);

            if ($deleted) {
                $results['deleted'][] = $filePath;
                $this->logger->info("Deleted: {$filePath}", 'delete');
            } else {
                $results['failed'][] = $filePath;
                $this->logger->error("Failed to delete: {$filePath}", 'delete');
            }
        }

        return $results;
    }

    public function verifyIntegrity(array $files): bool
    {
        foreach ($files as $file) {
            $filePath = is_array($file) ? $file['path'] : $file;
            $targetPath = $this->config->getProjectRoot() . '/' . $filePath;

            if (!file_exists($targetPath)) {
                $this->logger->error("Missing file: {$filePath}", 'verify');
                return false;
            }

            if (isset($file['hash'])) {
                $actualHash = hash_file('sha256', $targetPath);
                if ($actualHash !== $file['hash']) {
                    $this->logger->error("Hash mismatch: {$filePath}", 'verify');
                    return false;
                }
            }
        }

        return true;
    }

    public function cleanup(): bool
    {
        if (is_dir($this->tempDir)) {
            return Helpers::recursiveDelete($this->tempDir);
        }
        return true;
    }

    public function isExcluded(string $path): bool
    {
        return $this->config->isExcluded($path);
    }

    public function isPreserved(string $path): bool
    {
        return $this->config->isPreserved($path);
    }
}
