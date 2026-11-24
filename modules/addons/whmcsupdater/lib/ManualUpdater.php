<?php
/**
 * Orchestrates catalog check + resumable download; delegates apply to UpdateJob.
 */

namespace WHMCSUpdater;

use WHMCS\Database\Capsule;

class ManualUpdater
{
    /** @var string */
    private $whmcsRoot;

    /** @var StateStore */
    private $store;

    /** @var ReleaseCatalog */
    private $catalog;

    /** @var ResumableDownloader */
    private $downloader;

    /** @var UpdateJob */
    private $job;

    /** @var string|null */
    private $phpCliPath;

    public function __construct(?string $whmcsRoot = null, ?string $workDir = null, ?string $phpCliPath = null)
    {
        $this->whmcsRoot = $whmcsRoot
            ? rtrim($whmcsRoot, '/')
            : UpdateJob::detectWhmcsRoot();

        $resolvedWork = $workDir
            ? rtrim($workDir, '/')
            : self::defaultWorkDir();

        $this->phpCliPath = $phpCliPath;
        if ($this->phpCliPath === null) {
            try {
                $configured = Settings::get('php_cli_path', '');
                $this->phpCliPath = $configured !== '' ? $configured : null;
            } catch (\Throwable $e) {
                $this->phpCliPath = null;
            }
        }

        $this->store = new StateStore($resolvedWork);
        $applier = new UpdateApplier($this->whmcsRoot, $resolvedWork, function (string $msg) {
            $this->store->log($msg);
        });
        $this->job = new UpdateJob($this->store, $applier, $this->phpCliPath);

        $repo = new ProxyRepository();
        $this->catalog = new ReleaseCatalog(
            Settings::get('health_check_url', ReleaseCatalog::PACKAGES_URL) ?: ReleaseCatalog::PACKAGES_URL,
            max(15, Settings::int('connect_timeout', 10) * 2)
        );
        $this->downloader = new ResumableDownloader(
            $repo,
            Settings::makeSelector(),
            1024,
            60,
            Settings::int('connect_timeout', 10),
            Settings::bool('socks5_remote_dns', true),
            Settings::get('fallback_behavior', 'direct')
        );
        $store = $this->store;
        $this->downloader->onProgress(static function (array $progress) use ($store) {
            $store->mergeState([
                'phase' => 'download',
                'bytes' => $progress['bytes'] ?? 0,
                'total' => $progress['total'] ?? 0,
                'proxy' => $progress['proxy'] ?? null,
                'message' => $progress['message'] ?? 'downloading',
            ]);
        });
    }

    public function getWorkDir(): string
    {
        return $this->store->getWorkDir();
    }

    public function getWhmcsRoot(): string
    {
        return $this->whmcsRoot;
    }

    public function getStore(): StateStore
    {
        return $this->store;
    }

    public function getJob(): UpdateJob
    {
        return $this->job;
    }

    public static function defaultWorkDir(): string
    {
        $configured = '';
        try {
            $configured = Settings::get('manual_update_dir', '');
        } catch (\Throwable $e) {
            $configured = '';
        }

        if ($configured !== '') {
            if (strpos($configured, '/') === 0) {
                return rtrim($configured, '/');
            }
            return rtrim(UpdateJob::detectWhmcsRoot() . '/' . ltrim($configured, '/'), '/');
        }

        return UpdateJob::defaultWorkDir();
    }

