<?php

declare(strict_types=1);

namespace UpdateCore\Github;

use UpdateCore\Support\Config;

class GithubClient
{
    private Config $config;
    private int $timeout;

    public function __construct(?Config $config = null, int $timeout = 30)
    {
        $this->config = $config ?? Config::make();
        $this->timeout = $timeout;
    }

    public function getLatestCommit(): array
    {
        $repo = $this->config->get('repository');
        $branch = $this->config->get('branch', 'main');
        $url = "https://api.github.com/repos/{$repo}/commits/{$branch}";

        $response = $this->makeRequest($url);
        if ($response === null) {
            throw new \RuntimeException('Failed to fetch latest commit from GitHub');
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response from GitHub');
        }

        return [
            'sha'        => $data['sha'] ?? '',
            'short_sha'  => substr($data['sha'] ?? '', 0, 7),
            'message'    => $data['commit']['message'] ?? '',
            'author'     => $data['commit']['author']['name'] ?? '',
            'date'       => $data['commit']['author']['date'] ?? '',
            'url'        => $data['html_url'] ?? '',
        ];
    }

    public function getCompareChanges(string $fromCommit, string $toCommit): array
    {
        $repo = $this->config->get('repository');
        $url = "https://api.github.com/repos/{$repo}/compare/{$fromCommit}...{$toCommit}";

        $response = $this->makeRequest($url);
        if ($response === null) {
            throw new \RuntimeException('Failed to fetch compare data from GitHub');
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response from GitHub');
        }

        $changes = [
            'ahead_by'      => $data['ahead_by'] ?? 0,
            'behind_by'     => $data['behind_by'] ?? 0,
            'total_commits' => $data['total_commits'] ?? 0,
            'files'         => [],
            'added'         => [],
            'modified'      => [],
            'removed'       => [],
        ];

        if (isset($data['files']) && is_array($data['files'])) {
            foreach ($data['files'] as $file) {
                $fileData = [
                    'path'              => $file['filename'],
                    'status'            => $file['status'],
                    'additions'         => $file['additions'] ?? 0,
                    'deletions'         => $file['deletions'] ?? 0,
                    'changes'           => $file['changes'] ?? 0,
                    'sha'               => $file['sha'] ?? null,
                    'previous_filename' => $file['previous_filename'] ?? null,
                ];

                $changes['files'][] = $fileData;

                switch ($file['status']) {
                    case 'added':
                        $changes['added'][] = $fileData;
                        break;
                    case 'modified':
                        $changes['modified'][] = $fileData;
                        break;
                    case 'removed':
                        $changes['removed'][] = $fileData;
                        break;
                    case 'renamed':
                        $changes['added'][] = $fileData;
                        if ($file['previous_filename']) {
                            $changes['removed'][] = [
                                'path'   => $file['previous_filename'],
                                'status' => 'removed',
                            ];
                        }
                        break;
                }
            }
        }

        return $changes;
    }

    public function downloadFile(string $path, string $commit, string $destination): bool
    {
        $repo = $this->config->get('repository');
        $url = "https://raw.githubusercontent.com/{$repo}/{$commit}/{$path}";

        $content = $this->makeRequest($url);
        if ($content === null) {
            return false;
        }

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($destination, $content) !== false;
    }

    public function downloadZip(string $commit, string $destination): bool
    {
        $repo = $this->config->get('repository');
        $url = "https://github.com/{$repo}/archive/{$commit}.zip";

        $content = $this->downloadRawFile($url);
        if ($content === null || strlen($content) < 100) {
            return false;
        }

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($destination, $content) !== false;
    }

    public function getCommitHistory(string $fromCommit, string $toCommit, int $limit = 30): array
    {
        $repo = $this->config->get('repository');
        $url = "https://api.github.com/repos/{$repo}/compare/{$fromCommit}...{$toCommit}";

        $response = $this->makeRequest($url);
        if ($response === null) {
            return [];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['commits'])) {
            return [];
        }

        $commits = [];
        foreach (array_slice($data['commits'], 0, $limit) as $commit) {
            $commits[] = [
                'sha'       => $commit['sha'],
                'short_sha' => substr($commit['sha'], 0, 7),
                'message'   => $commit['commit']['message'] ?? '',
                'author'    => $commit['commit']['author']['name'] ?? '',
                'date'      => $commit['commit']['author']['date'] ?? '',
            ];
        }

        return $commits;
    }

    public function getRateLimit(): array
    {
        $url = 'https://api.github.com/rate_limit';
        $response = $this->makeRequest($url);

        if ($response === null) {
            return ['limit' => 0, 'remaining' => 0, 'reset' => 0];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['resources']['core'])) {
            return ['limit' => 0, 'remaining' => 0, 'reset' => 0];
        }

        $core = $data['resources']['core'];
        return [
            'limit'     => $core['limit'],
            'remaining' => $core['remaining'],
            'reset'     => $core['reset'],
            'reset_at'  => date('Y-m-d H:i:s', $core['reset']),
        ];
    }

    public function testConnection(): bool
    {
        try {
            $commit = $this->getLatestCommit();
            return !empty($commit['sha']);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function makeRequest(string $url): ?string
    {
        $ch = curl_init();
        $headers = [
            'User-Agent: UpdateCore/2.0',
            'Accept: application/vnd.github.v3+json',
        ];

        $token = $this->config->get('github_token');
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        return $response;
    }

    private function downloadRawFile(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: UpdateCore/2.0',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            CURLOPT_HEADER   => false,
            CURLOPT_ENCODING => '',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        return $response;
    }
}
