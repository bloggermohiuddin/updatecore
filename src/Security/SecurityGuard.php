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

    public function validateAdmin(): bool
    {
        $secretKey = (string) ($this->config->get('secret_key', '') ?? '');
        if ($secretKey !== '') {
            $provided = $_SERVER['HTTP_X_UPDATE_SECRET'] ?? $_POST['update_secret'] ?? '';
            if (!is_string($provided) || $provided === '' || !hash_equals($secretKey, $provided)) {
                $this->logSecurityEvent('admin_auth_failed', ['reason' => 'secret_mismatch']);
                return false;
            }
        }

        $allowedIps = $this->config->get('allowed_ips', []);
        if (!empty($allowedIps)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!in_array($ip, (array) $allowedIps, true)) {
                $this->logSecurityEvent('admin_auth_failed', ['reason' => 'ip_denied', 'ip' => $ip]);
                return false;
            }
        }

        return true;
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