    public function installedVersion(): string
    {
        try {
            $row = Capsule::table('tblconfiguration')->where('setting', 'Version')->first();
            if ($row && !empty($row->value)) {
                return (string) $row->value;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        if (defined('WHMCS_VERSION')) {
            return (string) WHMCS_VERSION;
        }

        // Fallback via PDO without Capsule.
        $version = $this->job->getApplier()->readDbVersion();
        return $version ?: '0.0.0';
    }

    /**
     * @return array{ok:bool,release:?array,releases:array,error:string,installed:string,blocked:?array,major_upgrade:bool}
     */
    public function checkForUpdate(?string $exactVersion = null, string $channel = 'stable', bool $forceDirect = false, ?int $proxyId = null): array
    {
        $installed = $this->installedVersion();
        $this->store->mergeState([
            'phase' => 'catalog',
            'status' => 'running',
            'installed' => $installed,
            'message' => 'Fetching release catalog…',
            'error' => '',
            'blocked' => null,
            'major_upgrade' => false,
        ]);

        $proxy = null;
        $repo = new ProxyRepository();
        if (!$forceDirect) {
            if ($proxyId) {
                $proxy = $repo->find($proxyId);
            } else {
                $selection = Settings::makeSelector()->select(true);
                $proxy = $selection['proxy'];
            }
        }

        $raw = $this->catalog->fetchRaw(
            $proxy,
            $repo,
            Settings::bool('socks5_remote_dns', true)
        );

        if (!$raw['ok'] && $proxy && Settings::get('fallback_behavior', 'direct') === 'direct') {
            $this->store->log('Catalog via proxy failed; retrying direct.');
            $raw = $this->catalog->fetchRaw(null, null, true);
        }

        if (!$raw['ok']) {
            $this->store->mergeState([
                'phase' => 'catalog',
                'status' => 'error',
                'error' => $raw['error'],
                'message' => 'Failed to fetch packages.json: ' . $raw['error'],
                'blocked' => null,
            ]);
            return [
                'ok' => false,
                'release' => null,
                'releases' => [],
                'error' => $raw['error'],
                'installed' => $installed,
                'blocked' => null,
                'major_upgrade' => false,
            ];
        }

        @file_put_contents($this->store->getWorkDir() . '/packages.json', $raw['body']);

        $parsed = $this->catalog->parse($raw['body']);
        if (!$parsed['ok']) {
            $this->store->mergeState([
                'phase' => 'catalog',
                'status' => 'error',
                'error' => $parsed['error'],
                'message' => 'Failed to parse packages.json',
                'blocked' => null,
            ]);
            return [
                'ok' => false,
                'release' => null,
                'releases' => [],
                'error' => $parsed['error'],
                'installed' => $installed,
                'blocked' => null,
                'major_upgrade' => false,
            ];
        }

        $picked = $this->catalog->pickTarget($parsed['releases'], $installed, $channel, $exactVersion);
        $blocked = isset($picked['blocked']) && is_array($picked['blocked']) ? $picked['blocked'] : null;
        $selectable = $this->catalog->listSelectableReleases($parsed['releases'], $installed, $channel);

        if (!$picked['ok'] || !$picked['release']) {
            $isLatest = ($picked['error'] === 'already_latest');
            // already_latest is not a failure — keep state clean so a page refresh
            // does not re-show a flash notice; the AJAX layer injects a one-shot message.
            if ($isLatest) {
                $this->store->mergeState([
                    'phase' => 'catalog',
                    'status' => 'idle',
                    'error' => '',
                    'message' => '',
                    'release' => null,
                    'blocked' => null,
                    'major_upgrade' => false,
                ]);
            } else {
                $message = 'No suitable release: ' . $picked['error'];
                if ($blocked !== null) {
                    $message = $this->catalog->formatBlockedMessage($blocked, null);
                }
                $this->store->mergeState([
                    'phase' => 'catalog',
                    'status' => 'error',
                    'error' => $picked['error'],
                    'message' => $message,
                    'release' => $picked['release'],
                    'blocked' => $blocked,
                    'major_upgrade' => false,
                ]);
            }
            return [
                'ok' => false,
                'release' => $picked['release'],
                'releases' => $selectable,
                'error' => $picked['error'],
                'installed' => $installed,
                'blocked' => $blocked,
                'major_upgrade' => false,
            ];
        }

        $release = $picked['release'];
        $majorUpgrade = $this->catalog->isMajorUpgrade($installed, (string) $release['version']);
        $message = sprintf('Target release: %s (%s bytes)', $release['full_version'], number_format($release['file_size']));
        if ($blocked !== null) {
            $message .= ' · ' . $this->catalog->formatBlockedMessage($blocked, (string) $release['full_version']);
        }

        $this->store->mergeState([
            'phase' => 'catalog',
            'status' => 'ready',
            'error' => '',
            'installed' => $installed,
            'release' => $release,
            'blocked' => $blocked,
            'major_upgrade' => $majorUpgrade,
            'message' => $message,
        ]);

        return [
            'ok' => true,
            'release' => $release,
            'releases' => $selectable,
            'error' => '',
            'installed' => $installed,
            'blocked' => $blocked,
            'major_upgrade' => $majorUpgrade,
        ];
    }

    /**
     * @return array{ok:bool,done:bool,error:string,state:array}
     */
    public function download(
        int $budgetSeconds = 0,
        ?string $localZip = null,
        ?int $proxyId = null,
        bool $forceDirect = false,
        int $minSpeed = 1024
    ): array {
        if (!$this->store->acquireLock()) {
            return ['ok' => false, 'done' => false, 'error' => 'locked', 'state' => $this->statusPayload()];
        }

        try {
            $state = $this->store->getState();
            $release = $state['release'] ?? null;
            if (!is_array($release) || empty($release['url'])) {
                $check = $this->checkForUpdate();
                if (!$check['ok']) {
                    return ['ok' => false, 'done' => false, 'error' => $check['error'], 'state' => $this->statusPayload()];
                }
                $release = $check['release'];
            }

            $zipName = 'whmcs-' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) $release['full_version']) . '.zip';
            $destPath = $this->store->getWorkDir() . '/' . $zipName;

            if ($localZip) {
                $this->store->mergeState([
                    'phase' => 'verify',
                    'status' => 'running',
                    'message' => 'Verifying local ZIP…',
                ]);
                $adopt = $this->downloader->adoptLocalZip($localZip, $destPath, $release);
                if (!$adopt['ok']) {
                    $this->store->mergeState([
                        'phase' => 'verify',
                        'status' => 'error',
                        'error' => $adopt['error'],
                        'message' => 'Local ZIP verification failed: ' . $adopt['error'],
                    ]);
                    return ['ok' => false, 'done' => false, 'error' => $adopt['error'], 'state' => $this->statusPayload()];
                }
                $this->store->mergeState([
                    'phase' => 'download',
                    'status' => 'complete',
                    'zip_path' => $this->store->relativizePath($destPath),
                    'bytes' => $adopt['bytes'],
                    'total' => (int) ($release['file_size'] ?? $adopt['bytes']),
                    'message' => 'Local ZIP verified.',
                    'error' => '',
                ]);
                return ['ok' => true, 'done' => true, 'error' => '', 'state' => $this->statusPayload()];
            }

            if ($minSpeed !== 1024) {
                $this->downloader = new ResumableDownloader(
                    new ProxyRepository(),
                    Settings::makeSelector(),
                    $minSpeed,
                    60,
                    Settings::int('connect_timeout', 10),
                    Settings::bool('socks5_remote_dns', true),
                    Settings::get('fallback_behavior', 'direct')
                );
                $store = $this->store;
                $this->downloader->onProgress(static function (array $progress) use ($store) {
                    $store->mergeState([
                        'phase' => 'download',
                        'bytes' => $progress['bytes'] ?? 0,
                        'total' => $progress['total'] ?? 0,
                        'proxy' => $progress['proxy'] ?? null,
                        'message' => $progress['message'] ?? 'downloading',
                    ]);
                });
            }

            $this->store->mergeState([
                'phase' => 'download',
                'status' => 'running',
                'zip_path' => $this->store->relativizePath($destPath),
                'message' => 'Downloading…',
                'error' => '',
            ]);
            $this->store->log('Download start: ' . $release['url']);

            $result = $this->downloader->download($release, $destPath, $budgetSeconds, $proxyId, $forceDirect);

            if ($result['done']) {
                $this->store->mergeState([
                    'phase' => 'download',
                    'status' => 'complete',
                    'zip_path' => $this->store->relativizePath($result['path']),
                    'bytes' => $result['bytes'],
                    'total' => $result['total'],
                    'proxy' => $result['proxy'],
                    'message' => 'Download complete and verified.',
                    'error' => '',
                ]);
                $this->store->log('Download complete: ' . $result['path']);
                return ['ok' => true, 'done' => true, 'error' => '', 'state' => $this->statusPayload()];
            }

            if ($result['ok'] && !$result['done']) {
                $this->store->mergeState([
                    'phase' => 'download',
                    'status' => 'running',
                    'zip_path' => $this->store->relativizePath($destPath),
                    'bytes' => $result['bytes'],
                    'total' => $result['total'],
                    'proxy' => $result['proxy'],
                    'message' => 'Download in progress…',
                    'error' => '',
                ]);
                return ['ok' => true, 'done' => false, 'error' => '', 'state' => $this->statusPayload()];
            }

            $this->store->mergeState([
                'phase' => 'download',
                'status' => 'error',
                'bytes' => $result['bytes'],
                'total' => $result['total'],
                'proxy' => $result['proxy'],
                'error' => $result['error'],
                'message' => 'Download failed: ' . $result['error'],
            ]);
            return ['ok' => false, 'done' => false, 'error' => $result['error'], 'state' => $this->statusPayload()];
        } finally {
            $this->store->releaseLock();
        }
    }

