<?php

declare(strict_types=1);

namespace Updater\Support;

class Cache
{
    private string $cachePath;
    private int $defaultTtl;

    public function __construct(?Config $config = null, int $defaultTtl = 3600)
    {
        $config = $config ?? Config::make();
        $this->cachePath = $config->getStoragePath('cache');
        $this->defaultTtl = $defaultTtl;
        updater_ensure_directory($this->cachePath);
    }

    public function get(string $key): mixed
    {
        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = file_get_contents($file);
        if ($data === false) {
            return null;
        }

        $cached = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($cached['expires_at']) || time() > $cached['expires_at']) {
            $this->forget($key);
            return null;
        }

        return $cached['value'] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): self
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $file = $this->getCacheFile($key);

        $cached = [
            'key'        => $key,
            'value'      => $value,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
        ];

        file_put_contents(
            $file,
            json_encode($cached, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        return $this;
    }

    public function forget(string $key): self
    {
        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
        return $this;
    }

    public function clear(): self
    {
        $files = glob($this->cachePath . '/*.cache');
        if (is_array($files)) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        return $this;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function getMultiple(array $keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }

    public function setMultiple(array $values, ?int $ttl = null): self
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return $this;
    }

    public function flushExpired(): self
    {
        $files = glob($this->cachePath . '/*.cache');
        if (!is_array($files)) {
            return $this;
        }

        foreach ($files as $file) {
            $data = file_get_contents($file);
            if ($data === false) {
                continue;
            }

            $cached = json_decode($data, true);
            if (isset($cached['expires_at']) && time() > $cached['expires_at']) {
                unlink($file);
            }
        }

        return $this;
    }

    private function getCacheFile(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return $this->cachePath . '/' . $safeKey . '.cache';
    }
}
