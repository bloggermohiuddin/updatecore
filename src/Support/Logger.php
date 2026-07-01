<?php

declare(strict_types=1);

namespace Updater\Support;

class Logger
{
    private string $logPath;
    private ?string $currentFile = null;
    private array $buffer = [];
    private bool $enabled = true;

    public function __construct(?Config $config = null)
    {
        $config = $config ?? Config::make();
        $this->logPath = $config->getStoragePath('logs');
        updater_ensure_directory($this->logPath);
        $this->rotateIfNeeded();
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function log(string $level, string $message, array $context = []): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $timestamp = updater_timestamp();
        $contextStr = $context ? ' ' . json_encode($context, JSON_THROW_ON_ERROR) : '';
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}";

        $this->buffer[] = $line;

        if (count($this->buffer) >= 50) {
            $this->flush();
        }

        return $this;
    }

    public function info(string $message, array $context = []): self
    {
        return $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): self
    {
        return $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): self
    {
        return $this->log('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): self
    {
        return $this->log('DEBUG', $message, $context);
    }

    public function success(string $message, array $context = []): self
    {
        return $this->log('SUCCESS', $message, $context);
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $file = $this->getLogFile();
        $content = implode(PHP_EOL, $this->buffer) . PHP_EOL;

        file_put_contents($file, $content, FILE_APPEND | LOCK_EX);
        $this->buffer = [];
    }

    public function getRecentLogs(int $lines = 50): array
    {
        $file = $this->getLogFile();
        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $allLines = array_filter(explode(PHP_EOL, trim($content)));
        return array_slice($allLines, -$lines);
    }

    public function getLogFiles(): array
    {
        $files = glob($this->logPath . '/updater-*.log');
        if ($files === false) {
            return [];
        }

        rsort($files);
        return $files;
    }

    public function clearLogs(): self
    {
        $files = $this->getLogFiles();
        foreach ($files as $file) {
            unlink($file);
        }
        return $this;
    }

    private function getLogFile(): string
    {
        if ($this->currentFile !== null && file_exists($this->currentFile)) {
            return $this->currentFile;
        }

        $date = date('Y-m-d');
        $this->currentFile = $this->logPath . '/updater-' . $date . '.log';
        return $this->currentFile;
    }

    private function rotateIfNeeded(): void
    {
        $files = $this->getLogFiles();
        $maxFiles = 30;

        if (count($files) > $maxFiles) {
            $toDelete = array_slice($files, $maxFiles);
            foreach ($toDelete as $file) {
                unlink($file);
            }
        }
    }

    public function __destruct()
    {
        $this->flush();
    }
}
