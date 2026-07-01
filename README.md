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

UpdateCore uses a **manifest-free architecture**. No `update.json` to maintain. The framework reads your GitHub repository's file tree directly via the GitHub API, compares blob SHAs against local files, downloads only the changed files, backs up the originals, replaces them safely, and rolls back automatically if anything fails.

Whether you are building a SaaS platform, a CMS, an admin panel, a CRM, or a REST API service, UpdateCore gives you production-grade update infrastructure in under 20 files.

---

## How It Works

```text
┌──────────────────────────────────────────────────────────┐
│                    Your PHP Application                   │
│                                                          │
│  require 'vendor/autoload.php';                          │
│                                                          │
│  $updater = Updater::make([                              │
│      'provider'   => 'github',                           │
│      'repository' => 'you/app',                          │
│  ]);                                                     │
│                                                          │
│  $updater->update();                                     │
└──────────────────────────────────────────────────────────┘
                          │
                          ▼
┌──────────────────────────────────────────────────────────┐
│                  UpdateCore Engine                        │
│                                                          │
│  1. Fetch latest commit from GitHub                      │
│  2. Compare with local last_check.json                   │
│  3. Fetch file tree (blob SHAs)                          │
│  4. Compare SHAs against local files                     │
│  5. Download only changed files                          │
│  6. Verify SHA integrity                                 │
│  7. Backup originals                                     │
│  8. Replace files atomically                             │
│  9. Save state to last_check.json                        │
│  10. Rollback automatically on failure                   │
└──────────────────────────────────────────────────────────┘
                          │
                          ▼
┌──────────────────────────────────────────────────────────┐
│              GitHub Repository (Remote Source)            │
│                                                          │
│  your-app/                                               │
│  ├── src/                                                │
│  ├── config/                                             │
│  ├── public/                                             │
│  └── ... (your project files)                            │
│                                                          │
│  No update.json needed.                                  │
│  Just push your code.                                    │
└──────────────────────────────────────────────────────────┘
```

---

## Features

- **No Manifest Required** — Reads GitHub file tree directly, no `update.json` to maintain
- **Git SHA Comparison** — Compares blob SHAs, same mechanism as Git itself
- **Incremental Downloads** — Only changed files are downloaded; zero unnecessary bandwidth
- **SHA256 Integrity Verification** — Every file is verified before writing to disk
- **Automatic Rollback** — If any step fails, the system restores the previous state
- **Backup System** — Full backup of affected files before any modification
- **Database Migrations** — Run SQL or PHP migration files as part of the update process
- **GitHub Provider** — Direct integration with GitHub API
- **Custom API Provider** — Connect to any REST API endpoint
- **Local State Tracking** — `last_check.json` tracks commit, hash, timestamps, history
- **Package Management** — Install, update, and remove modular packages
- **Progress Tracking** — Real-time progress data for dashboards and CLI tools
- **Structured Logging** — Every operation is logged with timestamps and context
- **File-Level Caching** — Cached tree data reduces API calls
- **Framework Independent** — Pure PHP 8.2+; works with Laravel, CodeIgniter, raw PHP, anything
- **PSR Compliant** — PSR-4 autoloading, PSR-12 coding style

---

## Why UpdateCore?

| Feature | UpdateCore | Manual Update | Git Pull |
| :--- | :---: | :---: | :---: |
| No manifest maintenance | ✅ | ❌ | ✅ |
| Incremental file downloads | ✅ | ❌ | ❌ |
| SHA verification | ✅ | ❌ | ❌ |
| Automatic rollback | ✅ | ❌ | ❌ |
| Pre-update backups | ✅ | ❌ | ❌ |
| Database migrations | ✅ | ❌ | ❌ |
| State tracking | ✅ | ❌ | ❌ |
| Works without SSH | ✅ | ❌ | ❌ |
| Framework independent | ✅ | ✅ | ✅ |
| Safe atomic writes | ✅ | ❌ | ❌ |

---

## Installation

### Via Composer

```bash
composer require bloggermohiuddin/updatecore
```

