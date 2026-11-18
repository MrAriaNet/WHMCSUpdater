<?php
/**
 * WHMCS Updater hooks.
 *
 * Environment variables must be set before the Automatic Updater starts its
 * Composer/Guzzle/cURL work. For the admin Update WHMCS page that means
 * applying them at hooks.php load time (not inside a late add_hook callback).
 * For cron tasks, PreAutomationTask / PostAutomationTask scope the change
 * to update-related jobs only.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use WHMCSUpdater\ProxyApplier;
use WHMCSUpdater\Settings;

/**
 * Detect whether the current admin request targets the Automatic Updater UI.
 */
function whmcsupdater_is_update_admin_request(): bool
{
    if (!defined('ADMINAREA') || !ADMINAREA) {
        return false;
    }

    $script = '';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $script = basename((string) $_SERVER['SCRIPT_NAME']);
    } elseif (!empty($_SERVER['PHP_SELF'])) {
        $script = basename((string) $_SERVER['PHP_SELF']);
    }

    $script = strtolower($script);

    // Primary WHMCS updater entry point. Custom admin folder names do not
    // affect basename matching.
    $updateScripts = [
        'update.php',
        'systemupdate.php',
        'updater.php',
    ];

    if (in_array($script, $updateScripts, true)) {
        return true;
    }

    // Some WHMCS builds route utilities through a generic controller with a
    // module / action query parameter.
    $module = strtolower((string) ($_REQUEST['module'] ?? $_REQUEST['m'] ?? ''));
    $action = strtolower((string) ($_REQUEST['action'] ?? $_REQUEST['a'] ?? ''));
    if ($module === 'update' || $action === 'update' || strpos($action, 'systemupdate') !== false) {
        return true;
    }

    return false;
}

/**
 * Detect whether an automation task object relates to checking for / applying updates.
 *
 * @param mixed $task
 */
function whmcsupdater_is_update_task($task): bool
{
    $haystacks = [];

    if (is_object($task)) {
        $haystacks[] = get_class($task);
        foreach (['name', 'className', 'identifier', 'type'] as $prop) {
            if (isset($task->{$prop})) {
                $haystacks[] = (string) $task->{$prop};
            }
        }
        if (method_exists($task, 'getName')) {
            try {
                $haystacks[] = (string) $task->getName();
            } catch (\Throwable $e) {
                // ignore
            }
        }
        // Collection-like wrappers used by some WHMCS versions.
        if (method_exists($task, 'first')) {
            try {
                $first = $task->first();
                if (is_object($first)) {
                    $haystacks[] = get_class($first);
                    if (isset($first->name)) {
                        $haystacks[] = (string) $first->name;
                    }
                    if (isset($first->class_name)) {
                        $haystacks[] = (string) $first->class_name;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    } elseif (is_string($task)) {
        $haystacks[] = $task;
    } elseif (is_array($task)) {
        foreach (['name', 'class', 'className', 'class_name', 'type'] as $key) {
            if (!empty($task[$key])) {
                $haystacks[] = (string) $task[$key];
            }
        }
    }

    $patterns = [
        'update',
        'updater',
        'checkforupdates',
        'check_for_updates',
        'systemupdate',
        'composerupdate',
        'whmcsupdate',
    ];

    foreach ($haystacks as $haystack) {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $haystack));
        foreach ($patterns as $pattern) {
            if ($normalized !== '' && strpos($normalized, $pattern) !== false) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Apply proxy environment variables for the current request when appropriate.
 */
function whmcsupdater_maybe_apply_admin(): void
{
    try {
        $mode = Settings::get('apply_mode', 'update_only');

        if ($mode === 'all_outbound') {
            // Only apply inside the admin area to avoid affecting client storefront traffic.
            if (defined('ADMINAREA') && ADMINAREA) {
                ProxyApplier::applyForContext('admin_all');
            }
            return;
        }

        if (whmcsupdater_is_update_admin_request()) {
            $result = ProxyApplier::applyForContext('admin_update');
            // When configured to abort and no proxy is available, leave a
            // marker that Admin UI tooling can surface; we do not fatally
            // halt the page here because WHMCS itself should report the
            // resulting network failure.
            unset($result);
        }
    } catch (\Throwable $e) {
        // Never break WHMCS because the addon failed; log if possible.
        if (function_exists('logActivity')) {
            logActivity('WHMCS Updater hook error: ' . $e->getMessage());
        }
    }
}

// Apply immediately for admin Update WHMCS requests.
whmcsupdater_maybe_apply_admin();

add_hook('PreAutomationTask', 1, function ($vars) {
    try {
        $mode = Settings::get('apply_mode', 'update_only');
        $task = is_array($vars) ? ($vars['task'] ?? $vars) : $vars;

        if ($mode === 'all_outbound' || whmcsupdater_is_update_task($task)) {
            ProxyApplier::applyForContext('cron_update');
        }
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('WHMCS Updater PreAutomationTask error: ' . $e->getMessage());
        }
    }
});

add_hook('PostAutomationTask', 1, function ($vars) {
    try {
        ProxyApplier::restore();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('WHMCS Updater PostAutomationTask error: ' . $e->getMessage());
        }
    }
});
