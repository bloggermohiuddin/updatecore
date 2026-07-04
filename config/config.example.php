<?php

/**
 * UpdateCore Configuration Example
 *
 * Copy this file to your project and pass to Updater::make()
 */

return [
    // GitHub repository (owner/repo format)
    'repository' => 'your-username/your-repo',

    // Branch to track for updates
    'branch' => 'main',

    // GitHub personal access token (optional, for private repos)
    'github_token' => '',

    // Project root path (auto-detected if empty)
    'project_root' => '',

    // Storage path (auto-derived from project_root if empty)
    'storage_path' => '',

    // Enable automatic backups before update
    'backup_enabled' => true,

    // Maximum backups to keep (older ones auto-deleted)
    'backup_max' => 10,

    // Paths excluded from backup/update
    'excluded_paths' => [
        'storage',
        'uploads',
        '.git',
        'logs',
        '.maintenance',
        'version.json',
    ],

    // Files that are never overwritten during update
    'preserved_files' => [
        'config.php',
        'admin/config.php',
        '.htaccess',
        'admin/.htaccess',
    ],

    // Maintenance mode message
    'maintenance_message' => 'System update in progress. Please try again later.',

    // Lock file timeout in seconds (default: 1 hour)
    'lock_timeout' => 3600,

    // Log retention in days
    'log_retention_days' => 30,

    // PDO database connection (required for migration tracking and logging)
    // 'db' => new PDO('mysql:host=localhost;dbname=your_db', 'user', 'pass'),
];
