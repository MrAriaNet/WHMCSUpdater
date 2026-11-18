<?php
/**
 * Resume-capable ZIP downloader with proxy failover and integrity checks.
 */

namespace WHMCSUpdater;

class ResumableDownloader
{
    /** @var ProxyRepository */
    private $repo;

    /** @var ProxySelector */
    private $selector;

    /** @var int */
    private $minSpeedBytes;

    /** @var int */
    private $stallSeconds;

    /** @var int */
    private $connectTimeout;

    /** @var bool */
    private $socks5RemoteDns;

    /** @var string */
    private $fallbackBehavior;

    /** @var callable|null */
    private $progressCallback;

    public function __construct(
        ProxyRepository $repo,
        ProxySelector $selector,
        int $minSpeedBytes = 1024,
        int $stallSeconds = 60,
        int $connectTimeout = 15,
        bool $socks5RemoteDns = true,
        string $fallbackBehavior = 'direct'
    ) {
        $this->repo = $repo;
        $this->selector = $selector;
        $this->minSpeedBytes = max(0, $minSpeedBytes);
        $this->stallSeconds = max(5, $stallSeconds);
        $this->connectTimeout = max(1, $connectTimeout);
        $this->socks5RemoteDns = $socks5RemoteDns;
        $this->fallbackBehavior = $fallbackBehavior;
    }

    /**
     * @param callable|null $callback function(array $progress): void
     */
    public function onProgress(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Download a release ZIP with resume support.
     *
     * @param array $release Release metadata from ReleaseCatalog
     * @param string $destPath Final ZIP path (without .part)
     * @param int $budgetSeconds Soft time budget for a single call (0 = unlimited)
     * @param int|null $forceProxyId Prefer a specific proxy ID
     * @param bool $forceDirect Skip proxies entirely
     * @return array{ok:bool,done:bool,path:string,bytes:int,total:int,proxy:?string,error:string,message:string}
     */
    public function download(
        array $release,
        string $destPath,
        int $budgetSeconds = 0,
        ?int $forceProxyId = null,
        bool $forceDirect = false
    ): array {
        if (!function_exists('curl_init')) {
            return $this->result(false, false, $destPath, 0, (int) ($release['file_size'] ?? 0), null, 'curl_extension_missing');
        }

        $url = (string) ($release['url'] ?? '');
        $expectedSize = (int) ($release['file_size'] ?? 0);
        $expectedSha = strtolower((string) ($release['sha'] ?? ''));

        if ($url === '') {
            return $this->result(false, false, $destPath, 0, $expectedSize, null, 'missing_url');
        }

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return $this->result(false, false, $destPath, 0, $expectedSize, null, 'cannot_create_workdir');
        }

        // Reuse a completed ZIP when it already verifies.
        if (is_file($destPath)) {
            $verify = $this->verifyFile($destPath, $expectedSize, $expectedSha);
            if ($verify['ok']) {
                return $this->result(true, true, $destPath, (int) filesize($destPath), $expectedSize, null, '', 'already_downloaded');
            }
            @unlink($destPath);
        }

        $partPath = $destPath . '.part';
        $deadline = $budgetSeconds > 0 ? (microtime(true) + $budgetSeconds) : 0.0;
        $usedProxyIds = [];
        $lastProxyLabel = null;
        $attempt = 0;
        $maxAttempts = max(3, Settings::int('max_attempts', 5) + 2);

        while ($attempt < $maxAttempts) {
            $attempt++;

            if ($deadline > 0 && microtime(true) >= $deadline) {
                $bytes = is_file($partPath) ? (int) filesize($partPath) : 0;
                return $this->result(true, false, $destPath, $bytes, $expectedSize, $lastProxyLabel, '', 'budget_exhausted');
            }

            $proxy = null;
            if (!$forceDirect) {
                $proxy = $this->pickProxy($forceProxyId, $usedProxyIds);
                if ($proxy === null && $this->fallbackBehavior === 'abort') {
                    $bytes = is_file($partPath) ? (int) filesize($partPath) : 0;
                    return $this->result(false, false, $destPath, $bytes, $expectedSize, null, 'no_healthy_proxy');
                }
            }

            $label = $proxy ? $this->repo->displayLabel($proxy) : 'direct';
            $lastProxyLabel = $label;

            $chunk = $this->downloadChunk($url, $partPath, $proxy, $deadline);
            $this->emitProgress([
                'phase' => 'download',
                'bytes' => $chunk['bytes'],
                'total' => $expectedSize > 0 ? $expectedSize : $chunk['bytes'],
                'proxy' => $label,
                'http_code' => $chunk['http_code'],
                'message' => $chunk['error'] !== '' ? $chunk['error'] : 'downloading',
            ]);

            if ($chunk['ok'] && $chunk['complete']) {
                // Move .part into place then verify.
                if (!@rename($partPath, $destPath)) {
                    if (!@copy($partPath, $destPath)) {
                        return $this->result(false, false, $destPath, $chunk['bytes'], $expectedSize, $label, 'cannot_finalize_zip');
                    }
                    @unlink($partPath);
                }

                $verify = $this->verifyFile($destPath, $expectedSize, $expectedSha);
                if (!$verify['ok']) {
                    @unlink($destPath);
                    return $this->result(false, false, $destPath, 0, $expectedSize, $label, $verify['error']);
                }

                if ($proxy) {
                    $this->repo->recordCheck((int) $proxy->id, true, null, 'download_ok');
                }

                return $this->result(true, true, $destPath, (int) filesize($destPath), $expectedSize, $label, '', 'download_complete');
            }

            if ($chunk['budget_hit']) {
                return $this->result(true, false, $destPath, $chunk['bytes'], $expectedSize, $label, '', 'budget_exhausted');
            }

            // Fail over: mark proxy unhealthy and try another.
            if ($proxy) {
                $usedProxyIds[(int) $proxy->id] = true;
                $this->repo->recordCheck((int) $proxy->id, false, null, $chunk['error'] !== '' ? $chunk['error'] : 'download_failed');
                $this->selector->invalidateCache();
                $forceProxyId = null;
            } else {
                // Direct connection failed — nothing else to try.
                return $this->result(false, false, $destPath, $chunk['bytes'], $expectedSize, $label, $chunk['error'] !== '' ? $chunk['error'] : 'download_failed');
            }
        }

        $bytes = is_file($partPath) ? (int) filesize($partPath) : 0;
        return $this->result(false, false, $destPath, $bytes, $expectedSize, $lastProxyLabel, 'all_download_attempts_failed');
    }

