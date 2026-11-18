<?php
/**
 * Shared cURL helpers for applying a pool proxy to a cURL handle.
 */

namespace WHMCSUpdater;

class ProxyCurl
{
    /**
     * Apply proxy-related options to an existing cURL handle.
     *
     * @param resource|\CurlHandle $ch
     * @param object $proxy Database row
     * @param ProxyRepository $repo
     * @param bool $socks5RemoteDns
     */
    public static function applyToHandle($ch, object $proxy, ProxyRepository $repo, bool $socks5RemoteDns = true): void
    {
        $proxyUrl = $repo->buildUrl($proxy, $socks5RemoteDns);
        curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);

        $proxyType = self::curlProxyType((string) $proxy->scheme, $socks5RemoteDns);
        if ($proxyType !== null) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxyType);
        }

        $username = (string) ($proxy->username ?? '');
        $password = $repo->decryptPassword($proxy->password ?? null);
        if ($username !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . $password);
        }
    }

    /**
     * Whether this PHP/cURL build can use HTTPS proxies.
     */
    public static function supportsHttpsProxy(): bool
    {
        if (!function_exists('curl_version')) {
            return false;
        }

        $info = curl_version();
        $version = $info['version'] ?? '0.0.0';

        return version_compare($version, '7.52.0', '>=');
    }

    public static function curlVersion(): string
    {
        if (!function_exists('curl_version')) {
            return 'unknown';
        }

        $info = curl_version();

        return (string) ($info['version'] ?? 'unknown');
    }

    /**
     * @return int|null
     */
    public static function curlProxyType(string $scheme, bool $socks5RemoteDns = true)
    {
        switch ($scheme) {
            case 'http':
                return defined('CURLPROXY_HTTP') ? CURLPROXY_HTTP : null;
            case 'https':
                if (defined('CURLPROXY_HTTPS')) {
                    return CURLPROXY_HTTPS;
                }
                return defined('CURLPROXY_HTTP') ? CURLPROXY_HTTP : null;
            case 'socks5':
                if ($socks5RemoteDns && defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                    return CURLPROXY_SOCKS5_HOSTNAME;
                }
                return defined('CURLPROXY_SOCKS5') ? CURLPROXY_SOCKS5 : null;
            default:
                return null;
        }
    }
}
