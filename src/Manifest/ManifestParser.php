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

    private ?array $manifest = null;

    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
    }

    public function parse(string $jsonContent): array
    {
        $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

        $this->validateManifest($data);
        $this->manifest = $data;

        $this->logger->info('Manifest parsed successfully', [
            'version' => $data['version'],
            'channel' => $data['channel'] ?? 'stable',
            'files'   => count($data['files'] ?? []),
            'deleted' => count($data['deleted'] ?? []),
        ]);

        return $this->manifest;
    }

    public function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Manifest file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read manifest file: {$filePath}");
        }

        return $this->parse($content);
    }

    public function getVersion(): ?string
    {
        return $this->manifest['version'] ?? null;
    }

    public function getChannel(): ?string
    {
        return $this->manifest['channel'] ?? null;
    }

    public function getReleaseDate(): ?string
    {
        return $this->manifest['release_date'] ?? null;
    }

    public function getFiles(): array
    {
        return $this->manifest['files'] ?? [];
    }

    public function getDeleted(): array
    {
        return $this->manifest['deleted'] ?? [];
    }

    public function getMigrations(): array
    {
        return $this->manifest['migrations'] ?? [];
    }

    public function getSignature(): ?string
    {
        return $this->manifest['signature'] ?? null;
    }

    public function getFileByPath(string $path): ?array
    {
        $path = updater_normalize_path($path);

        foreach ($this->getFiles() as $file) {
            if (updater_normalize_path($file['path']) === $path) {
                return $file;
            }
        }

        return null;
    }

    public function getChangedFiles(array $localHashes): array
    {
        $changed = [];

        foreach ($this->getFiles() as $file) {
            $remotePath = updater_normalize_path($file['path']);
            $remoteHash = $file['hash'];

            $localHash = $localHashes[$remotePath] ?? null;

            if ($localHash === null || $localHash !== $remoteHash) {
                $changed[] = $file;
            }
        }

        return $changed;
    }

    public function buildFileHashes(string $basePath): array
    {
        $hashes = [];

        foreach ($this->getFiles() as $file) {
            $fullPath = $basePath . '/' . updater_normalize_path($file['path']);
            if (file_exists($fullPath)) {
                $hashes[updater_normalize_path($file['path'])] = updater_hash_file($fullPath);
            } else {
                $hashes[updater_normalize_path($file['path'])] = null;
            }
        }

        return $hashes;
    }

    public function verifyIntegrity(string $basePath): array
    {
        $issues = [];

        foreach ($this->getFiles() as $file) {
            $remotePath = updater_normalize_path($file['path']);
            $fullPath = $basePath . '/' . $remotePath;

            if (!file_exists($fullPath)) {
                $issues[] = [
                    'path'   => $remotePath,
                    'reason' => 'file_missing',
                ];
                continue;
            }

            $currentHash = updater_hash_file($fullPath);
            if ($currentHash !== $file['hash']) {
                $issues[] = [
                    'path'      => $remotePath,
                    'reason'    => 'hash_mismatch',
                    'expected'  => $file['hash'],
                    'actual'    => $currentHash,
                ];
            }
        }

        return $issues;
    }

    public function toArray(): ?array
    {
        return $this->manifest;
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR): ?string
    {
        if ($this->manifest === null) {
            return null;
        }

        return json_encode($this->manifest, $flags);
    }

    private function validateManifest(array $data): void
    {
        if (!isset($data['version']) || !is_string($data['version'])) {
            throw new \RuntimeException('Manifest missing or invalid "version" field');
        }

        if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $data['version'])) {
            throw new \RuntimeException("Invalid version format: {$data['version']}. Expected semver (e.g. 1.0.0)");
        }

        if (!isset($data['files']) || !is_array($data['files'])) {
            throw new \RuntimeException('Manifest missing or invalid "files" array');
        }

        foreach ($data['files'] as $index => $file) {
            if (!isset($file['path']) || !is_string($file['path'])) {
                throw new \RuntimeException("Manifest file entry #{$index} missing or invalid 'path'");
            }
            if (!isset($file['hash']) || !is_string($file['hash'])) {
                throw new \RuntimeException("Manifest file entry #{$index} missing or invalid 'hash'");
            }
        }
    }
}