    /**
     * Verify an existing local ZIP (uploaded or previously downloaded).
     *
     * @return array{ok:bool,error:string,bytes:int}
     */
    public function verifyFile(string $path, int $expectedSize, string $expectedSha): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'error' => 'file_missing', 'bytes' => 0];
        }

        $bytes = (int) filesize($path);
        if ($expectedSize > 0 && $bytes !== $expectedSize) {
            return [
                'ok' => false,
                'error' => sprintf('size_mismatch:got_%d_expected_%d', $bytes, $expectedSize),
                'bytes' => $bytes,
            ];
        }

        if ($expectedSha !== '') {
            $hash = hash_file('sha256', $path);
            if ($hash === false || strtolower($hash) !== strtolower($expectedSha)) {
                return [
                    'ok' => false,
                    'error' => 'sha256_mismatch',
                    'bytes' => $bytes,
                ];
            }
        }

        return ['ok' => true, 'error' => '', 'bytes' => $bytes];
    }

    /**
     * Adopt a user-supplied ZIP into the work directory after verification.
     *
     * @return array{ok:bool,path:string,error:string,bytes:int}
     */
    public function adoptLocalZip(string $sourcePath, string $destPath, array $release): array
    {
        $expectedSize = (int) ($release['file_size'] ?? 0);
        $expectedSha = strtolower((string) ($release['sha'] ?? ''));

        $verify = $this->verifyFile($sourcePath, $expectedSize, $expectedSha);
        if (!$verify['ok']) {
            return ['ok' => false, 'path' => $destPath, 'error' => $verify['error'], 'bytes' => $verify['bytes']];
        }

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['ok' => false, 'path' => $destPath, 'error' => 'cannot_create_workdir', 'bytes' => 0];
        }

        if (realpath($sourcePath) !== realpath($destPath)) {
            if (!@copy($sourcePath, $destPath)) {
                return ['ok' => false, 'path' => $destPath, 'error' => 'cannot_copy_local_zip', 'bytes' => $verify['bytes']];
            }
        }

        return ['ok' => true, 'path' => $destPath, 'error' => '', 'bytes' => $verify['bytes']];
    }

    /**
     * @param array<int,bool> $excludeIds
     */
    private function pickProxy(?int $forceProxyId, array $excludeIds): ?object
    {
        if ($forceProxyId !== null && $forceProxyId > 0) {
            $proxy = $this->repo->find($forceProxyId);
            if ($proxy && (int) $proxy->enabled === 1 && empty($excludeIds[(int) $proxy->id])) {
                return $proxy;
            }
        }

        $selection = $this->selector->select(true);
        if ($selection['proxy'] && empty($excludeIds[(int) $selection['proxy']->id])) {
            return $selection['proxy'];
        }

        // Walk remaining enabled proxies that have not failed this run.
        foreach ($this->repo->enabled() as $proxy) {
            if (!empty($excludeIds[(int) $proxy->id])) {
                continue;
            }
            $result = $this->selector->checkOne((int) $proxy->id);
            if (!empty($result['result']['ok'])) {
                return $result['proxy'];
            }
            $excludeIds[(int) $proxy->id] = true;
        }

        return null;
    }

    /**
     * Perform one download attempt (possibly partial) against one proxy/direct.
     *
     * @param object|null $proxy
     * @return array{ok:bool,complete:bool,budget_hit:bool,bytes:int,http_code:int,error:string}
     */
    private function downloadChunk(string $url, string $partPath, ?object $proxy, float $deadline): array
    {
        $existing = is_file($partPath) ? (int) filesize($partPath) : 0;
        $mode = $existing > 0 ? 'ab' : 'wb';
        $fh = @fopen($partPath, $mode);
        if ($fh === false) {
            return ['ok' => false, 'complete' => false, 'budget_hit' => false, 'bytes' => $existing, 'http_code' => 0, 'error' => 'cannot_open_part'];
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WHMCSUpdater/1.2.0',
            CURLOPT_FAILONERROR => false,
            CURLOPT_BUFFERSIZE => 256 * 1024,
        ];

        if ($existing > 0) {
            $opts[CURLOPT_RESUME_FROM] = $existing;
        }

        if ($this->minSpeedBytes > 0) {
            $opts[CURLOPT_LOW_SPEED_LIMIT] = $this->minSpeedBytes;
            $opts[CURLOPT_LOW_SPEED_TIME] = $this->stallSeconds;
        }

        curl_setopt_array($ch, $opts);

        if ($proxy) {
            ProxyCurl::applyToHandle($ch, $proxy, $this->repo, $this->socks5RemoteDns);
        }

        $budgetHit = false;
        $self = $this;
        if ($deadline > 0) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($resource, $dlTotal, $dlNow) use ($self, $deadline, $existing, &$budgetHit) {
                if (microtime(true) >= $deadline) {
                    $budgetHit = true;
                    return 1; // abort transfer
                }
                $self->emitProgress([
                    'phase' => 'download',
                    'bytes' => $existing + (int) $dlNow,
                    'total' => $dlTotal > 0 ? ($existing + (int) $dlTotal) : 0,
                    'message' => 'downloading',
                ]);
                return 0;
            });
        } elseif ($this->progressCallback) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($resource, $dlTotal, $dlNow) use ($self, $existing) {
                $self->emitProgress([
                    'phase' => 'download',
                    'bytes' => $existing + (int) $dlNow,
                    'total' => $dlTotal > 0 ? ($existing + (int) $dlTotal) : 0,
                    'message' => 'downloading',
                ]);
                return 0;
            });
        }

        $okExec = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        $bytes = is_file($partPath) ? (int) filesize($partPath) : 0;

        // Server ignored Range and sent a full 200 — restart from scratch.
        if ($existing > 0 && $httpCode === 200 && $bytes > $existing) {
            // The append corrupted the file; truncate and signal retry.
            @unlink($partPath);
            return [
                'ok' => false,
                'complete' => false,
                'budget_hit' => false,
                'bytes' => 0,
                'http_code' => $httpCode,
                'error' => 'range_not_supported_restart',
            ];
        }

        if ($budgetHit || $errno === 42 /* CURLE_ABORTED_BY_CALLBACK */) {
            return [
                'ok' => true,
                'complete' => false,
                'budget_hit' => true,
                'bytes' => $bytes,
                'http_code' => $httpCode,
                'error' => '',
            ];
        }

        if ($okExec === false || $errno !== 0) {
            return [
                'ok' => false,
                'complete' => false,
                'budget_hit' => false,
                'bytes' => $bytes,
                'http_code' => $httpCode,
                'error' => sprintf('curl_%d: %s', $errno, $error),
            ];
        }

        if ($httpCode !== 200 && $httpCode !== 206) {
            return [
                'ok' => false,
                'complete' => false,
                'budget_hit' => false,
                'bytes' => $bytes,
                'http_code' => $httpCode,
                'error' => sprintf('unexpected_http_%d', $httpCode),
            ];
        }

        // Complete when we got a clean finish (no stall). Size/sha verified by caller.
        return [
            'ok' => true,
            'complete' => true,
            'budget_hit' => false,
            'bytes' => $bytes,
            'http_code' => $httpCode,
            'error' => '',
        ];
    }

    /**
     * @param array $progress
     */
    private function emitProgress(array $progress): void
    {
        if ($this->progressCallback) {
            ($this->progressCallback)($progress);
        }
    }

    /**
     * @return array{ok:bool,done:bool,path:string,bytes:int,total:int,proxy:?string,error:string,message:string}
     */
    private function result(
        bool $ok,
        bool $done,
        string $path,
        int $bytes,
        int $total,
        ?string $proxy,
        string $error,
        string $message = ''
    ): array {
        return [
            'ok' => $ok,
            'done' => $done,
            'path' => $path,
            'bytes' => $bytes,
            'total' => $total,
            'proxy' => $proxy,
            'error' => $error,
            'message' => $message !== '' ? $message : $error,
        ];
    }
}
