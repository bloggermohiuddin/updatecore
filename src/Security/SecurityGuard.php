<?php

declare(strict_types=1);

namespace UpdateCore\Security;

use UpdateCore\Support\Config;

class SecurityGuard
{
    private Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::make();
    }

    public function validateCsrf(?string $token = null): bool
    {
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public function generateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function createLockToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function logSecurityEvent(string $event, array $context = []): void
    {
        $data = [
            'event'      => $event,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp'  => date('Y-m-d H:i:s'),
            'context'    => $context,
        ];

        $logFile = $this->config->getLogPath() . '/security.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, json_encode($data) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
