<?php

declare(strict_types=1);

namespace Updater\Managers;

use Updater\Support\Config;
use Updater\Support\Logger;
use function Updater\Support\updater_normalize_path;
use function Updater\Support\updater_hash_file;
use function Updater\Support\updater_hash_content;
use function Updater\Support\updater_ensure_directory;

class FileManager
{
    private Config $config;
    private Logger $logger;

    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
    }

    public function getLocalHashes(array $filePaths): array
    {
        $hashes = [];

        foreach ($filePaths as $path) {
            $fullPath = $this->config->getBasePath($path);

            if (file_exists($fullPath)) {
                $hashes[updater_normalize_path($path)] = updater_hash_file($fullPath);
            } else {
                $hashes[updater_normalize_path($path)] = null;
            }
        }

        return $hashes;
    }

    public function getLocalHashesFromManifest(array $manifestFiles): array
    {
        $hashes = [];

        foreach ($manifestFiles as $file) {
            $path = updater_normalize_path($file['path']);
            $fullPath = $this->config->getBasePath($path);

            if (file_exists($fullPath)) {
                $hashes[$path] = updater_hash_file($fullPath);
            } else {
                $hashes[$path] = null;
            }
        }

        return $hashes;
    }

    public function verifyFileIntegrity(string $filePath, string $expectedHash): bool
    {
        if (!file_exists($filePath)) {
            $this->logger->warning("File does not exist for integrity check", ['path' => $filePath]);
            return false;
        }

        $actualHash = updater_hash_file($filePath);
        $isValid = $actualHash === $expectedHash;

        if (!$isValid) {
            $this->logger->warning("File integrity check failed", [
                'path'     => $filePath,
                'expected' => $expectedHash,
                'actual'   => $actualHash,
            ]);
        }

        return $isValid;
    }

    public function verifyDownloadedContent(string $content, string $expectedHash): bool
    {
        $actualHash = updater_hash_content($content);
        return $actualHash === $expectedHash;
    }

    public function writeFile(string $path, string $content, bool $verify = true, ?string $expectedHash = null): bool
    {
        if ($verify && $expectedHash !== null) {
            if (!$this->verifyDownloadedContent($content, $expectedHash)) {
                $this->logger->error("Downloaded content verification failed, refusing to write", [
                    'path' => $path,
                ]);
                return false;
            }
        }

        $fullPath = $this->config->getBasePath($path);
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            updater_ensure_directory($dir);
            $this->logger->debug("Created directory", ['dir' => $dir]);
        }

        $tempPath = $fullPath . '.tmp.' . uniqid('', true);
        $written = file_put_contents($tempPath, $content, LOCK_EX);

        if ($written === false) {
            $this->logger->error("Failed to write file", ['path' => $fullPath]);
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            return false;
        }

        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        rename($tempPath, $fullPath);
        chmod($fullPath, 0644);

        $this->logger->info("File written successfully", [
            'path' => $path,
            'size' => $written,
        ]);

        return true;
    }

    public function deleteFile(string $path): bool
    {
        $fullPath = $this->config->getBasePath($path);

        if (!file_exists($fullPath)) {
            $this->logger->debug("File already absent, skipping deletion", ['path' => $path]);
            return true;
        }

        if (!is_writable($fullPath)) {
            $this->logger->error("File not writable, cannot delete", ['path' => $path]);
            return false;
        }

        $deleted = unlink($fullPath);

        if ($deleted) {
            $this->logger->info("File deleted", ['path' => $path]);
        } else {
            $this->logger->error("Failed to delete file", ['path' => $path]);
        }

        return $deleted;
    }

    public function createDirectories(array $paths): self
    {
        foreach ($paths as $path) {
            $fullPath = $this->config->getBasePath($path);
            updater_ensure_directory($fullPath);
        }
        return $this;
    }

    public function getDirectoriesForFiles(array $files): array
    {
        $dirs = [];

        foreach ($files as $file) {
            $path = is_array($file) ? $file['path'] : $file;
            $dir = dirname(updater_normalize_path($path));
            if ($dir !== '.' && !in_array($dir, $dirs, true)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    public function getManifestFiles(string $basePath): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $item->getPathname());
                $relativePath = updater_normalize_path($relativePath);
                $files[] = [
                    'path' => $relativePath,
                    'hash' => updater_hash_file($item->getPathname()),
                    'size' => $item->getSize(),
                ];
            }
        }

        return $files;
    }

    public function calculateTotalSize(array $files): int
    {
        $total = 0;
        foreach ($files as $file) {
            $total += $file['size'] ?? 0;
        }
        return $total;
    }
}
