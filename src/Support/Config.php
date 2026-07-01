<?php

declare(strict_types=1);

namespace Updater\Support;

class Config
{
    private static ?Config $instance = null;

    private array $data = [];

    private array $defaults = [
        'provider'       => 'github',
        'repository'     => '',
        'branch'         => 'main',
        'token'          => '',
        'api_url'        => '',
        'api_token'      => '',
        'base_path'      => '',
        'storage_path'   => '',
        'backup_enabled' => true,
        'backup_max'     => 10,
        'timeout'        => 60,
    ];

    private function __construct(array $config = [])
    {
        $this->data = array_merge($this->defaults, $config);

        if (empty($this->data['base_path'])) {
            $this->data['base_path'] = defined('UPDATER_ROOT') ? UPDATER_ROOT : dirname(__DIR__, 2);
        }

        if (empty($this->data['storage_path'])) {
            $this->data['storage_path'] = defined('UPDATER_STORAGE') ? UPDATER_STORAGE : $this->data['base_path'] . '/storage';
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

    public function getStoragePath(string $subpath = ''): string
    {
        $path = $this->data['storage_path'];
        if ($subpath !== '') {
            $path .= '/' . ltrim($subpath, '/');
        }
        return $path;
    }

    public function getBasePath(string $subpath = ''): string
    {
        $path = $this->data['base_path'];
        if ($subpath !== '') {
            $path .= '/' . ltrim($subpath, '/');
        }
        return $path;
    }

}
