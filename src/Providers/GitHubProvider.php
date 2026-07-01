<?php

declare(strict_types=1);

namespace Updater\Providers;

use Updater\Support\Config;
use Updater\Support\Logger;
use function Updater\Support\updater_ensure_directory;

class GitHubProvider
{
    private Config $config;
    private Logger $logger;

    private string $apiBase = 'https://api.github.com';

    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
    }

    public function getLatestCommit(): array
    {
        $repo = $this->config->get('repository', '');
        $branch = $this->config->get('branch', 'main');

        $this->requireRepo($repo);

        $url = "{$this->apiBase}/repos/{$repo}/commits/{$branch}";

        $response = $this->request($url);
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return [
            'sha'        => $data['sha'] ?? '',
            'short_hash' => substr($data['sha'] ?? '', 0, 7),
            'message'    => $data['commit']['message'] ?? '',
            'date'       => $data['commit']['committer']['date'] ?? '',
            'author'     => $data['commit']['author']['name'] ?? '',
            'url'        => $data['html_url'] ?? '',
        ];
    }

    public function getFileTree(): array
    {
        $repo = $this->config->get('repository', '');
        $branch = $this->config->get('branch', 'main');

        $this->requireRepo($repo);

        $url = "{$this->apiBase}/repos/{$repo}/git/trees/{$branch}?recursive=1";

        $response = $this->request($url);
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        $files = [];

        foreach (($data['tree'] ?? []) as $item) {
            if ($item['type'] !== 'blob') {
                continue;
            }

            $path = $item['path'];

            if ($this->shouldSkipPath($path)) {
                continue;
            }

            $files[] = [
                'path'  => $path,
                'sha'   => $item['sha'],
                'size'  => $item['size'] ?? 0,
            ];
        }

        $this->logger->info("File tree fetched from GitHub", [
            'repo'   => $repo,
            'branch' => $branch,
            'files'  => count($files),
        ]);

        return $files;
    }

    public function downloadFile(string $remotePath, string $localPath): bool
    {
        $repo = $this->config->get('repository', '');
        $token = $this->config->get('token', '');

        $this->requireRepo($repo);

        $url = "{$this->apiBase}/repos/{$repo}/contents/{$remotePath}";

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\nAccept: application/vnd.github.v3.raw\r\n",
                'timeout' => $this->config->get('timeout', 60),
            ],
        ];

        if ($token !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$token}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error("Failed to download file from GitHub", ['path' => $remotePath]);
            return false;
        }

        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $written = file_put_contents($localPath, $response, LOCK_EX);

        if ($written === false) {
            $this->logger->error("Failed to write downloaded file", ['path' => $localPath]);
            return false;
        }

        $this->logger->info("File downloaded from GitHub", [
            'path' => $remotePath,
            'size' => $written,
        ]);

        return true;
    }

    public function downloadArchive(string $ref, string $version, string $destPath): bool
    {
        $repo = $this->config->get('repository', '');
        $token = $this->config->get('token', '');

        $this->requireRepo($repo);

        $url = "{$this->apiBase}/repos/{$repo}/zipball/{$ref}";

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\n",
                'timeout' => $this->config->get('timeout', 120),
            ],
        ];

        if ($token !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$token}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error('Failed to download archive from GitHub');
            return false;
        }

        updater_ensure_directory($destPath);
        $archivePath = $destPath . "/release-{$version}.zip";

        $written = file_put_contents($archivePath, $response, LOCK_EX);
        if ($written === false) {
            return false;
        }

        $this->logger->info("Archive downloaded from GitHub", [
            'version' => $version,
            'path'    => $archivePath,
        ]);

        return true;
    }

    public function testConnection(): bool
    {
        $repo = $this->config->get('repository', '');
        $url = "{$this->apiBase}/repos/{$repo}";

        try {
            $this->request($url, 10);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function request(string $url, ?int $timeout = null): string
    {
        $token = $this->config->get('token', '');

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\nAccept: application/json\r\n",
                'timeout' => $timeout ?? $this->config->get('timeout', 60),
            ],
        ];

        if ($token !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$token}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error('GitHub API request failed', ['url' => $url]);
            throw new \RuntimeException("GitHub API request failed: {$url}");
        }

        return $response;
    }

    private function requireRepo(string $repo): void
    {
        if ($repo === '') {
            throw new \RuntimeException('GitHub repository not configured');
        }
    }

    private function shouldSkipPath(string $path): bool
    {
        $skipPatterns = [
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
            '.env.',
            'composer.lock',
            'README.md',
            'LICENSE',
            'CHANGELOG.md',
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_starts_with($path, $pattern) || str_contains($path, '/' . $pattern)) {
                return true;
            }
        }

        return false;
    }
}