    /**
     * Start the staged apply job (does not run all phases in one request).
     *
     * @return array{ok:bool,error:string,state:array}
     */
    public function startApply(bool $confirmed = false, bool $doBackup = true): array
    {
        return $this->job->start([
            'yes' => $confirmed,
            'no_backup' => !$doBackup,
        ]);
    }

    /**
     * @return array{ok:bool,done:bool,error:string,state:array,needs_manual_installer:bool}
     */
    public function applyTick(int $budgetSeconds = UpdateJob::DEFAULT_BUDGET): array
    {
        return $this->job->tick($budgetSeconds);
    }

    /**
     * Full apply via ticks (CLI).
     *
     * @return array{ok:bool,error:string,state:array,needs_manual_installer:bool}
     */
    public function apply(bool $confirmed = false, bool $doBackup = true): array
    {
        $start = $this->startApply($confirmed, $doBackup);
        if (!$start['ok']) {
            return [
                'ok' => false,
                'error' => $start['error'],
                'state' => $start['state'],
                'needs_manual_installer' => false,
            ];
        }

        $result = $this->job->runToCompletion();
        return [
            'ok' => $result['ok'] && empty($result['needs_manual_installer']) && !empty($result['done']),
            'error' => $result['error'],
            'state' => $result['state'],
            'needs_manual_installer' => !empty($result['needs_manual_installer']),
        ];
    }

