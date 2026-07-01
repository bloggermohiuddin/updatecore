<?php

declare(strict_types=1);

namespace Updater\Support;

class StateManager
{
    private string $statePath;

    public function __construct(?Config $config = null)
    {
        $config = $config ?? Config::make();
        $this->statePath = $config->getStoragePath('last_check.json');
    }

    public function load(): array
    {
        if (!file_exists($this->statePath)) {
            return $this->getDefaults();
        }

        $data = json_decode(file_get_contents($this->statePath) ?: '{}', true, 512, JSON_THROW_ON_ERROR);

        return array_merge($this->getDefaults(), $data);
    }

    public function save(array $state): self
    {
        $current = $this->load();
        $merged = array_merge($current, $state);

        file_put_contents(
            $this->statePath,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $state = $this->load();
        return $state[$key] ?? $default;
    }

    public function set(string $key, mixed $value): self
    {
        $state = $this->load();
        $state[$key] = $value;

        file_put_contents(
            $this->statePath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        return $this;
    }

    public function getLastCommit(): string
    {
        return $this->get('commit', '');
    }

    public function setLastCommit(string $commit): self
    {
        return $this->set('commit', $commit);
    }

    public function getShortHash(): string
    {
        return $this->get('short_hash', '');
    }

    public function setShortHash(string $hash): self
    {
        return $this->set('short_hash', $hash);
    }

    public function getLastCheckedAt(): string
    {
        return $this->get('last_checked_at', 'never');
    }

    public function setLastCheckedAt(string $datetime = ''): self
    {
        return $this->set('last_checked_at', $datetime ?: updater_timestamp());
    }

    public function getLastUpdatedAt(): string
    {
        return $this->get('last_updated_at', 'never');
    }

    public function setLastUpdatedAt(string $datetime = ''): self
    {
        return $this->set('last_updated_at', $datetime ?: updater_timestamp());
    }

    public function getVersion(): string
    {
        return $this->get('version', '0.0.0');
    }

    public function setVersion(string $version): self
    {
        return $this->set('version', $version);
    }

    public function getFilesUpdated(): int
    {
        return (int) $this->get('files_updated', 0);
    }

    public function setFilesUpdated(int $count): self
    {
        return $this->set('files_updated', $count);
    }

    public function getHistory(): array
    {
        return $this->get('history', []);
    }

    public function addHistory(array $entry): self
    {
        $history = $this->getHistory();
        $history[] = array_merge($entry, [
            'timestamp' => updater_timestamp(),
        ]);

        $this->set('history', $history);
        return $this;
    }

    public function getFiles(): array
    {
        return $this->get('files', []);
    }

    public function setFiles(array $files): self
    {
        return $this->set('files', $files);
    }

    public function reset(): self
    {
        $this->save($this->getDefaults());
        return $this;
    }

    private function getDefaults(): array
    {
        return [
            'commit'           => '',
            'short_hash'       => '',
            'version'          => '0.0.0',
            'last_checked_at'  => 'never',
            'last_updated_at'  => 'never',
            'files_updated'    => 0,
            'files'            => [],
            'history'          => [],
        ];
    }
}
