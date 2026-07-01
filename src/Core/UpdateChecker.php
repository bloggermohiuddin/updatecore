<?php

declare(strict_types=1);

namespace Updater\Core;

use Updater\Support\Config;
use Updater\Support\Logger;
use Updater\Support\Cache;
use Updater\Support\StateManager;
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
    private StateManager $state;
    private VersionManager $versionManager;
    private FileManager $fileManager;
    private ManifestParser $manifestParser;

    public function __construct(
        ?Config $config = null,
        ?Logger $logger = null,
        ?Cache $cache = null
    ) {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
        $this->cache = $cache ?? new Cache($this->config);
        $this->state = new StateManager($this->config);
        $this->versionManager = new VersionManager($this->config, $this->logger, $this->cache);
        $this->fileManager = new FileManager($this->config, $this->logger);
        $this->manifestParser = new ManifestParser($this->config, $this->logger);
    }

    public function check(): array
    {
        $this->logger->info('Checking for updates...');

        $localCommit = $this->state->getLastCommit();
        $localVersion = $this->versionManager->getLocalVersion();

        $this->logger->info("Local state", [
            'commit'  => $localCommit ?: 'none',
            'version' => $localVersion,
        ]);

        try {
            $remoteCommit = $this->getRemoteCommit();
        } catch (\Exception $e) {
            $this->logger->error("Failed to check for updates", ['error' => $e->getMessage()]);
            return [
                'available' => false,
                'error'     => $e->getMessage(),
                'local'     => $localVersion,
            ];
        }

        $this->state->setLastCheckedAt();

        if ($remoteCommit['sha'] === $localCommit && $localCommit !== '') {
            $this->logger->info("Already up to date", [
                'commit' => $remoteCommit['short_hash'],
            ]);

            return [
                'available'  => false,
                'local'      => $localVersion,
                'commit'     => $remoteCommit['short_hash'],
                'message'    => 'Already up to date',
            ];
        }

        $remoteTree = $this->getRemoteFileTree();
        $localHashes = $this->manifestParser->getLocalHashes($this->config->getBasePath(), $remoteTree);
        $comparison = $this->manifestParser->compareFileTree($remoteTree, $localHashes);

        $totalSize = 0;
        foreach ($comparison['changed'] as $file) {
            $totalSize += $file['size'] ?? 0;
        }

        $hasChanges = !empty($comparison['changed']) || !empty($comparison['deleted']);

        if (!$hasChanges && $remoteCommit['sha'] === $localCommit) {
            $this->logger->info("No changes detected");

            return [
                'available'  => false,
                'local'      => $localVersion,
                'commit'     => $remoteCommit['short_hash'],
                'message'    => 'No changes detected',
            ];
        }

        $this->logger->info("Update available", [
            'from'     => $localCommit ?: 'initial',
            'to'       => $remoteCommit['short_hash'],
            'changed'  => count($comparison['changed']),
            'deleted'  => count($comparison['deleted']),
            'size'     => updater_file_size_human($totalSize),
        ]);

        return [
            'available'      => true,
            'local'          => $localVersion,
            'commit'         => $remoteCommit['short_hash'],
            'commit_full'    => $remoteCommit['sha'],
            'commit_message' => $remoteCommit['message'],
            'commit_date'    => $remoteCommit['date'],
            'commit_author'  => $remoteCommit['author'],
            'commit_url'     => $remoteCommit['url'],
            'changed_files'  => $comparison['changed'],
            'deleted_files'  => $comparison['deleted'],
            'total_size'     => $totalSize,
            'total_size_human' => updater_file_size_human($totalSize),
            'remote_tree'    => $remoteTree,
        ];
    }

    public function getRemoteCommit(): array
    {
        $cached = $this->cache->get('remote_commit');
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->createProvider();
        $commit = $provider->getLatestCommit();

        $this->cache->set('remote_commit', $commit, 300);

        return $commit;
    }

    public function getRemoteFileTree(): array
    {
        $cached = $this->cache->get('remote_tree');
        if ($cached !== null) {
            return $cached;
        }

        $provider = $this->createProvider();
        $tree = $provider->getFileTree();

        $this->cache->set('remote_tree', $tree, 300);

        return $tree;
    }

    public function getState(): StateManager
    {
        return $this->state;
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