    /**
     * @return array{ok:bool,error:string,state:array}
     */
    public function finalizeOnly(?string $token = null): array
    {
        if ($token !== null && !$this->store->validateToken($token)) {
            // Allow finalize after token cleared on success path / manual continue.
            $state = $this->store->getState();
            if (($state['status'] ?? '') !== 'awaiting_manual' && ($state['phase'] ?? '') !== 'verify_db') {
                return ['ok' => false, 'error' => 'invalid_token', 'state' => $this->statusPayload()];
            }
        }

        $this->job->continueAfterManual();
        $result = $this->job->runToCompletion();
        return [
            'ok' => !empty($result['done']) && $result['ok'],
            'error' => $result['error'],
            'state' => $result['state'],
        ];
    }

    /**
     * @return array{ok:bool,error:string,state:array}
     */
    public function rollback(): array
    {
        return $this->job->rollback();
    }

    /**
     * @return array{ok:bool,error:string,state:array,needs_manual_installer?:bool}
     */
    public function run(array $options = []): array
    {
        $check = $this->checkForUpdate(
            $options['version'] ?? null,
            $options['channel'] ?? 'stable',
            !empty($options['direct']),
            isset($options['proxy_id']) ? (int) $options['proxy_id'] : null
        );
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['error'], 'state' => $this->statusPayload()];
        }

        $download = $this->download(
            0,
            $options['zip'] ?? null,
            isset($options['proxy_id']) ? (int) $options['proxy_id'] : null,
            !empty($options['direct']),
            isset($options['min_speed']) ? (int) $options['min_speed'] : 1024
        );
        if (!$download['ok'] || !$download['done']) {
            return ['ok' => false, 'error' => $download['error'] !== '' ? $download['error'] : 'download_incomplete', 'state' => $this->statusPayload()];
        }

        return $this->apply(!empty($options['yes']), empty($options['no_backup']));
    }

    public function getState(): array
    {
        return $this->store->getState();
    }

    public function mergeState(array $patch): void
    {
        $this->store->mergeState($patch);
    }

    /**
     * @return array{ok:bool,error:string,path:string,cleared:bool}
     */
    public function clearTemplateCache(): array
    {
        return $this->job->getApplier()->clearTemplateCache();
    }

    public function clearState(): void
    {
        $this->store->clearState();
    }

    public function statusPayload(): array
    {
        $payload = $this->job->statusPayload();
        if (empty($payload['installed'])) {
            try {
                $payload['installed'] = $this->installedVersion();
            } catch (\Throwable $e) {
                $payload['installed'] = $payload['installed'] ?? '0.0.0';
            }
        }

        return $payload;
    }
}
