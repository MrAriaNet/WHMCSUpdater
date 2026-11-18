<?php
/**
 * Shared autoloader and helpers for the WHMCS Updater addon.
 *
 * Allowed entry points:
 * - WHMCS (WHMCS constant defined)
 * - CLI scripts
 * - Standalone update_runner.php (defines WHMCSUPDATER_STANDALONE)
 */

namespace WHMCSUpdater;

if (!defined('WHMCS') && PHP_SAPI !== 'cli' && !defined('WHMCSUPDATER_STANDALONE')) {
    die('This file cannot be accessed directly');
}

spl_autoload_register(static function ($class) {
    $prefix = 'WHMCSUpdater\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
