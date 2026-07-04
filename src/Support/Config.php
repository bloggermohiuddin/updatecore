<?php

declare(strict_types=1);

namespace UpdateCore\Support;

class Config
{
    private static ?self $instance = null;
    private array $data = [];

    private array $defaults = [
        'repository'          => '',
        'branch'              => 'main',
        'github_token'        => '',
        'project_root'        => '',
        'storage_path'        => '',
        'backup_enabled'      => true,
        'backup_max'          => 10,
        'excluded_paths'      => ['storage', 'uploads', '.git', 'logs', '.maintenance', 'version.json'],
        'preserved_files'     => ['config.php', 'admin/config.php', '.htaccess', 'admin/.htaccess'],
        'maintenance_message' => 'System update in progress. Please try again later.',
        'lock_timeout'        => 3600,
        'log_retention_days'  => 30,
        'secret_key'          => '',
        'allowed_ips'         => [],
        'db'                  => null,
    ];

    private function __construct(array $config = [])
    {
        $this->data = array_merge($this->defaults, $config);

        if (empty($this->data['project_root'])) {
            $this->data['project_root'] = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, 3);
        }

        if (empty($this->data['storage_path'])) {
            $this->data['storage_path'] = $this->data['project_root'] . '/storage';
        }
    }

    public static function make(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function flush(): void
    {
        self::$instance = null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function getProjectRoot(): string
    {
        return $this->data['project_root'];
    }

    public function getStoragePath(string $subpath = ''): string
    {
        $path = $this->data['storage_path'];
        if ($subpath !== '') {
            $path .= '/' . ltrim($subpath, '/');
        }
        return $path;
    }

    public function getBackupPath(): string
    {
        return $this->getStoragePath('backups');
    }

    public function getLogPath(): string
    {
        return $this->getStoragePath('logs');
    }

    public function getUpdatePath(): string
    {
        return $this->getStoragePath('updates');
    }

    public function getStatusPath(): string
    {
        return $this->getStoragePath('updates/status');
    }

    public function getLockPath(): string
    {
        return $this->getStoragePath('updates/locks');
    }

    public function getDownloadPath(): string
    {
        return $this->getStoragePath('updates/downloads');
    }

    public function getExtractPath(): string
    {
        return $this->getStoragePath('updates/extracts');
    }

    public function getExcludedPaths(): array
    {
        return $this->data['excluded_paths'];
    }

    public function getPreservedFiles(): array
    {
        return $this->data['preserved_files'];
    }

    public function isExcluded(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        foreach ($this->getExcludedPaths() as $excluded) {
            if (str_starts_with($path, $excluded) || str_contains($path, '/' . $excluded)) {
                return true;
            }
        }
        return false;
    }

    public function isPreserved(string $path): bool
    {
        foreach ($this->getPreservedFiles() as $preserved) {
            if ($path === $preserved || str_starts_with($path, $preserved)) {
                return true;
            }
        }
        return false;
    }
}
