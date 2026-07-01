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

    public function fetchManifest(): string
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');
        $apiToken = $this->config->get('api_token', '');

        if ($apiUrl === '') {
            throw new \RuntimeException('API URL not configured');
        }

        $url = "{$apiUrl}/update.json";

        $this->logger->info("Fetching manifest from API", ['url' => $url]);

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\nAccept: application/json\r\n",
                'timeout' => $this->config->get('timeout', 60),
            ],
        ];

        if ($apiToken !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$apiToken}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            $this->logger->error('Failed to fetch manifest from API', [
                'url'   => $url,
                'error' => $error['message'] ?? 'Unknown error',
            ]);
            throw new \RuntimeException("Failed to fetch manifest from API: {$url}");
        }

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid manifest response from API');
        }

        $this->logger->info('Manifest fetched successfully from API');
        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    public function downloadFile(string $remotePath, string $localPath): bool
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');
        $apiToken = $this->config->get('api_token', '');

        $url = "{$apiUrl}/files/{$remotePath}";

        $this->logger->info("Downloading file from API", ['path' => $remotePath]);

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
        $response = @file_get_contents($url, false, $context);

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
        $apiToken = $this->config->get('api_token', '');

        $url = "{$apiUrl}/packages/{$packageName}/update.json";

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\nAccept: application/json\r\n",
                'timeout' => $this->config->get('timeout', 60),
            ],
        ];

        if ($apiToken !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$apiToken}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \RuntimeException("Failed to fetch package manifest: {$packageName}");
        }

        return $response;
    }

    public function downloadPackageFile(string $packageName, string $remotePath, string $localPath): bool
    {
        $apiUrl = rtrim($this->config->get('api_url', ''), '/');
        $apiToken = $this->config->get('api_token', '');

        $url = "{$apiUrl}/packages/{$packageName}/files/{$remotePath}";

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
        $response = @file_get_contents($url, false, $context);

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

        $headers = [
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: UpdaterFramework/1.0\r\n",
                'timeout' => 10,
            ],
        ];

        $apiToken = $this->config->get('api_token', '');
        if ($apiToken !== '') {
            $headers['http']['header'] .= "Authorization: Bearer {$apiToken}\r\n";
        }

        $context = stream_context_create($headers);
        $response = @file_get_contents($url, false, $context);

        return $response !== false;
    }
}
