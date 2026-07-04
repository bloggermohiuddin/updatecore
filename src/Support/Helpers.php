<?php

declare(strict_types=1);

namespace UpdateCore\Support;

class Helpers
{
    public static function generateJobId(): string
    {
        return date('Ymd') . '-' . bin2hex(random_bytes(8));
    }

    public static function generateNonce(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= 1024 ** $pow;
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public static function recursiveCopy(string $source, string $dest): bool
    {
        if (!is_dir($source)) {
            $dir = dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            return copy($source, $dest);
        }

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $dir = dir($source);
        while (false !== ($entry = $dir->read())) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcPath = $source . '/' . $entry;
            $dstPath = $dest . '/' . $entry;
            if (is_dir($srcPath)) {
                self::recursiveCopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        $dir->close();
        return true;
    }

    public static function recursiveDelete(string $dir): bool
    {
        if (!is_dir($dir)) {
            return file_exists($dir) ? unlink($dir) : true;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::recursiveDelete($path) : unlink($path);
        }
        return rmdir($dir);
    }

    public static function safeFilePut(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $temp = $path . '.tmp';
        if (file_put_contents($temp, $content) === false) {
            return false;
        }
        return rename($temp, $path);
    }

    public static function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public static function timestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function normalizePath(string $path): string
    {
        return str_replace(['\\', '//'], '/', trim($path, '/'));
    }
}
