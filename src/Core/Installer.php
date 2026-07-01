<?php

declare(strict_types=1);

namespace Updater\Core;

use Updater\Support\Config;
use Updater\Support\Logger;
use Updater\Support\StateManager;
use Updater\Providers\GitHubProvider;
use Updater\Providers\ApiProvider;
use Updater\Managers\FileManager;
use Updater\Managers\BackupManager;
use Updater\Managers\MigrationManager;
use function Updater\Support\updater_ensure_directory;
use function Updater\Support\updater_timestamp;

class Installer
{
    private Config $config;
    private Logger $logger;
    private StateManager $state;
    private FileManager $fileManager;
    private BackupManager $backupManager;
    private MigrationManager $migrationManager;

    private array $progress = [
        'status'    => 'idle',
        'step'      => '',
        'progress'  => 0,
        'total'     => 0,
        'message'   => '',
    ];

    private array $installedFiles = [];

    public function __construct(
        ?Config $config = null,
        ?Logger $logger = null
    ) {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
        $this->state = new StateManager($this->config);
        $this->fileManager = new FileManager($this->config, $this->logger);
        $this->backupManager = new BackupManager($this->config, $this->logger);
        $this->migrationManager = new MigrationManager($this->config, $this->logger);
    }

    public function installUpdate(array $checkResult, ?callable $connectionFactory = null): bool
    {
        if (!$checkResult['available']) {
            $this->logger->info('No update to install');
            return true;
        }

        $this->setProgress('preparing', 'Preparing update...', 0);

        try {
            $this->createBackup($checkResult);
            $this->downloadFiles($checkResult);
            $this->deleteFiles($checkResult);
            $this->runMigrations($checkResult, $connectionFactory);
            $this->saveState($checkResult);

            $this->setProgress('completed', 'Update completed successfully', 100);
            $this->logger->success("Update installed successfully", [
                'commit' => $checkResult['commit'],
            ]);

            return true;

        } catch (\Exception $e) {
            $this->setProgress('failed', 'Update failed: ' . $e->getMessage(), -1);
            $this->logger->error("Update installation failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function installPackage(string $packageName, ?callable $connectionFactory = null): bool
    {
        $this->logger->info("Installing package: {$packageName}");
        $this->setProgress('downloading_package', "Downloading package: {$packageName}", 0);

        try {
            $provider = $this->createProvider();

            if ($provider instanceof ApiProvider) {
                $tree = $provider->getPackageFileTree($packageName);
            } else {
                throw new \RuntimeException("Package install only supported with API provider");
            }

            $totalFiles = count($tree);
            $current = 0;

            foreach ($tree as $file) {
                $current++;
                $percent = (int) (($current / max($totalFiles, 1)) * 100);
                $this->setProgress('downloading_package', "Installing {$file['path']}", $percent, $totalFiles);

                $tempDir = sys_get_temp_dir() . '/updater_pkg_' . uniqid('', true);
                updater_ensure_directory($tempDir);
                $tempFile = $tempDir . '/' . basename($file['path']);

                $success = $provider->downloadPackageFile($packageName, $file['path'], $tempFile);

                if (!$success || !file_exists($tempFile)) {
                    throw new \RuntimeException("Failed to download package file: {$file['path']}");
                }

                $content = file_get_contents($tempFile);
                @unlink($tempFile);
                @rmdir($tempDir);

                if ($content === false) {
                    throw new \RuntimeException("Failed to read package file: {$file['path']}");
                }

                $packageBasePath = "packages/{$packageName}";

                $written = $this->fileManager->writeFile(
                    $packageBasePath . '/' . $file['path'],
                    $content
                );

                if (!$written) {
                    throw new \RuntimeException("Failed to write package file: {$file['path']}");
                }

                $this->installedFiles[] = $packageBasePath . '/' . $file['path'];
            }

            $this->runPackageMigrations($packageName, $connectionFactory);

            $this->setProgress('completed', "Package {$packageName} installed", 100);
            $this->logger->success("Package installed", ['package' => $packageName]);

            return true;

        } catch (\Exception $e) {
            $this->setProgress('failed', "Package install failed: " . $e->getMessage(), -1);
            $this->logger->error("Package installation failed", [
                'package' => $packageName,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function updatePackage(string $packageName, ?callable $connectionFactory = null): bool
    {
        $this->logger->info("Updating package: {$packageName}");
        return $this->installPackage($packageName, $connectionFactory);
    }

    public function removePackage(string $packageName): bool
    {
        $this->logger->info("Removing package: {$packageName}");
        $this->setProgress('removing_package', "Removing package: {$packageName}", 0);

        $packagePath = $this->config->getBasePath("packages/{$packageName}");

        if (!is_dir($packagePath)) {
            $this->logger->warning("Package directory not found", ['package' => $packageName]);
            return true;
        }

        $backupManager = new BackupManager($this->config, $this->logger);
        $files = $this->getPackageFiles($packageName);

        if (!empty($files)) {
            $backupManager->createBackup($files);
        }

        $success = $this->deleteDirectory($packagePath);

        if ($success) {
            $this->setProgress('completed', "Package {$packageName} removed", 100);
            $this->logger->success("Package removed", ['package' => $packageName]);
        } else {
            $this->setProgress('failed', "Failed to remove package: {$packageName}", -1);
            $this->logger->error("Package removal failed", ['package' => $packageName]);
        }

        return $success;
    }

    public function getProgress(): array
    {
        return $this->progress;
    }

    public function getInstalledFiles(): array
    {
        return $this->installedFiles;
    }

    private function createBackup(array $checkResult): void
    {
        $this->setProgress('backup', 'Creating backup...', 10);
        $this->logger->info('Creating backup of files to be updated...');

        $files = array_merge(
            $checkResult['changed_files'] ?? [],
            array_map(fn($f) => ['path' => $f], $checkResult['deleted_files'] ?? [])
        );

        if (!empty($files)) {
            $backupId = $this->backupManager->createBackup($files);

            if ($backupId === null) {
                $this->logger->warning('Backup was skipped (disabled in config)');
            } else {
                $this->logger->info("Backup created with ID: {$backupId}");
            }
        }

        $this->setProgress('backup', 'Backup complete', 20);
    }

    private function downloadFiles(array $checkResult): void
    {
        $changedFiles = $checkResult['changed_files'] ?? [];

        if (empty($changedFiles)) {
            $this->logger->info('No files to download');
            $this->setProgress('download', 'No files to download', 50);
            return;
        }

        $this->setProgress('downloading', 'Downloading changed files...', 25);
        $this->logger->info("Downloading " . count($changedFiles) . " changed files");

        $provider = $this->createProvider();

        $total = count($changedFiles);
        $current = 0;
        $failed = [];

        foreach ($changedFiles as $file) {
            $current++;
            $percent = 25 + (int) (($current / $total) * 45);
            $this->setProgress('downloading', "Downloading {$file['path']}", $percent, $total);

            $this->logger->info("Downloading file", ['path' => $file['path']]);

            $tempDir = sys_get_temp_dir() . '/updater_' . uniqid('', true);
            updater_ensure_directory($tempDir);
            $tempFile = $tempDir . '/' . basename($file['path']);

            $success = $provider->downloadFile($file['path'], $tempFile);

            if (!$success || !file_exists($tempFile)) {
                $this->logger->error("Failed to download file", ['path' => $file['path']]);
                $failed[] = $file['path'];
                @unlink($tempFile);
                @rmdir($tempDir);
                continue;
            }

            $content = file_get_contents($tempFile);
            @unlink($tempFile);
            @rmdir($tempDir);

            if ($content === false) {
                $failed[] = $file['path'];
                continue;
            }

            $parser = new \Updater\Manifest\ManifestParser($this->config, $this->logger);
            if (!$parser->verifyDownloadedContent($content, $file['hash'], strlen($content))) {
                $this->logger->error("File integrity check failed, skipping", ['path' => $file['path']]);
                $failed[] = $file['path'];
                continue;
            }

            $written = $this->fileManager->writeFile(
                $file['path'],
                $content
            );

            if (!$written) {
                $failed[] = $file['path'];
                continue;
            }

            $this->installedFiles[] = $file['path'];
        }

        if (!empty($failed)) {
            throw new \RuntimeException("Failed to download " . count($failed) . " files: " . implode(', ', $failed));
        }

        $this->setProgress('downloading', 'Download complete', 70);
    }

    private function deleteFiles(array $checkResult): void
    {
        $deletedFiles = $checkResult['deleted_files'] ?? [];

        if (empty($deletedFiles)) {
            $this->logger->info('No files to delete');
            $this->setProgress('deleting', 'No files to delete', 75);
            return;
        }

        $this->setProgress('deleting', 'Deleting removed files...', 72);
        $this->logger->info("Deleting " . count($deletedFiles) . " files");

        foreach ($deletedFiles as $filePath) {
            $this->fileManager->deleteFile($filePath);
        }

        $this->setProgress('deleting', 'Deletion complete', 78);
    }

    private function runMigrations(array $checkResult, ?callable $connectionFactory = null): void
    {
        $migrations = $checkResult['migrations'] ?? [];

        if (empty($migrations)) {
            $this->logger->info('No migrations to run');
            $this->setProgress('migrating', 'No migrations to run', 85);
            return;
        }

        if ($connectionFactory === null) {
            $this->logger->warning('Migrations found but no connection factory provided, skipping');
            $this->setProgress('migrating', 'Migrations skipped (no DB connection)', 85);
            return;
        }

        $this->setProgress('migrating', 'Running migrations...', 80);
        $this->logger->info("Running " . count($migrations) . " migrations");

        $results = $this->migrationManager->runMigrations($migrations, $connectionFactory);

        if ($results['failed'] > 0) {
            throw new \RuntimeException("Migration failed: " . $results['failed'] . " migration(s) failed");
        }

        $this->setProgress('migrating', 'Migrations complete', 90);
    }

    private function saveState(array $checkResult): void
    {
        $this->setProgress('finalizing', 'Saving state...', 95);

        $this->state->save([
            'commit'          => $checkResult['commit_full'] ?? $checkResult['commit'] ?? '',
            'short_hash'      => $checkResult['commit'] ?? '',
            'version'         => $checkResult['commit'] ?? '0.0.0',
            'last_updated_at' => updater_timestamp(),
            'files_updated'   => count($this->installedFiles),
        ]);

        $this->state->addHistory([
            'from'        => $this->state->get('commit', ''),
            'to'          => $checkResult['commit_full'] ?? '',
            'short_hash'  => $checkResult['commit'] ?? '',
            'files_count' => count($this->installedFiles),
        ]);

        $this->setProgress('finalizing', 'State saved', 98);
    }

    private function runPackageMigrations(string $packageName, ?callable $connectionFactory = null): void
    {
        if ($connectionFactory === null) {
            return;
        }
    }

    private function getPackageFiles(string $packageName): array
    {
        $packagePath = $this->config->getBasePath("packages/{$packageName}");

        if (!is_dir($packagePath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packagePath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $relativePath = str_replace(
                    $this->config->getBasePath('') . DIRECTORY_SEPARATOR,
                    '',
                    $item->getPathname()
                );
                $files[] = ['path' => str_replace('\\', '/', $relativePath)];
            }
        }

        return $files;
    }

    private function deleteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($path);
    }

    private function setProgress(string $step, string $message, int $percent, ?int $total = null): void
    {
        $this->progress = [
            'status'   => $percent >= 100 ? 'completed' : ($percent < 0 ? 'failed' : 'running'),
            'step'     => $step,
            'progress' => $percent,
            'total'    => $total,
            'message'  => $message,
            'time'     => updater_timestamp(),
        ];
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
