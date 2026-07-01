<?php

declare(strict_types=1);

namespace Updater\Support;

function updater_version_compare(string $current, string $target, string $operator = '>'): bool
{
    return version_compare($current, $target, $operator);
}

function updater_hash_file(string $filePath, string $algo = 'sha256'): string
{
    if (!file_exists($filePath)) {
        throw new \RuntimeException("File not found: {$filePath}");
    }

    $contents = file_get_contents($filePath);
    if ($contents === false) {
        throw new \RuntimeException("Failed to read file: {$filePath}");
    }

    return hash($algo, $contents);
}

function updater_hash_content(string $content, string $algo = 'sha256'): string
{
    return hash($algo, $content);
}

function updater_ensure_directory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

function updater_normalize_path(string $path): string
{
    return str_replace(['\\', '//'], '/', trim($path, '/'));
}

function updater_file_size_human(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float) $bytes;

    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }

    return round($size, 2) . ' ' . $units[$i];
}

function updater_timestamp(): string
{
    return date('Y-m-d H:i:s');
}
