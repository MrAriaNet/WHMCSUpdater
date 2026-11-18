<?php
/**
 * Staged, budget-limited WHMCS update job that can resume after interruption.
 *
 * Does not bootstrap WHMCS. Uses StateStore + UpdateApplier only.
 */

namespace WHMCSUpdater;

class UpdateJob
{
    public const PHASES = [
        'plan',
        'extract',
        'manifest',
        'backup',
        'maintenance',
        'copy',
        'copy_install',
        'verify_files',
        'upgrade',
        'verify_db',
        'finalize',
        'done',
    ];

    /** Default work budget per tick (seconds). */
    public const DEFAULT_BUDGET = 12;

    /** @var StateStore */
    private $store;

    /** @var UpdateApplier */
    private $applier;

    /** @var string */
    private $whmcsRoot;

    /** @var string|null Configured PHP CLI path */
    private $phpCliPath;

    public function __construct(StateStore $store, UpdateApplier $applier, ?string $phpCliPath = null)
    {
        $this->store = $store;
        $this->applier = $applier;
        $this->whmcsRoot = $applier->getWhmcsRoot();
        $this->phpCliPath = $phpCliPath;
    }

    public function getStore(): StateStore
    {
        return $this->store;
    }

    public function getApplier(): UpdateApplier
    {
        return $this->applier;
    }

    /**
     * Begin or re-arm an apply job after download is complete.
     *
     * @param array<string,mixed> $options
     * @return array{ok:bool,error:string,state:array}
     */
    public function start(array $options = []): array
    {
        $state = $this->store->getState();
        $zipPath = $this->resolveZipPath($state);
        if ($zipPath === '' || !is_file($zipPath)) {
            return ['ok' => false, 'error' => 'zip_missing', 'state' => $this->statusPayload()];
        }

        $token = $this->store->issueToken();
        $doBackup = empty($options['no_backup']);
        $confirmed = !empty($options['yes']) || !empty($options['confirm']);
        if (!$confirmed) {
            return ['ok' => false, 'error' => 'confirmation_required', 'state' => $this->statusPayload()];
        }

        // Resume interrupted job without resetting cursors / maintenance_prev.
        $phase = (string) ($state['phase'] ?? 'idle');
        $status = (string) ($state['status'] ?? 'idle');
        $hasCursor = is_array($state['cursor'] ?? null);
        $hasManifest = is_file($this->store->getWorkDir() . '/manifest.jsonl');
        // Legacy proxyupdater/proxypool states (copy/running without cursor/manifest) must re-plan.
        $legacyIncomplete = in_array($phase, ['copy', 'extract', 'upgrade', 'apply', 'finalize'], true)
            && (!$hasCursor || (!$hasManifest && in_array($phase, ['copy', 'upgrade', 'finalize', 'apply'], true)));

        $resuming = !$legacyIncomplete
            && in_array($status, ['running', 'error', 'interrupted', 'awaiting_manual'], true)
            && in_array($phase, self::PHASES, true)
            && $phase !== 'done'
            && $phase !== 'idle';

        if (!$resuming) {
            $this->store->mergeState([
                'phase' => 'plan',
                'status' => 'running',
                'message' => $legacyIncomplete
                    ? 'Re-planning after interrupted legacy apply…'
                    : 'Planning update…',
                'error' => '',
                'zip_path' => $this->store->relativizePath($zipPath),
                'status_token' => $token,
                'cursor' => [
                    'extract_index' => 0,
                    'backup_line' => 0,
                    'copy_line' => 0,
                    'copy_install_line' => 0,
                    'verify_line' => 0,
                ],
                'do_backup' => $doBackup,
                'package_root' => '',
                'backup_dir' => (string) ($state['backup_dir'] ?? ''),
                'manifest_path' => 'manifest.jsonl',
                'needs_manual_installer' => false,
                'force_manual_installer' => false,
                'apply_started_at' => time(),
                'completed_at' => null,
            ]);
        } else {
            $this->store->mergeState([
                'status' => 'running',
                'status_token' => $token,
                'error' => '',
                'message' => 'Resuming interrupted update…',
                'needs_manual_installer' => !empty($state['needs_manual_installer']),
                'force_manual_installer' => !empty($state['force_manual_installer']),
            ]);
        }

        return ['ok' => true, 'error' => '', 'state' => $this->statusPayload()];
    }