[![Packagist](https://img.shields.io/packagist/v/bloggermohiuddin/updatecore.svg?style=flat-square)](https://packagist.org/packages/bloggermohiuddin/updatecore)

---

## Quick Start

### 1. Configure

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Updater\Core\Updater;

$updater = Updater::make([
    'provider'   => 'github',
    'repository' => 'your-username/your-repo',
    'token'      => 'ghp_your_personal_access_token',
]);
```

### 2. Check for Updates

```php
$result = $updater->check();

if ($result['available']) {
    echo "Update available!";
    echo "From: {$result['commit']}";
    echo "Files: " . count($result['changed_files']);
    echo "Size: {$result['total_size_human']}";
}
```

### 3. Install the Update

```php
$success = $updater->update();
```

### 4. One-Liner

```php
Updater::make($config)->update();
```

---

## Configuration

| Key | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `provider` | `string` | `'github'` | Update source (`github` or `api`) |
| `repository` | `string` | `''` | GitHub repo in `owner/repo` format |
| `branch` | `string` | `'main'` | Branch to pull from |
| `token` | `string` | `''` | GitHub personal access token |
| `api_url` | `string` | `''` | Base URL for custom API provider |
| `api_token` | `string` | `''` | Authentication token for API |
| `base_path` | `string` | auto | Root path of your application |
| `storage_path` | `string` | auto | Path for logs, backups, cache |
| `backup_enabled` | `bool` | `true` | Enable automatic backups |
| `backup_max` | `int` | `10` | Maximum backups to retain |
| `timeout` | `int` | `60` | HTTP timeout in seconds |

---

## Local State File

After each check or update, UpdateCore saves state to `storage/last_check.json`:

```json
{
    "commit": "8108b1ef4b2c9a3d...",
    "short_hash": "8108b1e",
    "version": "8108b1e",
    "last_checked_at": "2026-07-01 12:00:00",
    "last_updated_at": "2026-07-01 12:05:00",
    "files_updated": 5,
    "files": {},
    "history": [
        {
            "from": "cdbbf47...",
            "to": "8108b1e...",
            "short_hash": "8108b1e",
            "files_count": 5,
            "timestamp": "2026-07-01 12:05:00"
        }
    ]
}
```

---

## Providers

### GitHub Provider

Uses GitHub API to fetch file tree and download files. No manifest needed.

```php
$updater = Updater::make([
    'provider'   => 'github',
    'repository' => 'your-username/your-repo',
    'token'      => 'ghp_xxx',
]);
```

**API endpoints used:**

| Endpoint | Purpose |
| :--- | :--- |
| `GET /repos/{repo}/commits/{branch}` | Get latest commit |
| `GET /repos/{repo}/git/trees/{branch}?recursive=1` | Get file tree with blob SHAs |
| `GET /repos/{repo}/contents/{path}` | Download individual file |

### Custom API Provider

Connect to any REST API that serves file trees.

```php
$updater = Updater::make([
    'provider'   => 'api',
    'api_url'    => 'https://updates.yourapp.com',
    'api_token'  => 'your-token',
]);
```

**Expected endpoints:**

| Endpoint | Method | Description |
| :--- | :--- | :--- |
| `/commit` | GET | Latest commit info |
| `/files` | GET | File tree with SHAs |
| `/files/{path}` | GET | Download file |
| `/health` | GET | Connection test |

---

## Package Management

```php
$updater->installPackage('bkash-payment');
$updater->updatePackage('sms-system');
$updater->removePackage('old-plugin');
```

---

## Rollback System

### Automatic Rollback

```php
try {
    $updater->update();
} catch (\Exception $e) {
    // Rollback executed automatically
}
```

### Manual Rollback

```php
$updater->rollback();
$updater->rollbackTo('backup-id');
$updater->rollbackToVersion('2.0.0');
```

---

## Security

- **Git SHA Verification** — Uses same SHA mechanism as Git itself
- **Atomic File Writes** — Temp file → verify → replace (no partial writes)
- **Pre-write Validation** — Content verified before any file modification
- **Backup Before Change** — Every file backed up before replacement

---

## Logging

Logs stored in `storage/logs/`:

```
[2026-07-01 10:30:00] [INFO] Checking for updates...
[2026-07-01 10:30:01] [INFO] Local state {"commit":"8108b1e","version":"0.0.0"}
[2026-07-01 10:30:02] [INFO] File tree fetched from GitHub {"files":45}
[2026-07-01 10:30:02] [INFO] File comparison complete {"changed":5,"deleted":1}
[2026-07-01 10:30:03] [INFO] Downloading file {"path":"app/User.php","size":25120}
[2026-07-01 10:30:04] [SUCCESS] Update installed successfully {"commit":"a1b2c3d"}
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
│   │   ├── UpdateChecker.php        # Commit & tree comparison
│   │   ├── Installer.php            # Download & install files
│   │   └── Rollback.php             # Backup restoration
│   ├── Providers/
│   │   ├── GitHubProvider.php       # GitHub API integration
│   │   └── ApiProvider.php          # Custom API integration
│   ├── Managers/
│   │   ├── VersionManager.php       # Version tracking
│   │   ├── FileManager.php          # File operations
│   │   ├── BackupManager.php        # Backup management
│   │   └── MigrationManager.php     # Database migrations
│   ├── Manifest/
│   │   └── ManifestParser.php       # Git SHA comparison engine
│   └── Support/
│       ├── Config.php               # Configuration
│       ├── Logger.php               # Logging
│       ├── Cache.php                # File caching
│       ├── StateManager.php         # Local state (last_check.json)
│       └── Helpers.php              # Utility functions
└── storage/
    ├── last_check.json              # Local state
    ├── logs/
    ├── backups/
    └── cache/
```

---

## Works With Any PHP Project

| Project Type | Supported |
| :--- | :---: |
| Laravel | ✅ |
| CodeIgniter | ✅ |
| Raw PHP | ✅ |
| WordPress (custom) | ✅ |
| SaaS Applications | ✅ |
| CRM Systems | ✅ |
| Admin Panels | ✅ |
| REST APIs | ✅ |
| CMS Projects | ✅ |

---

## Versioning

| Version | Stage | Description |
| :--- | :--- | :--- |
| `v0.1.0` | Experimental | Core engine, GitHub provider |
| `v0.2.0` | Current | Tree-based updates, state tracking |
| `v0.5.0` | Pre-release | Stable rollback, migration system |
| `v1.0.0` | Stable | Production ready, API frozen |

---

## Roadmap

- [ ] GitLab provider
- [ ] Bitbucket provider
- [ ] CLI command-line interface
- [ ] Web dashboard with real-time progress
- [ ] Differential patch updates
- [ ] Webhook notifications
- [ ] Package dependency resolution
- [ ] Cron integration

---

## Contributing

1. **Fork** the repository
2. **Create** a feature branch
3. **Commit** your changes
4. **Push** to the branch
5. **Open** a Pull Request

### Code Standards

- PHP 8.2+ with strict types
- PSR-4 autoloading
- PSR-12 coding style
- No framework dependencies
- README must match code

---

## License

MIT License. See [LICENSE](LICENSE).

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
