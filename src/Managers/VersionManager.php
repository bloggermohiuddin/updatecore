<?php

declare(strict_types=1);

namespace Updater\Managers;

use Updater\Support\Config;
use Updater\Support\Cache;
use Updater\Support\Logger;
use function Updater\Support\updater_timestamp;

class VersionManager
{
    private Config $config;
    private Logger $logger;
    private Cache $cache;

    private string $versionFile;

    public function __construct(?Config $config = null, ?Logger $logger = null, ?Cache $cache = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
        $this->cache = $cache ?? new Cache($this->config);
        $this->versionFile = $this->config->getStoragePath('version.json');
    }

    public function getLocalVersion(): string
    {
        if (!file_exists($this->versionFile)) {
            $this->logger->debug('No local version file found, returning 0.0.0');
            return '0.0.0';
        }

        $data = json_decode(file_get_contents($this->versionFile) ?: '{}', true);

        return $data['version'] ?? '0.0.0';
    }

    public function setLocalVersion(string $version, array $meta = []): self
    {
        $data = array_merge($meta, [
            'version'      => $version,
            'updated_at'   => updater_timestamp(),
            'previous'     => $this->getLocalVersion(),
        ]);

        file_put_contents(
            $this->versionFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        $this->cache->forget('remote_version');
        $this->logger->info("Local version updated to {$version}", $data);

        return $this;
    }

    public function getVersionInfo(): array
    {
        if (!file_exists($this->versionFile)) {
            return [
                'version'    => '0.0.0',
                'updated_at' => null,
                'previous'   => null,
            ];
        }

        $data = json_decode(file_get_contents($this->versionFile) ?: '{}', true);

        return [
            'version'    => $data['version'] ?? '0.0.0',
            'updated_at' => $data['updated_at'] ?? null,
            'previous'   => $data['previous'] ?? null,
        ];
    }

    public function compareVersions(string $local, string $remote): int
    {
        return version_compare($local, $remote);
    }

    public function isUpdateAvailable(string $remoteVersion): bool
    {
        $localVersion = $this->getLocalVersion();
        return $this->compareVersions($localVersion, $remoteVersion) < 0;
    }

    public function formatVersion(string $version): string
    {
        $parts = explode('.', $version);
        return implode('.', array_slice($parts, 0, 3));
    }

    public function isVersionFormatValid(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $version);
    }

    public function getUpdateHistory(): array
    {
        $historyFile = $this->config->getStoragePath('update_history.json');

        if (!file_exists($historyFile)) {
            return [];
        }

        $data = json_decode(file_get_contents($historyFile) ?: '[]', true);

        return is_array($data) ? $data : [];
    }

    public function addToHistory(array $entry): self
    {
        $historyFile = $this->config->getStoragePath('update_history.json');
        $history = $this->getUpdateHistory();

        $history[] = array_merge($entry, [
            'timestamp' => updater_timestamp(),
        ]);

        file_put_contents(
            $historyFile,
            json_encode($history, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        return $this;
    }

    public function getRemoteVersion(): ?string
    {
        return $this->cache->get('remote_version');
    }

    public function setRemoteVersion(string $version): self
    {
        $this->cache->set('remote_version', $version, 300);
        return $this;
    }
}