    /**
     * Advance the job by one budget-limited tick.
     *
     * @return array{ok:bool,done:bool,error:string,state:array,needs_manual_installer:bool}
     */
    public function tick(int $budgetSeconds = self::DEFAULT_BUDGET): array
    {
        if (!$this->store->acquireLock()) {
            return [
                'ok' => false,
                'done' => false,
                'error' => 'locked',
                'state' => $this->statusPayload(),
                'needs_manual_installer' => false,
            ];
        }

        try {
            @ignore_user_abort(true);
            @set_time_limit($budgetSeconds + 30);

            $state = $this->store->getState();
            $phase = (string) ($state['phase'] ?? 'idle');
            $status = (string) ($state['status'] ?? 'idle');

            if ($status === 'complete' && $phase === 'done') {
                return [
                    'ok' => true,
                    'done' => true,
                    'error' => '',
                    'state' => $this->statusPayload(),
                    'needs_manual_installer' => false,
                ];
            }

            if ($status === 'awaiting_manual') {
                return [
                    'ok' => true,
                    'done' => false,
                    'error' => '',
                    'state' => $this->statusPayload(),
                    'needs_manual_installer' => true,
                ];
            }

            if ($phase === 'idle' || $status === 'idle' || $status === 'ready') {
                return [
                    'ok' => false,
                    'done' => false,
                    'error' => 'not_started',
                    'state' => $this->statusPayload(),
                    'needs_manual_installer' => false,
                ];
            }

            $this->store->mergeState(['status' => 'running']);
            $this->store->touchLock();

            $result = $this->runPhase($phase, max(2, $budgetSeconds));
            $payload = $this->statusPayload();

            return [
                'ok' => $result['ok'],
                'done' => !empty($result['done']),
                'error' => $result['error'] ?? '',
                'state' => $payload,
                'needs_manual_installer' => !empty($payload['needs_manual_installer']),
            ];
        } catch (\Throwable $e) {
            $this->store->log('Tick exception: ' . $e->getMessage());
            $this->store->mergeState([
                'status' => 'error',
                'error' => $e->getMessage(),
                'message' => 'Update failed: ' . $e->getMessage(),
            ]);
            return [
                'ok' => false,
                'done' => false,
                'error' => $e->getMessage(),
                'state' => $this->statusPayload(),
                'needs_manual_installer' => false,
            ];
        } finally {
            $this->store->releaseLock();
        }
    }

    /**
     * Run ticks until done, waiting for manual, or error. For CLI use.
     *
     * @return array{ok:bool,done:bool,error:string,state:array,needs_manual_installer:bool}
     */
    public function runToCompletion(int $budgetSeconds = self::DEFAULT_BUDGET, int $maxTicks = 10000): array
    {
        $last = [
            'ok' => false,
            'done' => false,
            'error' => 'not_started',
            'state' => $this->statusPayload(),
            'needs_manual_installer' => false,
        ];

        for ($i = 0; $i < $maxTicks; $i++) {
            $last = $this->tick($budgetSeconds);
            if (!$last['ok'] && ($last['error'] ?? '') !== 'locked') {
                return $last;
            }
            if (!empty($last['done']) || !empty($last['needs_manual_installer'])) {
                return $last;
            }
            usleep(50000);
        }

        return [
            'ok' => false,
            'done' => false,
            'error' => 'max_ticks_exceeded',
            'state' => $this->statusPayload(),
            'needs_manual_installer' => false,
        ];
    }

