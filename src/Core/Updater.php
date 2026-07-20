<?php

declare(strict_types=1);

namespace UpdateCore\Core;

use UpdateCore\Support\Config;
use UpdateCore\Support\Logger;
use UpdateCore\Support\Helpers;
use UpdateCore\Github\GithubClient;
use UpdateCore\Backup\BackupService;
use UpdateCore\Replace\ReplaceService;
use UpdateCore\Migration\MigrationRunner;
use UpdateCore\Migration\MigrationRepository;
use UpdateCore\Security\SecurityGuard;

class Updater
{
    private Config $config;
    private \PDO $db;
    private Logger $logger;
    private SecurityGuard $security;
    private GithubClient $github;
    private string $jobId;

    public function __construct(\PDO $db, array $config = [])
    {
        Config::flush();
        $this->config = Config::make($config);
        $this->config->set('db', $db);
        $this->db = $db;
        $this->jobId = Helpers::generateJobId();
        $this->logger = new Logger($this->jobId, $db, $this->config);
        $this->security = new SecurityGuard($this->config);
        $this->github = new GithubClient($this->config);
    }

    public static function make(\PDO $db, array $config = []): self
    {
        return new self($db, $config);
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function check(): array
    {
        try {
            $current = $this->getVersion();
            $latest = $this->github->getLatestCommit();

            $isUpdateAvailable = $latest['sha'] !== $current['commit'];

            return [
                'success'          => true,
                'update_available' => $isUpdateAvailable,
                'current'          => [
                    'version'    => $current['version'],
                    'commit'     => $current['commit'],
                    'short_hash' => $current['short_hash'] ?? substr($current['commit'], 0, 7),
                    'updated_at' => $current['updated_at'] ?? 'unknown',
                ],
                'latest'           => [
                    'version'    => '1.0.0+',
                    'commit'     => $latest['sha'],
                    'short_hash' => $latest['short_sha'],
                    'message'    => $latest['message'],
                    'author'     => $latest['author'],
                    'date'       => $latest['date'],
                ],
                'commits_behind'   => $isUpdateAvailable
                    ? $this->github->getCommitHistory($current['commit'], $latest['sha'])
                    : [],
                'rate_limit'       => $this->github->getRateLimit(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Update check failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function execute(bool $skipAdminAuth = false): array
    {
        $startTime = microtime(true);
        $statusFile = $this->getStatusFile();

        try {
            $this->logger->info('Starting update process', 'init');
            $this->createLock();

            if (!$skipAdminAuth) {
                if (!$this->security->validateAdmin()) {
                    throw new \RuntimeException('Admin authentication required');
                }
            }

            $checkResult = $this->check();
            if (!$checkResult['success']) {
                throw new \RuntimeException('Update check failed: ' . $checkResult['error']);
            }

            if (!$checkResult['update_available']) {
                $this->cleanup();
                return ['success' => true, 'message' => 'Already up to date', 'job_id' => $this->jobId];
            }

            $currentCommit = $checkResult['current']['commit'];
            $targetCommit = $checkResult['latest']['commit'];

            $this->updateStatus($statusFile, 'running', 5, 'fetching', 'Preparing update...');

            $changes = $this->github->getCompareChanges($currentCommit, $targetCommit);
            $this->logger->info("Found {$changes['total_commits']} commits, " . count($changes['files']) . ' files changed', 'compare');

            $this->updateStatus($statusFile, 'running', 10, 'maintenance', 'Enabling maintenance mode...');
            $this->enableMaintenance();
            $this->logger->info('Maintenance mode enabled', 'maintenance');

            $this->updateStatus($statusFile, 'running', 20, 'backup', 'Creating backup...');
            $backupService = new BackupService($this->jobId, $this->config);
            $backup = $backupService->createBackup(array_column($changes['files'], 'path'));
            $this->logger->info("Backup: {$backup['total_files']} files, " . Helpers::formatBytes($backup['total_size']), 'backup');

            $this->updateStatus($statusFile, 'running', 40, 'downloading', 'Downloading files...');
            $replaceService = new ReplaceService($this->logger, $this->config);

            $downloadResults = ['success' => [], 'failed' => []];
            $filesToDownload = array_merge($changes['modified'], $changes['added']);
            $totalFiles = count($filesToDownload);
            $processedFiles = 0;

            foreach ($filesToDownload as $file) {
                $filePath = $file['path'] ?? $file;
                $processedFiles++;
                $progress = 40 + (int)(($processedFiles / max($totalFiles, 1)) * 20);
                $this->updateStatus($statusFile, 'running', $progress, 'downloading', "Downloading {$filePath} ({$processedFiles}/{$totalFiles})...");

                if ($this->config->isExcluded($filePath) || $this->config->isPreserved($filePath)) {
                    $downloadResults['success'][] = $filePath . ' (skipped)';
                    continue;
                }

                $targetPath = $this->config->getProjectRoot() . '/' . $filePath;
                if ($this->github->downloadFile($filePath, $targetCommit, $targetPath)) {
                    $downloadResults['success'][] = $filePath;
                    $this->logger->info("Downloaded: {$filePath}", 'download');
                } else {
                    $downloadResults['failed'][] = $filePath;
                    $this->logger->error("Failed to download: {$filePath}", 'download');
                }
            }

            if (!empty($downloadResults['failed'])) {
                $this->logger->warning('Failed to download ' . count($downloadResults['failed']) . ' files');
            }

            $this->updateStatus($statusFile, 'running', 70, 'cleaning', 'Removing obsolete files...');
            $deleteResults = $replaceService->deleteRemovedFiles($changes['removed']);
            $this->logger->info('Deleted: ' . count($deleteResults['deleted']) . ' files', 'delete');

            $this->updateStatus($statusFile, 'running', 80, 'migrating', 'Running migrations...');
            $migrationRunner = new MigrationRunner($this->db, null, $this->logger, $this->config);
            $migrationResults = $migrationRunner->runAll();

            if (!empty($migrationResults['failed'])) {
                $this->logger->warning('Migration failed: ' . $migrationResults['failed'][0]['error']);
            }

            $this->updateStatus($statusFile, 'running', 90, 'finalizing', 'Finalizing...');
            $newVersion = [
                'version'    => '1.0.0+',
                'commit'     => $targetCommit,
                'updated_at' => date('Y-m-d H:i:s'),
                'short_hash' => substr($targetCommit, 0, 7),
                'repository' => $this->config->get('repository'),
                'branch'     => $this->config->get('branch', 'main'),
            ];
            $this->setVersion($newVersion);

            $this->updateStatus($statusFile, 'running', 95, 'cleanup', 'Cleaning up...');
            $this->disableMaintenance();
            $replaceService->cleanup();
            $this->cleanup();

            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $this->logger->success("Update completed in {$duration}ms", 'complete');
            $this->updateStatus($statusFile, 'success', 100, 'complete', 'Update completed successfully!');

            BackupService::pruneOld($this->config->get('backup_max', 10), $this->config);

            return [
                'success'        => true,
                'message'        => 'Update completed successfully',
                'job_id'         => $this->jobId,
                'duration_ms'    => $duration,
                'from_commit'    => $currentCommit,
                'to_commit'      => $targetCommit,
                'files_changed'  => count($changes['files']),
                'migrations_run' => count($migrationResults['executed']),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Update failed: ' . $e->getMessage(), 'error');
            $this->handleFailure($e->getMessage(), $statusFile);

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'job_id'  => $this->jobId,
            ];
        }
    }

    public function rollback(string $backupJobId): array
    {
        try {
            if (!$this->security->validateAdmin()) {
                throw new \RuntimeException('Admin authentication required');
            }

            $backupService = new BackupService($backupJobId, $this->config);
            if (!$backupService->verify()) {
                throw new \RuntimeException('Backup verification failed');
            }

            $this->enableMaintenance('System rollback in progress...');

            if (!$backupService->restore()) {
                throw new \RuntimeException('Restore failed');
            }

            $metadata = $backupService->getMetadata();
            if ($metadata) {
                $this->setVersion([
                    'version'    => $metadata['from_version'] ?? '0.0.0',
                    'commit'     => $metadata['from_commit'] ?? 'unknown',
                    'updated_at' => date('Y-m-d H:i:s'),
                    'short_hash' => substr($metadata['from_commit'] ?? '', 0, 7),
                    'repository' => $this->config->get('repository'),
                    'branch'     => $this->config->get('branch', 'main'),
                ]);
            }

            $this->disableMaintenance();
            $this->logger->success('Rollback completed: ' . $backupJobId, 'rollback');

            return ['success' => true, 'message' => 'Rollback completed', 'job_id' => $backupJobId];
        } catch (\Exception $e) {
            $this->disableMaintenance();
            $this->logger->error('Rollback failed: ' . $e->getMessage(), 'rollback');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getStatus(string $jobId): ?array
    {
        $statusFile = $this->config->getStatusPath() . '/' . $jobId . '.json';
        if (!file_exists($statusFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($statusFile), true);
        if (!$data) {
            return null;
        }

        $logger = new Logger($jobId, $this->db, $this->config);
        $data['logs'] = $logger->getJobLogs(20);

        return $data;
    }

    public function listBackups(): array
    {
        return BackupService::listBackups($this->config);
    }

    public function deleteBackup(string $backupJobId): bool
    {
        $backupService = new BackupService($backupJobId, $this->config);
        $result = $backupService->delete();
        if ($result) {
            $this->logger->cleanAllLogs();
        }
        return $result;
    }

    public function enableMaintenance(?string $message = null): bool
    {
        $message = $message ?? $this->config->get('maintenance_message', 'System update in progress.');
        $flagFile = $this->config->getProjectRoot() . '/.maintenance';
        return file_put_contents($flagFile, json_encode(['enabled_at' => date('Y-m-d H:i:s'), 'message' => $message])) !== false;
    }

    public function disableMaintenance(): bool
    {
        $flagFile = $this->config->getProjectRoot() . '/.maintenance';
        return file_exists($flagFile) ? unlink($flagFile) : true;
    }

    public function isMaintenanceMode(): bool
    {
        return file_exists($this->config->getProjectRoot() . '/.maintenance');
    }

    public function getVersion(): array
    {
        $versionFile = $this->config->getProjectRoot() . '/version.json';
        if (!file_exists($versionFile)) {
            return ['version' => '0.0.0', 'commit' => 'unknown', 'updated_at' => 'never', 'short_hash' => 'unknown', 'repository' => '', 'branch' => 'main'];
        }
        $data = json_decode(file_get_contents($versionFile), true);
        return is_array($data) ? $data : ['version' => '0.0.0', 'commit' => 'unknown'];
    }

    public function setVersion(array $data): bool
    {
        $versionFile = $this->config->getProjectRoot() . '/version.json';
        return file_put_contents($versionFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    public function cleanOldLogs(int $keepDays = 30): int
    {
        return $this->logger->cleanOldLogs($keepDays);
    }

    public function cleanAllLogs(): int
    {
        return $this->logger->cleanAllLogs();
    }

    public function getLogStats(): array
    {
        return $this->logger->getStats();
    }

    public function getGithub(): GithubClient
    {
        return $this->github;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getSecurity(): SecurityGuard
    {
        return $this->security;
    }

    private function handleFailure(string $error, string $statusFile): void
    {
        try {
            $this->updateStatus($statusFile, 'failed', 0, 'rollback', 'Attempting rollback...');
            $backupService = new BackupService($this->jobId, $this->config);
            if ($backupService->verify()) {
                $backupService->restore();
                $this->logger->info('Automatic rollback completed', 'rollback');
            }
        } catch (\Exception $e) {
            $this->logger->error('Rollback failed: ' . $e->getMessage(), 'rollback');
        }

        $this->disableMaintenance();
        $this->cleanup();
        $this->updateStatus($statusFile, 'failed', 0, 'error', $error);
    }

    private function createLock(): void
    {
        $lockDir = $this->config->getLockPath();
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $lockFile = $lockDir . '/update.lock';
        if (file_exists($lockFile)) {
            $lockData = json_decode(file_get_contents($lockFile), true);
            if ($lockData && isset($lockData['timestamp'])) {
                if (time() - $lockData['timestamp'] < $this->config->get('lock_timeout', 3600)) {
                    throw new \RuntimeException('Another update is in progress');
                }
            }
        }

        file_put_contents($lockFile, json_encode([
            'job_id'    => $this->jobId,
            'timestamp' => time(),
            'token'     => $this->security->createLockToken(),
        ]));
    }

    private function cleanup(): void
    {
        $lockFile = $this->config->getLockPath() . '/update.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    private function getStatusFile(): string
    {
        $statusDir = $this->config->getStatusPath();
        if (!is_dir($statusDir)) {
            mkdir($statusDir, 0755, true);
        }
        return $statusDir . '/' . $this->jobId . '.json';
    }

    private function updateStatus(string $statusFile, string $status, int $progress, string $step, string $message): void
    {
        file_put_contents($statusFile, json_encode([
            'status'    => $status,
            'progress'  => $progress,
            'step'      => $step,
            'message'   => $message,
            'job_id'    => $this->jobId,
            'timestamp' => time(),
        ]));
    }
}
