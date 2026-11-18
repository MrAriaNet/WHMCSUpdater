<?php
/**
 * WHMCS Updater Addon
 *
 * Resumable WHMCS upgrades with staged apply/recovery, plus an optional
 * Proxy Pool that routes Automatic Updater traffic through managed
 * HTTP, HTTPS, and SOCKS5 proxies.
 *
 * @package    WHMCSUpdater
 * @author     Aria Jahangiri Far
 * @copyright  Copyright (c) 2026 Aria Jahangiri Far
 * @license    MIT
 * @link       https://github.com/MrAriaNet/WHMCSUpdater
 * @version    1.2.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Addon module configuration shown at Setup > Addon Modules.
 *
 * @return array
 */
function whmcsupdater_config()
{
    return [
        'name' => 'WHMCS Updater',
        'description' => 'Apply resumable manual WHMCS updates to completion, with an optional Proxy Pool (HTTP, HTTPS, SOCKS5) for Automatic Updater traffic.',
        'version' => '1.2.0',
        'author' => 'Aria Jahangiri Far',
        'language' => 'english',
        'fields' => [
            'apply_mode' => [
                'FriendlyName' => 'Apply Mode',
                'Type' => 'dropdown',
                'Options' => [
                    'update_only' => 'Update traffic only',
                    'all_outbound' => 'All outbound requests',
                ],
                'Default' => 'update_only',
                'Description' => 'Limit proxy usage to the Automatic Updater, or apply it to every outbound request.',
            ],
            'selection_strategy' => [
                'FriendlyName' => 'Selection Strategy',
                'Type' => 'dropdown',
                'Options' => [
                    'fastest' => 'Fastest healthy',
                    'priority' => 'Priority order',
                    'random' => 'Random',
                    'round_robin' => 'Round robin',
                ],
                'Default' => 'fastest',
                'Description' => 'How the next proxy is chosen from the enabled pool.',
            ],
            'health_check_url' => [
                'FriendlyName' => 'Health Check URL',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'https://releases.whmcs.com/v2/packages.json',
                'Description' => 'URL used to verify that a proxy can reach the WHMCS update infrastructure.',
            ],
            'connect_timeout' => [
                'FriendlyName' => 'Connect Timeout (seconds)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '10',
                'Description' => 'Maximum seconds to wait while establishing a proxy connection.',
            ],
            'max_attempts' => [
                'FriendlyName' => 'Max Selection Attempts',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '5',
                'Description' => 'How many proxies to try before giving up during selection.',
            ],
            'cache_ttl' => [
                'FriendlyName' => 'Selected Proxy Cache TTL (seconds)',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '300',
                'Description' => 'Reuse the last healthy proxy for this many seconds to avoid re-testing on every AJAX request.',
            ],
            'socks5_remote_dns' => [
                'FriendlyName' => 'SOCKS5 Remote DNS',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Resolve DNS through the SOCKS5 proxy (socks5h). Recommended.',
            ],
            'fallback_behavior' => [
                'FriendlyName' => 'No Healthy Proxy Behavior',
                'Type' => 'dropdown',
                'Options' => [
                    'direct' => 'Fall back to direct connection',
                    'abort' => 'Abort and log an error',
                ],
                'Default' => 'direct',
                'Description' => 'What to do when every enabled proxy fails the health check.',
            ],
            'no_proxy' => [
                'FriendlyName' => 'NO_PROXY List',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'localhost,127.0.0.1',
                'Description' => 'Comma-separated hosts that must bypass the proxy.',
            ],
            'manual_update_dir' => [
                'FriendlyName' => 'Manual Update Work Directory',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Absolute path for ZIP/state/logs used by the resumable updater. Leave blank to use modules/addons/whmcsupdater/storage/manual_update. Prefer a path outside the web root.',
            ],
            'php_cli_path' => [
                'FriendlyName' => 'PHP CLI Binary Path',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '',
                'Description' => 'Absolute path to the PHP CLI binary used for install/bin/installer.php. Leave blank to auto-detect. Must be CLI SAPI with ionCube for your WHMCS version.',
            ],
        ],
    ];
}

/**
 * Create database tables when the addon is activated.
 *
 * @return array{status:string,description:string}
 */
