<?php

declare(strict_types=1);

namespace Updater\Core;

use Updater\Support\Config;
use Updater\Support\Logger;
use Updater\Support\Cache;
use Updater\Providers\GitHubProvider;
use Updater\Providers\ApiProvider;
use Updater\Manifest\ManifestParser;
use Updater\Managers\VersionManager;
use Updater\Managers\FileManager;
use function Updater\Support\updater_file_size_human;

class UpdateChecker
{
    private Config $config;
    private Logger $logger;
    private Cache $cache;
    private VersionManager $versionManager;
    private FileManager $fileManager;
    private ManifestParser $manifestParser;

    private ?array $remoteManifest = null;

    public function __construct(
        ?Config $config = null,
        ?Logger $logger = null,
        ?Cache $cache = null
    ) {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
        $this->cache = $cache ?? new Cache($this->config);
        $this->versionManager = new VersionManager($this->config, $this->logger, $this->cache);
        $this->fileManager = new FileManager($this->config, $this->logger);
        $this->manifestParser = new ManifestParser($this->config, $this->logger);
    }

    public function check(): array
    {
        $this->logger->info('Checking for updates...');

        $localVersion = $this->versionManager->getLocalVersion();
        $this->logger->info("Local version: {$localVersion}");

        try {
            $remoteManifest = $this->fetchRemoteManifest();
        } catch (\Exception $e) {
            $this->logger->error("Failed to check for updates", ['error' => $e->getMessage()]);
            return [
                'available' => false,
                'error'     => $e->getMessage(),
                'local'     => $localVersion,
            ];
        }

        $remoteVersion = $remoteManifest['version'] ?? '0.0.0';
        $this->versionManager->setRemoteVersion($remoteVersion);

        $isAvailable = $this->versionManager->isUpdateAvailable($remoteVersion);

        if (!$isAvailable) {
            $this->logger->info("Application is up to date", [
                'local'  => $localVersion,
                'remote' => $remoteVersion,
            ]);

            return [
                'available'    => false,
                'local'        => $localVersion,
                'remote'       => $remoteVersion,
                'message'      => 'Application is up to date',
            ];
        }

        $localHashes = $this->fileManager->getLocalHashesFromManifest($remoteManifest['files'] ?? []);
        $changedFiles = $this->manifestParser->parse(json_encode($remoteManifest, JSON_THROW_ON_ERROR))
            ->getChangedFiles($localHashes);

        $deletedFiles = $remoteManifest['deleted'] ?? [];
        $migrations = $remoteManifest['migrations'] ?? [];

        $totalSize = $this->fileManager->calculateTotalSize($changedFiles);

        $this->logger->info("Update available", [
            'local'   => $localVersion,
            'remote'  => $remoteVersion,
            'changed' => count($changedFiles),
            'deleted' => count($deletedFiles),
            'size'    => updater_file_size_human($totalSize),
        ]);

        return [
            'available'      => true,
            'local'          => $localVersion,
            'remote'         => $remoteVersion,
            'release_date'   => $remoteManifest['release_date'] ?? null,
            'changed_files'  => $changedFiles,
            'deleted_files'  => $deletedFiles,
            'migrations'     => $migrations,
            'total_size'     => $totalSize,
            'total_size_human' => updater_file_size_human($totalSize),
            'manifest'       => $remoteManifest,
        ];
    }

    public function fetchRemoteManifest(): array
    {
        $cached = $this->cache->get('remote_manifest');
        if ($cached !== null) {
            $this->logger->debug('Using cached remote manifest');
            return $cached;
        }

        $provider = $this->createProvider();

        $jsonContent = $provider->fetchManifest();
        $manifest = $this->manifestParser->parse($jsonContent);

        $this->cache->set('remote_manifest', $manifest, 300);
        $this->remoteManifest = $manifest;

        return $manifest;
    }

    public function getRemoteManifest(): ?array
    {
        return $this->remoteManifest;
    }

    public function checkFileIntegrity(): array
    {
        $manifest = $this->fetchRemoteManifest();
        $parser = new ManifestParser($this->config, $this->logger);
        $parser->parse(json_encode($manifest, JSON_THROW_ON_ERROR));

        return $parser->verifyIntegrity($this->config->getBasePath());
    }

    public function getVersionManager(): VersionManager
    {
        return $this->versionManager;
    }

    public function getFileManager(): FileManager
    {
        return $this->fileManager;
    }

    private function createProvider(): GitHubProvider|ApiProvider
    {
        $providerType = $this->config->get('provider', 'github');

        return match ($providerType) {
            'github' => new GitHubProvider($this->config, $this->logger),
            'api'    => new ApiProvider($this->config, $this->logger),
            default  => throw new \RuntimeException("Unknown provider: {$providerType}"),
        };
    }
}
