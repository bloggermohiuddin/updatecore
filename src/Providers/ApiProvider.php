<?php

declare(strict_types=1);

namespace Updater\Providers;

use Updater\Support\Config;
use Updater\Support\Logger;

class ApiProvider
{
    private Config $config;
    private Logger $logger;

    public function __construct(?Config $config = null, ?Logger $logger = null)
    {
        $this->config = $config ?? Config::make();
        $this->logger = $logger ?? new Logger($this->config);
    }

    public function getLatestCommit(): array
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');

        if ($apiUrl === '') {
            throw new \RuntimeException('API URL not configured');
        }

        $url = "{$apiUrl}/commit";

        $response = $this->request($url);
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        return [
            'sha'        => $data['sha'] ?? '',
            'short_hash' => $data['short_hash'] ?? substr($data['sha'] ?? '', 0, 7),
            'message'    => $data['message'] ?? '',
            'date'       => $data['date'] ?? '',
            'author'     => $data['author'] ?? '',
            'url'        => $data['url'] ?? '',
        ];
    }

    public function getFileTree(): array
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');

        if ($apiUrl === '') {
            throw new \RuntimeException('API URL not configured');
        }

        $url = "{$apiUrl}/files";

        $response = $this->request($url);
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        $files = [];

        foreach (($data['files'] ?? []) as $item) {
            $files[] = [
                'path'  => $item['path'] ?? '',
                'sha'   => $item['sha'] ?? $item['hash'] ?? '',
                'size'  => $item['size'] ?? 0,
            ];
        }

        $this->logger->info("File tree fetched from API", [
            'files' => count($files),
        ]);

        return $files;
    }

    public function downloadFile(string $remotePath, string $localPath): bool
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');

        $url = "{$apiUrl}/files/{$remotePath}";

        $this->logger->info("Downloading file from API", ['path' => $remotePath]);

        $response = $this->requestRaw($url);

        if ($response === false) {
            $this->logger->error("Failed to download file from API", ['path' => $remotePath]);
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

        $this->logger->info("File downloaded from API", [
            'path' => $remotePath,
            'size' => $written,
        ]);

        return true;
    }

    public function fetchPackageManifest(string $packageName): string
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');

        $url = "{$apiUrl}/packages/{$packageName}/commit";

        return $this->request($url);
    }

    public function getPackageFileTree(string $packageName): array
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');

        $url = "{$apiUrl}/packages/{$packageName}/files";

        $response = $this->request($url);
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        $files = [];

        foreach (($data['files'] ?? []) as $item) {
            $files[] = [
                'path'  => $item['path'] ?? '',
                'sha'   => $item['sha'] ?? $item['hash'] ?? '',
                'size'  => $item['size'] ?? 0,
            ];
        }

        return $files;
    }

    public function downloadPackageFile(string $packageName, string $remotePath, string $localPath): bool
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');

        $url = "{$apiUrl}/packages/{$packageName}/files/{$remotePath}";

        $response = $this->requestRaw($url);

        if ($response === false) {
            return false;
        }

        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($localPath, $response, LOCK_EX) !== false;
    }

    public function testConnection(): bool
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');
        $url = "{$apiUrl}/health";

        try {
            $this->request($url, 10);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function request(string $url, ?int $timeout = null): string
    {
        $apiToken = $this->config->get('api_token', '');

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\nAccept: application/json\r\n",
                'timeout' => $timeout ?? $this->config->get('timeout', 60),
            ],
        ];

        if ($apiToken !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$apiToken}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->logger->error('API request failed', ['url' => $url]);
            throw new \RuntimeException("API request failed: {$url}");
        }

        return $response;
    }

    private function requestRaw(string $url): string|false
    {
        $apiToken = $this->config->get('api_token', '');

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\n",
                'timeout' => $this->config->get('timeout', 60),
            ],
        ];

        if ($apiToken !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$apiToken}\r\n";
        }

        $context = stream_context_create($headers);
        return @file_get_contents($url, false, $context);
    }
}
