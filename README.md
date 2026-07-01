<div align="center">

# UpdateCore

**Framework-agnostic PHP auto-update engine for self-hosted applications.**

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Active%20Development-orange.svg?style=flat-square)](https://github.com/bloggermohiuddin/updatecore)

<br>

A lightweight yet powerful update framework that enables self-hosted PHP applications to
detect, download, verify, and install updates from remote sources — automatically.

[Getting Started](#installation) · [Documentation](#configuration) · [Report Bug](https://github.com/bloggermohiuddin/updatecore/issues)

</div>

---

## Project Status

> UpdateCore is currently under **active development**.
>
> The core update engine is functional and tested. Additional providers, CLI tooling, and a web dashboard are planned for upcoming releases.
>
> API surface may change before v1.0.0. Use in production at your own discretion.

---

## Overview

Keeping self-hosted applications up to date is one of the most overlooked challenges in PHP development. Manual updates are error-prone, risky, and time-consuming. **UpdateCore** solves this by providing a complete, reusable auto-update engine that integrates into any PHP project — regardless of framework, architecture, or hosting environment.

UpdateCore uses a **manifest-based architecture**. Your remote server hosts a `update.json` manifest that describes the latest release: version, file hashes, deletions, and migrations. The framework compares local hashes against the remote manifest, downloads only the changed files, backs up the originals, replaces them safely, runs any necessary database migrations, and rolls back automatically if anything fails.

Whether you are building a SaaS platform, a CMS, an admin panel, a CRM, or a REST API service, UpdateCore gives you production-grade update infrastructure in under 20 files.

---

## Update Flow

```text
Application starts
        │
        ▼
┌─────────────────┐
│  Check Manifest  │──→ Fetch update.json from remote
└────────┬────────┘
         ▼
┌─────────────────────┐
│  Compare Local Hash │──→ Detect changed files
└────────┬────────────┘
         ▼
┌────────────────────┐
│ Download Files     │──→ Only changed files, zero waste
└────────┬───────────┘
         ▼
┌────────────────────┐
│  Verify SHA256     │──→ Hash validation before write
└────────┬───────────┘
         ▼
┌────────────────────┐
│  Create Backup     │──→ Snapshot originals
└────────┬───────────┘
         ▼
┌────────────────────┐
│  Replace Files     │──→ Atomic write with tmp file
└────────┬───────────┘
         ▼
┌────────────────────┐
│  Run Migrations    │──→ SQL / PHP migration files
└────────┬───────────┘
         ▼
┌────────────────────┐
│  Update Version    │──→ Write new version + history
└────────┬───────────┘
         ▼
       Success
         │
         ▼ (on failure at any step)
┌────────────────────┐
│  Automatic Rollback│──→ Restore backup + previous version
└────────────────────┘
```

---

## Features

- **Manifest-Based Updates** — Remote `update.json` defines version, files, deletions, and migrations
- **Incremental File Downloads** — Only changed files are downloaded; zero unnecessary bandwidth
- **SHA256 Integrity Verification** — Every file is hash-verified before writing to disk
- **Automatic Rollback** — If any step fails, the system restores the previous state
- **Backup System** — Full backup of affected files before any modification, with configurable retention
- **Database Migrations** — Run SQL or PHP migration files as part of the update process
- **GitHub Provider** — Pull manifests and files directly from GitHub repository releases
- **Custom API Provider** — Connect to any REST API endpoint hosting your update artifacts
- **Update Channels** — Support for `stable`, `beta`, `dev`, and `nightly` release channels
- **Package Management** — Install, update, and remove modular packages and plugins
- **Progress Tracking** — Real-time progress data for future web dashboards and CLI tools
- **Structured Logging** — Every operation is logged with timestamps, levels, and context
- **File-Level Caching** — Cached manifests reduce redundant remote requests
- **Framework Independent** — Pure PHP 8.2+; no Laravel, Symfony, or CodeIgniter dependency
- **PSR Compliant** — Follows PSR-4 autoloading and modern PHP coding standards

---

## Why UpdateCore?

| Feature | UpdateCore | Manual Update | Custom Script |
| :--- | :---: | :---: | :---: |
| Manifest-based architecture | ✅ | ❌ | ⚠️ Partial |
| Incremental file downloads | ✅ | ❌ | ⚠️ Partial |
| SHA256 file verification | ✅ | ❌ | ⚠️ Partial |
| Automatic rollback | ✅ | ❌ | ❌ |
| Pre-update backups | ✅ | ❌ | ⚠️ Manual |
| Database migrations | ✅ | ❌ | ⚠️ Manual |
| Multiple providers | ✅ | ❌ | ❌ |
| Update channels | ✅ | ❌ | ❌ |
| Package management | ✅ | ❌ | ❌ |
| Progress tracking | ✅ | ❌ | ❌ |
| Framework independent | ✅ | ✅ | ✅ |
| No vendor lock-in | ✅ | ✅ | ✅ |

---

## Installation

### Via Composer

```bash
composer require bloggermohiuddin/updatecore
```

---

## Quick Start

### 1. Configure the Updater

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Updater\Core\Updater;

$updater = Updater::make([
    'provider'   => 'github',
    'repository' => 'your-username/your-repo',
    'token'      => 'ghp_your_personal_access_token',
    'channel'    => 'stable',
]);
```

### 2. Check for Updates

```php
$result = $updater->check();

if ($result['available']) {
    echo "Update available: {$result['local']} → {$result['remote']}";
    echo "Files to update: " . count($result['changed_files']);
    echo "Download size: {$result['total_size_human']}";
}
```

### 3. Install the Update

```php
// Simple update (no database migrations)
$success = $updater->update();

// Update with database migrations
$success = $updater->update(function () {
    return new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
});
```

### 4. One-Liner Update

```php
Updater::make($config)->update();
```

---

## Configuration

### Full Configuration Reference

| Key | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `provider` | `string` | `'github'` | Update source provider (`github` or `api`) |
| `repository` | `string` | `''` | GitHub repository in `owner/repo` format |
| `branch` | `string` | `'main'` | Branch or release tag to pull from |
| `token` | `string` | `''` | GitHub personal access token for private repos |
| `api_url` | `string` | `''` | Base URL for custom API provider |
| `api_token` | `string` | `''` | Authentication token for custom API |
| `channel` | `string` | `'stable'` | Release channel (`stable`, `beta`, `dev`, `nightly`) |
| `base_path` | `string` | auto-detected | Root path of the application being updated |
| `storage_path` | `string` | auto-detected | Path for logs, backups, and cache |
| `backup_enabled` | `bool` | `true` | Enable or disable automatic backups |
| `backup_max` | `int` | `10` | Maximum number of backups to retain |
| `timeout` | `int` | `60` | HTTP request timeout in seconds |

### Environment-Specific Configuration

```php
// Production
$updater = Updater::make([
    'provider'       => 'github',
    'repository'     => 'myorg/myapp',
    'token'          => getenv('UPDATE_TOKEN'),
    'channel'        => 'stable',
    'backup_enabled' => true,
]);

// Development
$updater = Updater::make([
    'provider'       => 'github',
    'repository'     => 'myorg/myapp',
    'channel'        => 'dev',
    'backup_enabled' => false,
]);
```

---

## Update Manifest Format

The remote server hosts `update.json` files organized by channel. The manifest defines everything about a release.

### Manifest Structure

```json
{
    "version": "2.1.0",
    "channel": "stable",
    "release_date": "2026-07-01",

    "files": [
        {
            "path": "app/User.php",
            "hash": "a1b2c3d4e5f6...",
            "size": 25120
        },
        {
            "path": "config/database.php",
            "hash": "f6e5d4c3b2a1...",
            "size": 3100
        }
    ],

    "deleted": [
        "legacy/old_handler.php"
    ],

    "migrations": [
        "migration_14.sql",
        "migration_15_add_index.php"
    ]
}
```

### Field Reference

| Field | Type | Required | Description |
| :--- | :--- | :---: | :--- |
| `version` | `string` | ✅ | Semantic version number (e.g., `2.1.0`) |
| `channel` | `string` | ✅ | Release channel (`stable`, `beta`, `dev`, `nightly`) |
| `release_date` | `string` | ❌ | ISO 8601 date of the release |
| `files` | `array` | ✅ | Array of files included in this release |
| `files[].path` | `string` | ✅ | Relative path to the file from project root |
| `files[].hash` | `string` | ✅ | SHA256 hash of the file contents |
| `files[].size` | `int` | ✅ | File size in bytes |
| `deleted` | `array` | ❌ | Files to remove during update |
| `migrations` | `array` | ❌ | Migration files to execute during update |

---

## Providers

UpdateCore ships with two built-in providers.

### GitHub Provider

Pulls manifests and files from a GitHub repository using the GitHub API.

```php
$updater = Updater::make([
    'provider'   => 'github',
    'repository' => 'your-username/your-repo',
    'token'      => 'ghp_xxxxxxxxxxxxxxxxxxxx',
    'channel'    => 'stable',
]);
```

**How it works:**

- Fetches `update.json` from `{repository}/contents/{channel}/update.json`
- Downloads individual files from `{repository}/contents/{channel}/files/{path}`
- Supports public and private repositories via token authentication

**Remote file structure:**

```
your-repo/
├── stable/
│   ├── update.json
│   └── files/
│       ├── app/
│       │   └── User.php
│       └── config/
│           └── database.php
├── beta/
│   ├── update.json
│   └── files/
└── dev/
    ├── update.json
    └── files/
```

### Custom API Provider

Connect to any REST API endpoint that serves update manifests and files.

```php
$updater = Updater::make([
    'provider'   => 'api',
    'api_url'    => 'https://updates.yourapp.com',
    'api_token'  => 'your-api-token',
    'channel'    => 'stable',
]);
```

**Expected API endpoints:**

| Endpoint | Method | Description |
| :--- | :--- | :--- |
| `/{channel}/update.json` | GET | Fetch update manifest |
| `/{channel}/files/{path}` | GET | Download individual file |
| `/packages/{channel}/{name}/update.json` | GET | Fetch package manifest |
| `/packages/{channel}/{name}/files/{path}` | GET | Download package file |
| `/health` | GET | Connection health check |

---

## Package Management

UpdateCore includes a package manager for modular plugin and extension systems.

### Install a Package

```php
$updater->installPackage('bkash-payment');
```

### Update a Package

```php
$updater->updatePackage('sms-system');
```

### Remove a Package

```php
$updater->removePackage('old-plugin');
```

> **Note:** Package management requires a compatible API server that serves package manifests and files at the expected endpoints.

---

## Rollback System

UpdateCore implements a rollback mechanism that activates automatically on failure.

### Automatic Rollback

When an update fails at any stage, the system automatically restores the previous state:

```php
try {
    $updater->update();
} catch (\Exception $e) {
    // Rollback already executed automatically
    // Previous files and version are restored
    echo "Update failed and was rolled back: " . $e->getMessage();
}
```

### Manual Rollback

Roll back to the most recent backup:

```php
$updater->rollback();
```

Roll back to a specific backup:

```php
$updater->rollbackTo('2026-07-01_10-30-00_abc123def');
```

Roll back to a specific version:

```php
$updater->rollbackToVersion('2.0.0');
```

### View Available Rollbacks

```php
$backups = $updater->getBackups();

foreach ($backups as $backup) {
    echo "Backup ID: {$backup['id']}";
    echo "Created: {$backup['created_at']}";
    echo "Files: " . count($backup['files']);
}
```

---

## Security

Security is a core design principle of UpdateCore. Every update operation includes multiple layers of protection.

### SHA256 File Verification

Every file in the manifest includes a SHA256 hash. UpdateCore verifies:

1. **Pre-write verification** — Downloaded content is hashed and compared before writing
2. **Post-write verification** — Written file is re-hashed to confirm integrity
3. **Corrupted file rejection** — Files that fail verification are never written to disk

### Safe File Replacement

UpdateCore uses atomic file operations:

1. New content is written to a temporary `.tmp` file
2. The temporary file is verified
3. The original file is replaced atomically
4. Permissions are set to `0644`

This prevents partial writes and corrupted states.

### Integrity Checking

```php
$issues = $updater->checkIntegrity();

if (!empty($issues)) {
    foreach ($issues as $issue) {
        echo "Path: {$issue['path']}\n";
        echo "Reason: {$issue['reason']}\n";
    }
}
```

---

## Logging

Every operation in UpdateCore is logged with timestamps, levels, and contextual data.

### Log Location

```
storage/logs/updater-2026-07-01.log
```

### Log Levels

| Level | Usage |
| :--- | :--- |
| `INFO` | Normal operations (checking, downloading, replacing) |
| `SUCCESS` | Completed operations |
| `WARNING` | Non-critical issues |
| `ERROR` | Failed operations |
| `DEBUG` | Detailed diagnostic information |

### Example Log Output

```
[2026-07-01 10:30:00] [INFO] Checking for updates...
[2026-07-01 10:30:01] [INFO] Local version: 2.0.0
[2026-07-01 10:30:02] [INFO] Manifest fetched successfully from GitHub
[2026-07-01 10:30:02] [INFO] Update available {"local":"2.0.0","remote":"2.1.0","changed":5}
[2026-07-01 10:30:02] [INFO] Creating backup...
[2026-07-01 10:30:02] [INFO] Backup created {"id":"2026-07-01_10-30-02_abc123","files":5}
[2026-07-01 10:30:03] [INFO] Downloading file {"path":"app/User.php","size":25120}
[2026-07-01 10:30:03] [INFO] File written successfully {"path":"app/User.php","size":25120}
[2026-07-01 10:30:04] [SUCCESS] Update installed successfully {"version":"2.1.0"}
```

### Accessing Logs Programmatically

```php
$recentLogs = $updater->getRecentLogs(100);

foreach ($recentLogs as $line) {
    echo $line . "\n";
}
```

---

## Update Channels

UpdateCore supports multiple release channels for managing different stages of your release pipeline.

### Available Channels

| Channel | Purpose | Stability |
| :--- | :--- | :--- |
| `stable` | Production releases | Highest |
| `beta` | Pre-release testing | High |
| `dev` | Development builds | Medium |
| `nightly` | Automated daily builds | Low |

### Switching Channels

```php
$updater->setChannel('beta');
$result = $updater->check();
```

### Remote Directory Structure

```
your-update-server/
├── stable/
│   ├── update.json
│   └── files/
├── beta/
│   ├── update.json
│   └── files/
├── dev/
│   ├── update.json
│   └── files/
└── nightly/
    ├── update.json
    └── files/
```

---

## Project Structure

```
updatecore/
├── composer.json
├── bootstrap.php
├── src/
│   ├── Core/
│   │   ├── Updater.php              # Main entry point
│   │   ├── UpdateChecker.php        # Remote update detection
│   │   ├── Installer.php            # File download & installation
│   │   └── Rollback.php             # Backup restoration
│   ├── Providers/
│   │   ├── GitHubProvider.php       # GitHub Releases API
│   │   └── ApiProvider.php          # Custom REST API
│   ├── Managers/
│   │   ├── VersionManager.php       # Semantic version tracking
│   │   ├── FileManager.php          # File operations & verification
│   │   ├── BackupManager.php        # Backup creation & restore
│   │   └── MigrationManager.php     # Database migration runner
│   ├── Manifest/
│   │   └── ManifestParser.php       # update.json parser & validator
│   └── Support/
│       ├── Config.php               # Singleton configuration
│       ├── Logger.php               # File-based logging
│       ├── Cache.php                # File-based caching
│       └── Helpers.php              # Utility functions
└── storage/
    ├── logs/
    ├── backups/
    ├── cache/
    └── migrations/
```

---

## API Reference

| Method | Description |
| :--- | :--- |
| `Updater::make(array $config)` | Create a new updater instance |
| `$updater->check()` | Check for available updates |
| `$updater->update()` | Execute the full update flow |
| `$updater->rollback()` | Roll back to the most recent backup |
| `$updater->rollbackTo(string $id)` | Roll back to a specific backup |
| `$updater->rollbackToVersion(string $v)` | Roll back to a specific version |
| `$updater->installPackage(string $name)` | Install a package |
| `$updater->updatePackage(string $name)` | Update a package |
| `$updater->removePackage(string $name)` | Remove a package |
| `$updater->getProgress()` | Get current operation progress |
| `$updater->getDashboardData()` | Get all data for web dashboard |
| `$updater->getLocalVersion()` | Get current local version |
| `$updater->getVersionInfo()` | Get detailed version information |
| `$updater->getUpdateHistory()` | Get history of all updates |
| `$updater->getBackups()` | List all available backups |
| `$updater->checkIntegrity()` | Verify local file integrity |
| `$updater->getMigrationStatus()` | Get migration execution status |
| `$updater->getRecentLogs(int $n)` | Get recent log entries |
| `$updater->testConnection()` | Test remote connection |
| `$updater->setChannel(string $c)` | Switch update channel |
| `$updater->clearCache()` | Clear cached manifests |

---

## Roadmap

- [ ] GitLab provider
- [ ] Bitbucket provider
- [ ] CLI command-line interface
- [ ] Web dashboard with real-time progress
- [ ] Signature verification enforcement
- [ ] Differential patch updates
- [ ] Webhook notifications on update events
- [ ] Package dependency resolution
- [ ] Update scheduling and cron integration
- [ ] Multi-language migration support

---

## Contributing

Contributions are welcome. To contribute:

1. **Fork** the repository
2. **Create** a feature branch (`git checkout -b feature/amazing-feature`)
3. **Commit** your changes (`git commit -m 'Add amazing feature'`)
4. **Push** to the branch (`git push origin feature/amazing-feature`)
5. **Open** a Pull Request

### Code Standards

- PHP 8.2+ with strict types
- PSR-4 autoloading
- PSR-12 coding style
- No framework dependencies
- All methods must include type declarations
- README must match code — never document unimplemented features

### Reporting Issues

Please use the [GitHub Issues](https://github.com/bloggermohiuddin/updatecore/issues) tracker. Include:

- PHP version
- OS/environment
- Steps to reproduce
- Expected vs actual behavior
- Relevant log output

---

## License

This project is licensed under the **MIT License**. See the [LICENSE](LICENSE) file for details.

---

<div align="center">

### Author

**MD Mohiuddin**

[![GitHub](https://img.shields.io/badge/GitHub-bloggermohiuddin-181717.svg?style=flat-square&logo=github)](https://github.com/bloggermohiuddin)

<br>

Designed and developed by MD Mohiuddin.

Focused on building scalable self-hosted PHP infrastructure, SaaS products, and reusable backend systems.

<br>

**If you find UpdateCore useful, please consider giving it a star on GitHub.**

</div>