    /**
     * Explicit rollback from the latest backup directory.
     *
     * @return array{ok:bool,error:string,state:array}
     */
    public function rollback(): array
    {
        if (!$this->store->acquireLock()) {
            return ['ok' => false, 'error' => 'locked', 'state' => $this->statusPayload()];
        }

        try {
            $state = $this->store->getState();
            $backupRel = (string) ($state['backup_dir'] ?? '');
            $backupDir = $backupRel !== '' ? $this->store->resolvePath($backupRel) : '';
            if ($backupDir === '' || !is_dir($backupDir)) {
                // Find newest backup_* under work dir.
                $backupDir = $this->findLatestBackup();
            }
            if ($backupDir === '' || !is_dir($backupDir)) {
                return ['ok' => false, 'error' => 'backup_missing', 'state' => $this->statusPayload()];
            }

            $this->store->log('Rolling back from ' . $backupDir);
            $this->store->mergeState([
                'phase' => 'rollback',
                'status' => 'running',
                'message' => 'Restoring files from backup…',
                'error' => '',
            ]);

            if (!$this->applier->restoreBackup($backupDir)) {
                $this->store->mergeState([
                    'status' => 'error',
                    'error' => 'rollback_failed',
                    'message' => 'Rollback failed.',
                ]);
                return ['ok' => false, 'error' => 'rollback_failed', 'state' => $this->statusPayload()];
            }

            $installDir = $this->whmcsRoot . '/install';
            if (is_dir($installDir)) {
                $this->applier->rrmdir($installDir);
            }
            $relocating = $this->whmcsRoot . '/.whmcs-relocating';
            if (is_file($relocating)) {
                @unlink($relocating);
            }
            $this->applier->restorePreviousMaintenanceMode();
            $this->applier->rrmdir($this->store->getWorkDir() . '/staging');

            $this->store->mergeState([
                'phase' => 'idle',
                'status' => 'idle',
                'message' => 'Rollback complete. Files restored from backup.',
                'error' => '',
                'needs_manual_installer' => false,
            ]);
            $this->store->clearToken();

            return ['ok' => true, 'error' => '', 'state' => $this->statusPayload()];
        } finally {
            $this->store->releaseLock();
        }
    }

    /**
     * After a manual browser installer run, verify DB and finalize.
     *
     * @return array{ok:bool,error:string,state:array}
     */
    public function continueAfterManual(): array
    {
        $this->store->mergeState([
            'phase' => 'verify_db',
            'status' => 'running',
            'message' => 'Verifying database version after manual installer…',
            'needs_manual_installer' => false,
            'error' => '',
        ]);

        return ['ok' => true, 'error' => '', 'state' => $this->statusPayload()];
    }

    /**
     * @return array{ok:bool,done:bool,error:string}
     */
    private function runPhase(string $phase, int $budget): array
    {
        switch ($phase) {
            case 'plan':
                return $this->phasePlan();
            case 'extract':
                return $this->phaseExtract($budget);
            case 'manifest':
                return $this->phaseManifest();
            case 'backup':
                return $this->phaseBackup($budget);
            case 'maintenance':
                return $this->phaseMaintenance();
            case 'copy':
                return $this->phaseCopy($budget, false);
            case 'copy_install':
                return $this->phaseCopy($budget, true);
            case 'verify_files':
                return $this->phaseVerifyFiles($budget);
            case 'upgrade':
                return $this->phaseUpgrade();
            case 'verify_db':
                return $this->phaseVerifyDb();
            case 'finalize':
                return $this->phaseFinalize();
            case 'done':
                return ['ok' => true, 'done' => true, 'error' => ''];
            default:
                $this->store->mergeState([
                    'status' => 'error',
                    'error' => 'unknown_phase:' . $phase,
                    'message' => 'Unknown phase: ' . $phase,
                ]);
                return ['ok' => false, 'done' => false, 'error' => 'unknown_phase'];
        }
    }

