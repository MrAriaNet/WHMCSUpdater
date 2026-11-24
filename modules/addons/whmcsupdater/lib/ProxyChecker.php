<?php
/**
 * Health-check a single proxy with cURL.
 */

namespace WHMCSUpdater;

class ProxyChecker
{
    /** @var string */
    private $healthUrl;

    /** @var int */
    private $timeout;

    /** @var bool */
    private $socks5RemoteDns;

    public function __construct(string $healthUrl, int $timeout = 10, bool $socks5RemoteDns = true)
    {
        $this->healthUrl = $healthUrl !== '' ? $healthUrl : 'https://releases.whmcs.com/v2/packages.json';
        $this->timeout = max(1, $timeout);
        $this->socks5RemoteDns = $socks5RemoteDns;
    }

    /**
     * Probe the health URL through the given proxy.
     *
     * @param object $proxy Database row
     * @param ProxyRepository $repo
     * @return array{ok:bool,latency_ms:?int,http_code:int,error:string}
     */
    public function check(object $proxy, ProxyRepository $repo): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'latency_ms' => null,
                'http_code' => 0,
                'error' => 'curl_extension_missing',
            ];
        }

        if ($proxy->scheme === 'https' && !ProxyCurl::supportsHttpsProxy()) {
            return [
                'ok' => false,
                'latency_ms' => null,
                'http_code' => 0,
                'error' => 'https_proxy_requires_curl_7_52',
            ];
        }

        $ch = curl_init($this->healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WHMCSUpdater/1.0',
            CURLOPT_NOBODY => false,
            CURLOPT_HEADER => false,
        ]);

        ProxyCurl::applyToHandle($ch, $proxy, $repo, $this->socks5RemoteDns);

        $started = microtime(true);
        $body = curl_exec($ch);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'ok' => false,
                'latency_ms' => $latencyMs,
                'http_code' => $httpCode,
                'error' => sprintf('curl_%d: %s', $errno, $error),
            ];
        }

        // Accept any 2xx/3xx from the update host as proof of connectivity.
        $ok = $httpCode >= 200 && $httpCode < 400 && $body !== false;

        return [
            'ok' => $ok,
            'latency_ms' => $latencyMs,
            'http_code' => $httpCode,
            'error' => $ok ? '' : sprintf('unexpected_http_%d', $httpCode),
        ];
    }

    public function supportsHttpsProxy(): bool
    {
        return ProxyCurl::supportsHttpsProxy();
    }

    public function curlVersion(): string
    {
        return ProxyCurl::curlVersion();
    }
}
