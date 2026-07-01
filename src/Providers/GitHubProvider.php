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

    public function fetchManifest(string $channel = ''): string
    {
        $channel = $channel ?: $this->config->get('channel', 'stable');
        $repo = $this->config->get('repository', '');
        $token = $this->config->get('token', '');

        if ($repo === '') {
            throw new \RuntimeException('GitHub repository not configured');
        }

        $this->logger->info("Fetching manifest from GitHub", [
            'repo'    => $repo,
            'channel' => $channel,
        ]);

        $url = "{$this->apiBase}/repos/{$repo}/contents/{$channel}/update.json";

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout' => $this->config->get('timeout', 60),
            ],
        ];

        if ($token !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$token}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error('Failed to fetch manifest from GitHub', ['url' => $url]);
            throw new \RuntimeException("Failed to fetch manifest from GitHub: {$url}");
        }

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (isset($decoded['content']) && isset($decoded['encoding'])) {
            $content = base64_decode($decoded['content'], true);
            if ($content === false) {
                throw new \RuntimeException('Failed to decode GitHub base64 content');
            }
            $response = $content;
        }

        $this->logger->info('Manifest fetched successfully from GitHub');
        return is_string($response) ? $response : $response;
    }

    public function downloadFile(string $remotePath, string $localPath, string $channel = ''): bool
    {
        $channel = $channel ?: $this->config->get('channel', 'stable');
        $repo = $this->config->get('repository', '');
        $token = $this->config->get('token', '');

        $url = "{$this->apiBase}/repos/{$repo}/contents/{$channel}/files/{$remotePath}";

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

    public function downloadArchive(string $channel, string $version, string $destPath): bool
    {
        $repo = $this->config->get('repository', '');
        $token = $this->config->get('token', '');

        $url = "{$this->apiBase}/repos/{$repo}/zipball/{$channel}";

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

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\n",
                'timeout' => 10,
            ],
        ];

        $token = $this->config->get('token', '');
        if ($token !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$token}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        return $response !== false;
    }
}
