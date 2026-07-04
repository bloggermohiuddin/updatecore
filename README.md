<div align="center">

# UpdateCore

**Framework-agnostic PHP auto-update engine for self-hosted applications.**

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-8892BF.svg?style=flat-square&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Active%20Development-orange.svg?style=flat-square)](https://github.com/bloggermohiuddin/updatecore)

<br>

A lightweight yet powerful update framework that enables self-hosted PHP applications to
detect, download, and install updates from GitHub — automatically.

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

UpdateCore uses a **zip-based architecture**. It fetches the latest commit from GitHub, downloads the repository as a zip archive, extracts only the changed files, backs up the originals, replaces them safely, runs any pending migrations, and rolls back automatically if anything fails.

Whether you are building a SaaS platform, a CMS, an admin panel, a CRM, or a REST API service, UpdateCore gives you production-grade update infrastructure in under 20 files.

---

## How It Works

```text
┌──────────────────────────────────────────────────────────┐
│                    Your PHP Application                   │
│                                                          │
│  require 'vendor/autoload.php';                          │
│                                                          │
│  $updater = Updater::make($db, [                         │
│      'repository' => 'you/app',                          │
│  ]);                                                     │
│                                                          │
│  $result = $updater->execute();                          │
└──────────────────────────────────────────────────────────┘
                          │
                          ▼
┌──────────────────────────────────────────────────────────┐
│                  UpdateCore Engine                        │
│                                                          │
│  1. Lock updates (prevent concurrent runs)               │
│  2. Check admin authentication                           │
│  3. Fetch latest commit from GitHub                      │
│  4. Compare with current version.json                    │
│  5. Enable maintenance mode                              │
│  6. Backup affected files                                │
│  7. Download repository zip archive                      │
│  8. Extract and replace files                            │
│  9. Delete removed files                                 │
│  10. Run database migrations                             │
│  11. Update version.json                                 │
│  12. Disable maintenance mode                            │
│  13. Unlock updates                                      │
│  14. Auto-rollback on any failure                        │
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
│  Just push your code.                                    │
└──────────────────────────────────────────────────────────┘
```

---

## Features

- **Zip-Based Updates** — Downloads repository as zip, extracts changed files
- **GitHub Integration** — Direct integration with GitHub API
- **Automatic Rollback** — If any step fails, the system restores the previous state
- **Backup System** — Full backup of affected files before any modification
- **Database Migrations** — Run SQL or PHP migration files as part of the update process
- **PHP Migrations** — Support for `up()` and `down()` methods with auto-rollback
- **Security Guards** — Admin authentication, CSRF protection, lock tokens
- **Maintenance Mode** — Automatic maintenance mode during updates
- **Structured Logging** — Every operation is logged to file and database
- **Framework Independent** — Pure PHP 8.0+; works with Laravel, CodeIgniter, raw PHP, anything
- **PSR-4 Autoloading** — Clean namespace structure

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

Create `config/updatecore.php` or use constants:

```php
<?php

define('GITHUB_REPO', 'your-username/your-repo');
define('GITHUB_TOKEN', 'ghp_your_personal_access_token');
```

### 2. Initialize

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use UpdateCore\Core\Updater;

$db = new PDO('mysql:host=localhost;dbname=your_db', 'user', 'pass');

$update = Updater::make($db, [
    'repository'   => GITHUB_REPO,
    'github_token' => GITHUB_TOKEN,
]);
```

### 3. Check for Updates

```php
$result = $update->check();

if ($result['update_available']) {
    echo "Update available!";
    echo "From: {$result['current']['short_hash']}";
    echo "To: {$result['latest']['short_hash']}";
}
```

### 4. Install the Update

```php
$result = $update->execute();

if ($result['success']) {
    echo "Update installed successfully!";
} else {
    echo "Update failed: " . $result['error'];
}
```

### 5. One-Liner

```php
Updater::make($db, $config)->execute();
```

---

## Configuration

| Key | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `repository` | `string` | `''` | GitHub repo in `owner/repo` format |
| `branch` | `string` | `'main'` | Branch to pull from |
| `github_token` | `string` | `''` | GitHub personal access token |
| `project_root` | `string` | auto | Root path of your application |
| `storage_path` | `string` | auto | Path for logs, backups, storage |
| `backup_enabled` | `bool` | `true` | Enable automatic backups |
| `backup_max` | `int` | `5` | Maximum backups to retain |
| `timeout` | `int` | `300` | HTTP timeout in seconds |
| `excluded_dirs` | `array` | `['storage', 'vendor']` | Directories to skip during update |
| `excluded_files` | `array` | `['config.php']` | Files to skip during update |

---

## Update Flow

```text
Lock → Auth → Check → Maintenance → Backup → Download → Extract → Replace → Migrate → Finalize → Unlock
  │                                              │                    │          │
  │                                              └── Rollback ───────┘          └── Rollback on failure
  └── Auto-rollback on any failure
```

### Automatic Rollback

If any step fails during the update process, UpdateCore automatically:

1. Restores all files from backup
2. Disables maintenance mode
3. Unlocks the update system
4. Logs the failure

---

## Database Migrations

Place migration files in `storage/migrations/`:

```sql
-- 2026_01_01_000001_add_users_table.sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

```php
// 2026_01_02_000002_add_profile_fields.php
return new class {
    public function up(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL");
    }

    public function down(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE users DROP COLUMN avatar");
    }
};
```

### Migration with Auto-Rollback

```php
return new class {
    public function up(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20)");
    }

    public function down(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("ALTER TABLE users DROP COLUMN phone");
    }
};
```

If `up()` throws an exception, `down()` is called automatically to rollback.

---

## Security

- **Admin Authentication** — Verify admin identity before updates
- **CSRF Protection** — Token-based request validation
- **Lock Tokens** — Prevent concurrent update attempts
- **Backup Before Change** — Every file backed up before replacement
- **Atomic Writes** — Temp file → verify → replace (no partial writes)
- **Maintenance Mode** — Prevents user access during updates
- **Excluded Files** — Config files never overwritten

---

## Logging

Logs are stored in two places:

### File Logs

`storage/logs/update_[job_id].log`:

```text
[2026-07-01 10:30:00] [INFO] Update started
[2026-07-01 10:30:01] [INFO] Checking for updates...
[2026-07-01 10:30:02] [INFO] Update available: abc123d → def456a
[2026-07-01 10:30:03] [INFO] Backup created: 20260701-abc123
[2026-07-01 10:30:05] [INFO] Downloading update...
[2026-07-01 10:30:10] [INFO] Extracting files...
[2026-07-01 10:30:15] [INFO] Running migrations...
[2026-07-01 10:30:20] [SUCCESS] Update completed successfully
```

### Database Logs

`system_logs` table stores all log entries with context.

---

## Project Structure

```
updatecore/
├── composer.json
├── config/
│   └── config.example.php
├── src/
│   ├── Core/
│   │   └── Updater.php              # Main orchestrator
│   ├── Github/
│   │   └── GithubClient.php         # GitHub API client
│   ├── Backup/
│   │   └── BackupService.php        # Backup & restore
│   ├── Replace/
│   │   └── ReplaceService.php       # File replacement
│   ├── Migration/
│   │   ├── MigrationRunner.php      # SQL & PHP migrations
│   │   └── MigrationRepository.php  # Migration tracking
│   ├── Security/
│   │   └── SecurityGuard.php        # Auth, CSRF, locks
│   └── Support/
│       ├── Config.php               # Configuration
│       ├── Logger.php               # Dual logging (file + DB)
│       └── Helpers.php              # Utility functions
└── README.md
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
| `v0.2.0` | Current | Zip-based updates, migrations |
| `v0.5.0` | Pre-release | Stable rollback, security guards |
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

- PHP 8.0+ with strict types
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
