<?php
/**
 * Read addon module settings from tbladdonmodules.
 */

namespace WHMCSUpdater;

use WHMCS\Database\Capsule;

class Settings
{
    /** @var array<string, string>|null */
    private static $cache = null;

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defaults = [
            'apply_mode' => 'update_only',
            'selection_strategy' => 'fastest',
            'health_check_url' => 'https://releases.whmcs.com/v2/packages.json',
            'connect_timeout' => '10',
            'max_attempts' => '5',
            'cache_ttl' => '300',
            'socks5_remote_dns' => 'on',
            'fallback_behavior' => 'direct',
            'no_proxy' => 'localhost,127.0.0.1',
            'manual_update_dir' => '',
            'php_cli_path' => '',
        ];

        try {
            $rows = Capsule::table('tbladdonmodules')
                ->where('module', 'whmcsupdater')
                ->get();
            foreach ($rows as $row) {
                $defaults[$row->setting] = (string) $row->value;
            }
        } catch (\Throwable $e) {
            // Tables may not exist yet during first load; keep defaults.
        }

        self::$cache = $defaults;

        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();

        return array_key_exists($key, $all) ? (string) $all[$key] : $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key, (string) $default);
        if (!is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = strtolower(self::get($key, $default ? 'on' : 'off'));

        return in_array($value, ['on', '1', 'true', 'yes'], true);
    }

    /**
     * Build a fully wired selector from current settings.
     */
    public static function makeSelector(): ProxySelector
    {
        $repo = new ProxyRepository();
        $checker = new ProxyChecker(
            self::get('health_check_url'),
            self::int('connect_timeout', 10),
            self::bool('socks5_remote_dns', true)
        );

        return new ProxySelector(
            $repo,
            $checker,
            self::get('selection_strategy', 'fastest'),
            self::int('max_attempts', 5),
            self::int('cache_ttl', 300)
        );
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
