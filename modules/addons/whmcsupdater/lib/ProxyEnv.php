<?php
/**
 * Apply and restore outbound proxy environment variables.
 *
 * Composer, Guzzle, and libcurl consult different sources (putenv, $_SERVER,
 * $_ENV), so every variable is written to all three.
 */

namespace WHMCSUpdater;

class ProxyEnv
{
    /** @var array<string, string|null>|null Snapshot taken before apply() */
    private static $snapshot = null;

    /** @var string[] */
    private static $keys = [
        'http_proxy',
        'HTTP_PROXY',
        'https_proxy',
        'HTTPS_PROXY',
        'CGI_HTTP_PROXY',
        'cgi_http_proxy',
        'all_proxy',
        'ALL_PROXY',
        'no_proxy',
        'NO_PROXY',
    ];

    /**
     * Whether proxy environment variables are currently applied by this class.
     */
    public static function isApplied(): bool
    {
        return self::$snapshot !== null;
    }

    /**
     * Set proxy environment variables and remember previous values for restore().
     */
    public static function apply(string $proxyUrl, string $noProxy = 'localhost,127.0.0.1'): void
    {
        if (self::$snapshot === null) {
            self::$snapshot = [];
            foreach (self::$keys as $key) {
                self::$snapshot[$key] = self::read($key);
            }
        }

        $values = [
            'http_proxy' => $proxyUrl,
            'HTTP_PROXY' => $proxyUrl,
            'https_proxy' => $proxyUrl,
            'HTTPS_PROXY' => $proxyUrl,
            'CGI_HTTP_PROXY' => $proxyUrl,
            'cgi_http_proxy' => $proxyUrl,
            'all_proxy' => $proxyUrl,
            'ALL_PROXY' => $proxyUrl,
            'no_proxy' => $noProxy,
            'NO_PROXY' => $noProxy,
        ];

        foreach ($values as $key => $value) {
            self::write($key, $value);
        }
    }

    /**
     * Restore environment variables captured by the last apply().
     */
    public static function restore(): void
    {
        if (self::$snapshot === null) {
            return;
        }

        foreach (self::$snapshot as $key => $value) {
            if ($value === null) {
                self::clear($key);
            } else {
                self::write($key, $value);
            }
        }

        self::$snapshot = null;
    }

    /**
     * @return string|null
     */
    private static function read(string $key)
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false) {
            return (string) $fromEnv;
        }
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (isset($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        return null;
    }

    private static function write(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }

    private static function clear(string $key): void
    {
        putenv($key);
        unset($_SERVER[$key], $_ENV[$key]);
    }
}
