<?php

declare(strict_types=1);

namespace Updater\Manifest;

use Updater\Support\Config;
use Updater\Support\Logger;
use function Updater\Support\updater_normalize_path;
use function Updater\Support\updater_hash_file;

class ManifestParser
{
    private Config $config;
    private Logger $logger;

    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
    }

    public function compareFileTree(array $remoteTree, array $localHashes): array
    {
        $changed = [];
        $deleted = [];

        $remotePaths = [];

        foreach ($remoteTree as $remoteFile) {
            $path = updater_normalize_path($remoteFile['path']);
            $remotePaths[$path] = $remoteFile;
            $remoteHash = $remoteFile['sha'];

            $localHash = $localHashes[$path] ?? null;

            if ($localHash === null || $localHash !== $remoteHash) {
                $changed[] = [
                    'path' => $path,
                    'hash' => $remoteHash,
                    'size' => $remoteFile['size'] ?? 0,
                    'type' => $localHash === null ? 'new' : 'modified',
                ];
            }
        }

        foreach ($localHashes as $localPath => $localHash) {
            if (!isset($remotePaths[$localPath])) {
                $deleted[] = $localPath;
            }
        }

        $this->logger->info("File comparison complete", [
            'changed' => count($changed),
            'deleted' => count($deleted),
            'unchanged' => count($remoteTree) - count($changed),
        ]);

        return [
            'changed' => $changed,
            'deleted' => $deleted,
        ];
    }

    public function getLocalHashes(string $basePath, array $remoteTree): array
    {
        $hashes = [];

        foreach ($remoteTree as $remoteFile) {
            $path = updater_normalize_path($remoteFile['path']);
            $fullPath = $basePath . '/' . $path;

            if (file_exists($fullPath)) {
                $hashes[$path] = $this->getFileGitHash($fullPath);
            } else {
                $hashes[$path] = null;
            }
        }

        return $hashes;
    }

    public function getFileGitHash(string $filePath): string
    {
        if (!file_exists($filePath)) {
            return '';
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return '';
        }

        $size = filesize($filePath);
        $header = "blob {$size}\0";
        $store = $header . $content;

        return sha1($store);
    }

    public function verifyFileIntegrity(string $localPath, string $expectedGitHash): bool
    {
        if (!file_exists($localPath)) {
            return false;
        }

        $actualHash = $this->getFileGitHash($localPath);
        return $actualHash === $expectedGitHash;
    }

    public function verifyDownloadedContent(string $content, string $expectedGitHash, int $size): bool
    {
        $header = "blob {$size}\0";
        $store = $header . $content;
        $actualHash = sha1($store);

        return $actualHash === $expectedGitHash;
    }

    public function buildLocalTree(string $basePath, array $skipPatterns = []): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $item->getPathname());
            $relativePath = updater_normalize_path($relativePath);

            if ($this->shouldSkipPath($relativePath, $skipPatterns)) {
                continue;
            }

            $files[] = [
                'path' => $relativePath,
                'sha'  => $this->getFileGitHash($item->getPathname()),
                'size' => $item->getSize(),
            ];
        }

        return $files;
    }

    private function shouldSkipPath(string $path, array $extraPatterns = []): bool
    {
        $defaultPatterns = [
            '.git',
            '.github',
            '.gitignore',
            'vendor/',
            'node_modules/',
            'storage/logs/',
            'storage/cache/',
            'storage/backups/',
            'storage/migrations/',
            '.env',
            'composer.lock',
            'README.md',
            'LICENSE',
        ];

        $patterns = array_merge($defaultPatterns, $extraPatterns);

        foreach ($patterns as $pattern) {
            if (str_starts_with($path, $pattern) || str_contains($path, '/' . $pattern)) {
                return true;
            }
        }

        return false;
    }
}