function whmcsupdater_activate()
{
    try {
        whmcsupdater_migrate_legacy();

        if (!Capsule::schema()->hasTable('mod_whmcsupdater_proxies')) {
            Capsule::schema()->create('mod_whmcsupdater_proxies', function ($table) {
                $table->increments('id');
                $table->string('label', 100)->default('');
                $table->enum('scheme', ['http', 'https', 'socks5']);
                $table->string('host', 255);
                $table->unsignedInteger('port');
                $table->string('username', 255)->nullable();
                $table->text('password')->nullable();
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('priority')->default(100);
                $table->string('last_status', 20)->default('unknown');
                $table->unsignedInteger('last_latency_ms')->nullable();
                $table->unsignedInteger('fail_count')->default(0);
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamps();
                $table->index(['enabled', 'priority']);
                $table->index('last_status');
            });
        }

        if (!Capsule::schema()->hasTable('mod_whmcsupdater_log')) {
            Capsule::schema()->create('mod_whmcsupdater_log', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('proxy_id')->nullable();
                $table->string('proxy_label', 255)->nullable();
                $table->string('context', 50)->default('unknown');
                $table->string('result', 20);
                $table->text('message')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index('created_at');
                $table->index('proxy_id');
            });
        }

        if (!Capsule::schema()->hasTable('mod_whmcsupdater_state')) {
            Capsule::schema()->create('mod_whmcsupdater_state', function ($table) {
                $table->string('state_key', 64)->primary();
                $table->text('state_value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        return [
            'status' => 'success',
            'description' => 'WHMCS Updater activated. Add proxies from Addons > WHMCS Updater.',
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Unable to activate WHMCS Updater: ' . $e->getMessage(),
        ];
    }
}

/**
 * Migrate tables, settings, and work directory from legacy addons
 * (proxyupdater, then proxypool). Prefer the newer legacy module first.
 */
function whmcsupdater_migrate_legacy(): void
{
    foreach (['proxyupdater', 'proxypool'] as $legacyModule) {
        $tableMap = [
            'mod_' . $legacyModule . '_proxies' => 'mod_whmcsupdater_proxies',
            'mod_' . $legacyModule . '_log' => 'mod_whmcsupdater_log',
            'mod_' . $legacyModule . '_state' => 'mod_whmcsupdater_state',
        ];

        foreach ($tableMap as $old => $new) {
            if (Capsule::schema()->hasTable($old) && !Capsule::schema()->hasTable($new)) {
                Capsule::statement('RENAME TABLE `' . $old . '` TO `' . $new . '`');
            }
        }

        $legacySettings = Capsule::table('tbladdonmodules')
            ->where('module', $legacyModule)
            ->get();
        foreach ($legacySettings as $row) {
            $exists = Capsule::table('tbladdonmodules')
                ->where('module', 'whmcsupdater')
                ->where('setting', $row->setting)
                ->exists();
            if (!$exists) {
                Capsule::table('tbladdonmodules')->insert([
                    'module' => 'whmcsupdater',
                    'setting' => $row->setting,
                    'value' => $row->value,
                ]);
            }
        }

        $oldWork = dirname(__DIR__) . '/' . $legacyModule . '/storage/manual_update';
        $newWork = __DIR__ . '/storage/manual_update';
        if (is_dir($oldWork) && is_dir($newWork)) {
            $entries = @scandir($oldWork) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $src = $oldWork . '/' . $entry;
                $dst = $newWork . '/' . $entry;
                if (is_file($src) && !is_file($dst)) {
                    @copy($src, $dst);
                } elseif (is_dir($src) && !is_dir($dst)) {
                    whmcsupdater_copy_dir($src, $dst);
                }
            }
        }
    }
}

/**
 * Recursively copy a directory tree.
 */
function whmcsupdater_copy_dir(string $src, string $dst): void
{
    if (!is_dir($dst) && !@mkdir($dst, 0750, true) && !is_dir($dst)) {
        return;
    }
    $items = @scandir($src) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $from = $src . '/' . $item;
        $to = $dst . '/' . $item;
        if (is_dir($from)) {
            whmcsupdater_copy_dir($from, $to);
        } elseif (!is_file($to)) {
            @copy($from, $to);
        }
    }
}

/**
 * Remove addon tables on deactivation.
 *
 * @return array{status:string,description:string}
 */
function whmcsupdater_deactivate()
{
    try {
        Capsule::schema()->dropIfExists('mod_whmcsupdater_log');
        Capsule::schema()->dropIfExists('mod_whmcsupdater_state');
        Capsule::schema()->dropIfExists('mod_whmcsupdater_proxies');

        return [
            'status' => 'success',
            'description' => 'WHMCS Updater deactivated and its tables removed.',
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Unable to deactivate WHMCS Updater: ' . $e->getMessage(),
        ];
    }
}

/**
 * Admin area output for Addons > WHMCS Updater.
 *
 * @param array $vars Module variables including settings and language strings
 */
function whmcsupdater_output($vars)
{
    require_once __DIR__ . '/lib/bootstrap.php';

    $lang = WHMCSUpdater\Lang::resolve($vars);

    echo WHMCSUpdater\AdminUi::render($vars, $lang);
}
