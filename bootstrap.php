<?php

declare(strict_types=1);

defined('UPDATER_ROOT') || define('UPDATER_ROOT', dirname(__DIR__));
defined('UPDATER_STORAGE') || define('UPDATER_STORAGE', UPDATER_ROOT . '/storage');

if (!is_dir(UPDATER_STORAGE)) {
    mkdir(UPDATER_STORAGE, 0755, true);
}

foreach (['logs', 'backups', 'cache', 'migrations'] as $dir) {
    $path = UPDATER_STORAGE . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}
