<?php

declare(strict_types=1);

namespace Updater\Core;

use Updater\Support\Config;
use Updater\Support\Logger;
use Updater\Support\Cache;
use Updater\Support\StateManager;
use Updater\Managers\VersionManager;
use Updater\Managers\BackupManager;
use Updater\Managers\MigrationManager;
use Updater\Managers\FileManager;
use Updater\Providers\GitHubProvider;
use Updater\Providers\ApiProvider;

class Updater
{
    private Config $config;
    private Logger $logger;
    private Cache $cache;
    private StateManager $state;

    private UpdateChecker $checker;
    private Installer $installer;
    private Rollback $rollback;
    private VersionManager $versionManager;
    private BackupManager $backupManager;
    private MigrationManager $migrationManager;
    private FileManager $fileManager;

    private static ?self $instance = null;

    public function __construct(array $config = [])
    {
        $this->config = Config::make($config);
        $this->logger = new Logger($this->config);
        $this->cache = new Cache($this->config);
        $this->state = new StateManager($this->config);

        $this->checker = new UpdateChecker($this->config, $this->logger, $this->cache);
        $this->installer = new Installer($this->config, $this->logger);
        $this->rollback = new Rollback($this->config, $this->logger);
        $this->versionManager = new VersionManager($this->config, $this->logger, $this->cache);
        $this->backupManager = new BackupManager($this->config, $this->logger);
        $this->migrationManager = new MigrationManager($this->config, $this->logger);
        $this->fileManager = new FileManager($this->config, $this->logger);
    }

    public static function make(array $config = []): self
    {
        return new self($config);
    }

    public static function configure(array $config = []): self
    {
        Config::flush();
        self::$instance = new self($config);
        return self::$instance;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function check(): array
    {
        return $this->checker->check();
    }

    public function update(?callable $connectionFactory = null): bool
    {
        $checkResult = $this->check();

        if (isset($checkResult['error'])) {
            $this->logger->error("Update check failed", ['error' => $checkResult['error']]);
            return false;
        }

        if (!$checkResult['available']) {
            $this->logger->info('Already up to date');
            return true;
        }

        try {
            return $this->installer->installUpdate($checkResult, $connectionFactory);
        } catch (\Exception $e) {
            $this->logger->error("Update failed, initiating rollback", ['error' => $e->getMessage()]);

            $this->rollback->rollback(null, $connectionFactory);

            return false;
        }
    }

    public function rollback(?callable $connectionFactory = null): bool
    {
        return $this->rollback->rollback(null, $connectionFactory);
    }

    public function rollbackTo(string $backupId, ?callable $connectionFactory = null): bool
    {
        return $this->rollback->rollback($backupId, $connectionFactory);
    }

    public function rollbackToVersion(string $version, ?callable $connectionFactory = null): bool
    {
        return $this->rollback->rollbackToVersion($version, $connectionFactory);
    }

    public function installPackage(string $packageName, ?callable $connectionFactory = null): bool
    {
        return $this->installer->installPackage($packageName, $connectionFactory);
    }

    public function updatePackage(string $packageName, ?callable $connectionFactory = null): bool
    {
        return $this->installer->updatePackage($packageName, $connectionFactory);
    }

    public function removePackage(string $packageName): bool
    {
        return $this->installer->removePackage($packageName);
    }

    public function getProgress(): array
    {
        return $this->installer->getProgress();
    }

    public function getState(): StateManager
    {
        return $this->state;
    }

    public function getLocalVersion(): string
    {
        return $this->state->getVersion();
    }

    public function getVersionInfo(): array
    {
        return $this->state->load();
    }

    public function getUpdateHistory(): array
    {
        return $this->state->getHistory();
    }

    public function getBackups(): array
    {
        return $this->backupManager->getBackups();
    }

    public function getMigrationStatus(): array
    {
        return $this->migrationManager->getMigrationStatus();
    }

    public function getRecentLogs(int $lines = 50): array
    {
        return $this->logger->getRecentLogs($lines);
    }

    public function testConnection(): bool
    {
        $providerType = $this->config->get('provider', 'github');

        try {
            $provider = match ($providerType) {
                'github' => new GitHubProvider($this->config, $this->logger),
                'api'    => new ApiProvider($this->config, $this->logger),
                default  => throw new \RuntimeException("Unknown provider: {$providerType}"),
            };

            return $provider->testConnection();
        } catch (\Exception $e) {
            $this->logger->error("Connection test failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getChecker(): UpdateChecker
    {
        return $this->checker;
    }

    public function getInstaller(): Installer
    {
        return $this->installer;
    }

    public function getRollback(): Rollback
    {
        return $this->rollback;
    }

    public function getVersionManager(): VersionManager
    {
        return $this->versionManager;
    }

    public function getBackupManager(): BackupManager
    {
        return $this->backupManager;
    }

    public function getMigrationManager(): MigrationManager
    {
        return $this->migrationManager;
    }

    public function getFileManager(): FileManager
    {
        return $this->fileManager;
    }

    public function getCache(): Cache
    {
        return $this->cache;
    }

    public function clearCache(): self
    {
        $this->cache->clear();
        $this->logger->info('Cache cleared');
        return $this;
    }

    public function getDashboardData(): array
    {
        return [
            'state'             => $this->state->load(),
            'update_history'    => $this->state->getHistory(),
            'backups'           => $this->getBackups(),
            'migration_status'  => $this->getMigrationStatus(),
            'progress'          => $this->getProgress(),
        ];
    }
}