    private function phasePlan(): array
    {
        $state = $this->store->getState();
        $zipPath = $this->resolveZipPath($state);
        if ($zipPath === '' || !is_file($zipPath)) {
            return $this->fail('zip_missing', 'ZIP not found. Download first.');
        }

        $release = is_array($state['release'] ?? null) ? $state['release'] : null;
        if (is_array($release)) {
            $expectedSize = (int) ($release['file_size'] ?? 0);
            $expectedSha = strtolower((string) ($release['sha'] ?? ''));
            if ($expectedSize > 0 && (int) filesize($zipPath) !== $expectedSize) {
                return $this->fail('size_mismatch', 'ZIP size does not match catalog metadata.');
            }
            if ($expectedSha !== '') {
                $hash = hash_file('sha256', $zipPath);
                if ($hash === false || strtolower($hash) !== $expectedSha) {
                    return $this->fail('sha256_mismatch', 'ZIP SHA-256 verification failed.');
                }
            }

            $compatGuard = $this->guardReleaseCompatibility($release);
            if (!$compatGuard['ok']) {
                return $this->fail($compatGuard['error'], $compatGuard['message']);
            }
            if (!empty($compatGuard['force_manual'])) {
                $this->store->mergeState([
                    'force_manual_installer' => true,
                    'message' => $compatGuard['message'],
                ]);
                $this->store->log($compatGuard['message']);
            }
        }

        $pre = $this->applier->preflight($zipPath);
        if (!$pre['ok']) {
            $err = implode(',', $pre['errors']);
            return $this->fail($err, 'Preflight failed: ' . $err);
        }

        $dry = $this->applier->writeDryRun();
        if (!$dry['ok']) {
            return $this->fail($dry['error'], 'Write dry-run failed: ' . $dry['error']);
        }

        // Fresh staging for a new plan (not when resuming extract mid-way).
        $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : [];
        if ((int) ($cursor['extract_index'] ?? 0) === 0) {
            $this->applier->rrmdir($this->store->getWorkDir() . '/staging');
        }

        $this->advance('extract', 'Extracting release…');
        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    /**
     * Ensure the live web PHP (and preferably CLI PHP) can run the target.
     *
     * @param array<string,mixed> $release
     * @return array{ok:bool,error:string,message:string,force_manual:bool}
     */
    private function guardReleaseCompatibility(array $release): array
    {
        $catalog = new ReleaseCatalog();
        $webPhp = $this->detectWebPhpVersion();
        $webCompat = $catalog->checkCompatibility($release, $webPhp, null);
        if (!$webCompat['ok']) {
            return [
                'ok' => false,
                'error' => 'php_web_incompatible:' . $webCompat['error'],
                'message' => 'Web PHP ' . $webPhp . ' cannot run WHMCS '
                    . ($release['full_version'] ?? $release['version'] ?? '')
                    . ' (' . $webCompat['error'] . '). Upgrade web PHP/ionCube first.',
                'force_manual' => false,
            ];
        }

        $php = $this->applier->detectPhpBinary($this->phpCliPath);
        $validated = $this->applier->validatePhpBinary($php);
        if (!$validated['ok']) {
            return [
                'ok' => true,
                'error' => '',
                'message' => 'PHP CLI unavailable or missing ionCube; will use browser installer after file copy.',
                'force_manual' => true,
            ];
        }

        $cliPhp = (string) ($validated['php_version'] ?? '');
        $cliIon = (string) ($validated['ioncube_version'] ?? '');
        if ($cliPhp !== '') {
            $cliCompat = $catalog->checkCompatibility($release, $cliPhp, $cliIon);
            if (!$cliCompat['ok']) {
                return [
                    'ok' => true,
                    'error' => '',
                    'message' => 'PHP CLI ' . $cliPhp . ' is incompatible with the target release; '
                        . 'files will be applied then finish via /install/install.php ('
                        . $cliCompat['error'] . ').',
                    'force_manual' => true,
                ];
            }
        }

        return ['ok' => true, 'error' => '', 'message' => '', 'force_manual' => false];
    }

    private function detectWebPhpVersion(): string
    {
        $pointer = dirname(__DIR__) . '/storage/web_php_pointer.txt';
        if (is_file($pointer)) {
            $stored = trim((string) @file_get_contents($pointer));
            if ($stored !== '' && preg_match('/^\d+\.\d+/', $stored)) {
                return $stored;
            }
        }

        return PHP_VERSION;
    }

    private function phaseExtract(int $budget): array
    {
        $state = $this->store->getState();
        $zipPath = $this->resolveZipPath($state);
        $staging = $this->store->getWorkDir() . '/staging';
        $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : [];
        $start = (int) ($cursor['extract_index'] ?? 0);

        $this->store->mergeState([
            'message' => sprintf('Extracting release (index %d)…', $start),
        ]);

        $result = $this->applier->extractChunk($zipPath, $staging, $start, $budget);
        if (!$result['ok']) {
            return $this->fail($result['error'], 'Extract failed: ' . $result['error']);
        }

        $cursor['extract_index'] = (int) ($result['next_index'] ?? $start);
        $patch = [
            'cursor' => $cursor,
            'message' => sprintf(
                'Extracted %d / %d entries…',
                (int) ($result['next_index'] ?? 0),
                (int) ($result['total'] ?? 0)
            ),
        ];

        if (!empty($result['done'])) {
            $patch['package_root'] = $this->store->relativizePath((string) $result['package_root']);
            $patch['phase'] = 'manifest';
            $patch['message'] = 'Building file manifest…';
            $this->store->log('Package root: ' . $result['package_root']);
        }

        $this->store->mergeState($patch);

        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    private function phaseManifest(): array
    {
        $state = $this->store->getState();
        $packageRoot = $this->resolvePackageRoot($state);
        if ($packageRoot === '' || !is_dir($packageRoot)) {
            return $this->fail('package_root_missing', 'Package root not found after extract.');
        }

        $manifestPath = $this->store->getWorkDir() . '/manifest.jsonl';
        $built = $this->applier->buildManifest($packageRoot, $manifestPath);
        if (!$built['ok']) {
            return $this->fail($built['error'], 'Manifest failed: ' . $built['error']);
        }

        $doBackup = !empty($state['do_backup']);
        if ($doBackup) {
            $backupDir = (string) ($state['backup_dir'] ?? '');
            if ($backupDir === '') {
                $existing = $this->findLatestBackup();
                $backupDir = $existing !== ''
                    ? $this->store->relativizePath($existing)
                    : ('backup_' . date('Ymd_His'));
            }
            $this->store->mergeState([
                'backup_dir' => $backupDir,
                'manifest_path' => 'manifest.jsonl',
                'phase' => 'backup',
                'message' => sprintf('Backing up overlapping files (%d)…', $built['count']),
            ]);
        } else {
            $this->store->mergeState([
                'manifest_path' => 'manifest.jsonl',
                'phase' => 'maintenance',
                'message' => 'Enabling maintenance mode…',
            ]);
        }

        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    private function phaseBackup(int $budget): array
    {
        $state = $this->store->getState();
        $manifest = $this->store->getWorkDir() . '/manifest.jsonl';
        $backupRel = (string) ($state['backup_dir'] ?? '');
        $backupDir = $this->store->resolvePath($backupRel);
        $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : [];
        $start = (int) ($cursor['backup_line'] ?? 0);

        $result = $this->applier->backupChunk($manifest, $backupDir, $start, $budget);
        if (!$result['ok']) {
            return $this->fail($result['error'], 'Backup failed: ' . $result['error']);
        }

        $cursor['backup_line'] = (int) $result['next_line'];
        $patch = [
            'cursor' => $cursor,
            'message' => sprintf('Backed up through line %d…', $cursor['backup_line']),
        ];
        if (!empty($result['done'])) {
            $patch['phase'] = 'maintenance';
            $patch['message'] = 'Enabling maintenance mode…';
        }
        $this->store->mergeState($patch);

        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    private function phaseMaintenance(): array
    {
        $this->applier->ensureMaintenanceOn();
        $this->advance('copy', 'Copying release files…');

        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    private function phaseCopy(int $budget, bool $installOnly): array
    {
        $state = $this->store->getState();
        $packageRoot = $this->resolvePackageRoot($state);
        $manifest = $this->store->getWorkDir() . '/manifest.jsonl';
        $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : [];
        $key = $installOnly ? 'copy_install_line' : 'copy_line';
        $start = (int) ($cursor[$key] ?? 0);

        $result = $this->applier->copyChunk($packageRoot, $manifest, $start, $installOnly, $budget);
        if (!$result['ok']) {
            return $this->fail($result['error'], 'Copy failed: ' . $result['error']);
        }

        $cursor[$key] = (int) $result['next_line'];
        $patch = [
            'cursor' => $cursor,
            'message' => sprintf(
                '%s copy through line %d…',
                $installOnly ? 'Install' : 'File',
                $cursor[$key]
            ),
        ];

        if (!empty($result['done'])) {
            if (!$installOnly) {
                $patch['phase'] = 'copy_install';
                $patch['message'] = 'Copying install directory…';
            } else {
                $patch['phase'] = 'verify_files';
                $patch['message'] = 'Verifying copied files…';
            }
        }
        $this->store->mergeState($patch);

        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    private function phaseVerifyFiles(int $budget): array
    {
        $state = $this->store->getState();
        $packageRoot = $this->resolvePackageRoot($state);
        $manifest = $this->store->getWorkDir() . '/manifest.jsonl';
        $cursor = is_array($state['cursor'] ?? null) ? $state['cursor'] : [];
        $start = (int) ($cursor['verify_line'] ?? 0);

        $result = $this->applier->verifyChunk($packageRoot, $manifest, $start, $budget);
        if (!$result['ok']) {
            return $this->fail($result['error'], 'File verification failed: ' . $result['error']);
        }

        $cursor['verify_line'] = (int) $result['next_line'];
        $patch = [
            'cursor' => $cursor,
            'message' => sprintf('Verified through line %d…', $cursor['verify_line']),
        ];
        if (!empty($result['done'])) {
            $patch['phase'] = 'upgrade';
            $patch['message'] = 'Running database upgrade…';
        }
        $this->store->mergeState($patch);

        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    private function phaseUpgrade(): array
    {
        $state = $this->store->getState();
        if (!empty($state['force_manual_installer'])) {
            $this->store->mergeState([
                'phase' => 'upgrade',
                'status' => 'awaiting_manual',
                'error' => 'php_cli_incompatible',
                'message' => 'Files verified. PHP CLI is incompatible with this release — finish via /install/install.php, then Continue / Finalize.',
                'needs_manual_installer' => true,
            ]);
            return ['ok' => true, 'done' => false, 'error' => ''];
        }

        $php = $this->applier->detectPhpBinary($this->phpCliPath);
        $result = $this->applier->runInstallerUpgrade($php);

        if (!$result['ok'] && ($result['error'] === 'proc_open_disabled'
            || $result['error'] === 'php_cli_missing_ioncube'
            || $result['error'] === 'php_binary_missing'
            || $result['error'] === 'php_binary_unusable'
            || strpos((string) $result['error'], 'php_not_cli_sapi') === 0
        )) {
            $this->store->mergeState([
                'phase' => 'upgrade',
                'status' => 'awaiting_manual',
                'error' => $result['error'],
                'message' => 'Files verified. Complete the upgrade in the browser via /install/install.php, then click Continue / Finalize.',
                'needs_manual_installer' => true,
                'installer_output' => substr((string) ($result['output'] ?? ''), -4000),
            ]);
            return ['ok' => true, 'done' => false, 'error' => ''];
        }

        if (!$result['done']) {
            $this->store->mergeState([
                'message' => 'Database upgrade running (pid ' . (int) ($result['pid'] ?? 0) . ')…',
                'installer_output' => substr((string) ($result['output'] ?? ''), -2000),
            ]);
            return ['ok' => true, 'done' => false, 'error' => ''];
        }

        if (!$result['ok']) {
            return $this->fail(
                $result['error'] !== '' ? $result['error'] : 'installer_failed',
                'Installer failed: ' . ($result['error'] !== '' ? $result['error'] : 'installer_failed')
            );
        }

        $this->advance('verify_db', 'Verifying database version…');

        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    private function phaseVerifyDb(): array
    {
        $state = $this->store->getState();
        $release = is_array($state['release'] ?? null) ? $state['release'] : null;
        $target = is_array($release) ? (string) ($release['version'] ?? '') : '';
        $dbVersion = $this->applier->readDbVersion();

        if ($dbVersion === null) {
            return $this->fail('db_version_unreadable', 'Could not read database Version setting.');
        }

        $this->store->mergeState([
            'db_version' => $dbVersion,
            'message' => 'Database version: ' . $dbVersion,
        ]);

        if ($target !== '') {
            $normalizedDb = preg_match('/^(\d+(?:\.\d+){1,3})/', $dbVersion, $m) ? $m[1] : $dbVersion;
            if (version_compare($normalizedDb, $target, '<')) {
                // Maybe manual installer not finished yet.
                if (!empty($state['needs_manual_installer']) || is_dir($this->whmcsRoot . '/install')) {
                    $this->store->mergeState([
                        'status' => 'awaiting_manual',
                        'needs_manual_installer' => true,
                        'message' => 'Database still at ' . $dbVersion . '. Finish /install/install.php then continue.',
                    ]);
                    return ['ok' => true, 'done' => false, 'error' => ''];
                }
                return $this->fail(
                    'db_version_mismatch',
                    sprintf('Database version %s is below target %s.', $dbVersion, $target)
                );
            }
        }

        $this->advance('finalize', 'Finalizing…');

        return ['ok' => true, 'done' => false, 'error' => ''];
    }

    private function phaseFinalize(): array
    {
        $final = $this->applier->finalize(true);
        if (!$final['ok']) {
            return $this->fail($final['error'], 'Finalize failed: ' . $final['error']);
        }

        $this->applier->rrmdir($this->store->getWorkDir() . '/staging');
        @unlink($this->store->getWorkDir() . '/manifest.jsonl');
        @unlink($this->store->getWorkDir() . '/installer.pid');

        $this->store->mergeState([
            'phase' => 'done',
            'status' => 'complete',
            'message' => 'Update completed successfully.',
            'error' => '',
            'needs_manual_installer' => false,
            'completed_at' => time(),
        ]);
        $this->store->clearToken();
        $this->store->log('Update completed successfully.');

        return ['ok' => true, 'done' => true, 'error' => ''];
    }

    private function advance(string $phase, string $message): void
    {
        $this->store->mergeState([
            'phase' => $phase,
            'status' => 'running',
            'message' => $message,
            'error' => '',
        ]);
        $this->store->log($message);
    }

    /**
     * @return array{ok:bool,done:bool,error:string}
     */
    private function fail(string $error, string $message): array
    {
        $this->store->log($message);
        $this->store->mergeState([
            'status' => 'error',
            'error' => $error,
            'message' => $message,
        ]);

        return ['ok' => false, 'done' => false, 'error' => $error];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function resolveZipPath(array $state): string
    {
        $zipPath = (string) ($state['zip_path'] ?? '');
        if ($zipPath !== '') {
            $resolved = $this->store->resolvePath($zipPath);
            if (is_file($resolved)) {
                return $resolved;
            }
        }

        $release = $state['release'] ?? null;
        if (is_array($release) && !empty($release['full_version'])) {
            $zipName = 'whmcs-' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) $release['full_version']) . '.zip';
            $candidate = $this->store->getWorkDir() . '/' . $zipName;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $state
     */
    private function resolvePackageRoot(array $state): string
    {
        $stored = (string) ($state['package_root'] ?? '');
        if ($stored === '') {
            return '';
        }
        $path = $this->store->resolvePath($stored);
        if (is_dir($path)) {
            return $path;
        }
        // Absolute path stored before relativize support.
        if (is_dir($stored)) {
            return rtrim($stored, '/');
        }

        return '';
    }

    private function findLatestBackup(): string
    {
        $dir = $this->store->getWorkDir();
        $entries = @scandir($dir) ?: [];
        $best = '';
        $bestMtime = 0;
        foreach ($entries as $entry) {
            if (strpos($entry, 'backup_') !== 0) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $mtime = (int) @filemtime($path);
            if ($mtime >= $bestMtime) {
                $bestMtime = $mtime;
                $best = $path;
            }
        }

        return $best;
    }

    /**
     * @return array<string,mixed>
     */
    public function statusPayload(): array
    {
        $state = $this->store->getState();
        $bytes = (int) ($state['bytes'] ?? 0);
        $total = (int) ($state['total'] ?? 0);
        $percent = ($total > 0) ? round(($bytes / $total) * 100, 1) : 0;

        if (!empty($state['zip_path'])) {
            $zipPath = $this->store->resolvePath((string) $state['zip_path']);
            $part = $zipPath . '.part';
            if (is_file($part)) {
                $bytes = (int) filesize($part);
                $percent = ($total > 0) ? round(($bytes / $total) * 100, 1) : 0;
            } elseif (is_file($zipPath)) {
                $bytes = (int) filesize($zipPath);
                $percent = ($total > 0) ? round(($bytes / $total) * 100, 1) : 100;
            }
        }

        $interrupted = $this->store->looksInterrupted();
        $status = (string) ($state['status'] ?? 'idle');
        if ($interrupted) {
            $status = 'interrupted';
        }

        $token = (string) ($state['status_token'] ?? '');
        if ($token === '' && is_file($this->store->getWorkDir() . '/' . StateStore::TOKEN_FILE)) {
            $token = trim((string) file_get_contents($this->store->getWorkDir() . '/' . StateStore::TOKEN_FILE));
        }

        // Apply progress estimate from cursors when in apply phases.
        $applyPhases = ['plan', 'extract', 'manifest', 'backup', 'maintenance', 'copy', 'copy_install', 'verify_files', 'upgrade', 'verify_db', 'finalize'];
        $phase = (string) ($state['phase'] ?? 'idle');
        if (in_array($phase, $applyPhases, true) && $phase !== 'done') {
            $idx = array_search($phase, $applyPhases, true);
            if ($idx !== false) {
                $percent = round((($idx + 1) / count($applyPhases)) * 100, 1);
            }
        }
        if ($phase === 'done' && $status === 'complete') {
            $percent = 100;
        }

        return [
            'phase' => $phase,
            'status' => $status,
            'installed' => $state['installed'] ?? null,
            'db_version' => $state['db_version'] ?? null,
            'release' => $state['release'] ?? null,
            'blocked' => $state['blocked'] ?? null,
            'major_upgrade' => !empty($state['major_upgrade']),
            'message' => $state['message'] ?? '',
            'error' => $state['error'] ?? '',
            'bytes' => $bytes,
            'total' => $total,
            'percent' => $percent,
            'proxy' => $state['proxy'] ?? null,
            'status_token' => $token !== '' ? $token : null,
            'needs_manual_installer' => (($state['status'] ?? '') === 'awaiting_manual') || !empty($state['needs_manual_installer']),
            'interrupted' => $interrupted,
            'updated_at' => $state['updated_at'] ?? null,
            'completed_at' => $state['completed_at'] ?? null,
            'work_dir' => $this->store->getWorkDir(),
            'admin_dir' => $this->applier->getAdminDirName(),
            'crons_dir' => $this->applier->getCronsDir(),
            'cursor' => $state['cursor'] ?? null,
            'backup_dir' => $state['backup_dir'] ?? null,
        ];
    }

    /**
     * Factory that does not require WHMCS bootstrap.
     */
    public static function createStandalone(?string $whmcsRoot = null, ?string $workDir = null, ?string $phpCliPath = null): self
    {
        $root = $whmcsRoot ? rtrim($whmcsRoot, '/') : self::detectWhmcsRoot();
        $work = $workDir ? rtrim($workDir, '/') : self::defaultWorkDir($root);
        $store = new StateStore($work);
        $applier = new UpdateApplier($root, $work, static function (string $msg) use ($store) {
            $store->log($msg);
        });

        return new self($store, $applier, $phpCliPath);
    }

    public static function detectWhmcsRoot(): string
    {
        // modules/addons/whmcsupdater/lib -> four levels up
        $candidate = dirname(__DIR__, 4);
        if (is_file($candidate . '/init.php') && is_file($candidate . '/configuration.php')) {
            return $candidate;
        }

        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/init.php') && is_file($dir . '/configuration.php')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return $candidate;
    }

    public static function defaultWorkDir(?string $whmcsRoot = null): string
    {
        return dirname(__DIR__) . '/storage/manual_update';
    }
}
