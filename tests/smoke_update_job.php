<?php
/**
 * Smoke-test staged UpdateJob with an intentional mid-copy interrupt.
 *
 * Usage: php tests/smoke_update_job.php
 *        php -d extension=zip tests/smoke_update_job.php
 */

namespace {
    define('WHMCSUPDATER_STANDALONE', true);
    require_once dirname(__DIR__) . '/modules/addons/whmcsupdater/lib/bootstrap.php';
}

namespace {
    use WHMCSUpdater\StateStore;
    use WHMCSUpdater\UpdateApplier;
    use WHMCSUpdater\UpdateJob;

    $failed = 0;

    function fail(string $msg): void
    {
        global $failed;
        echo "FAIL $msg\n";
        $failed++;
    }

    function ok(string $msg): void
    {
        echo "OK  $msg\n";
    }

    function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    $tmpRoot = sys_get_temp_dir() . '/pu_job_' . getmypid();
    rrmdir($tmpRoot);
    @mkdir($tmpRoot, 0755, true);

    $whmcs = $tmpRoot . '/whmcs';
    $work = $tmpRoot . '/work';
    @mkdir($whmcs, 0755, true);
    @mkdir($whmcs . '/myadmin', 0755, true);
    @mkdir($whmcs . '/custom_crons', 0755, true);
    @mkdir($whmcs . '/includes', 0755, true);
    @mkdir($work, 0755, true);

    $configBody = "<?php\n\$customadminpath = 'myadmin';\n\$crons_dir = '{$whmcs}/custom_crons';\n\$db_host='localhost';\n\$db_username='x';\n\$db_password='x';\n\$db_name='x';\n";
    file_put_contents($whmcs . '/configuration.php', $configBody);
    file_put_contents($whmcs . '/init.php', "<?php\n// old init\n");
    file_put_contents($whmcs . '/includes/old.php', "old\n");
    file_put_contents($whmcs . '/myadmin/index.php', "old admin\n");
    @mkdir($whmcs . '/vendor/whmcs/whmcs-foundation/lib', 0755, true);
    file_put_contents($whmcs . '/vendor/whmcs/whmcs-foundation/lib/License.php', "<?php\n// CUSTOM LICENSE\n");

    // --- path mapping ---
    $applier = new UpdateApplier($whmcs, $work);
    if ($applier->getAdminDirName() !== 'myadmin') {
        fail('admin dir override');
    } else {
        ok('admin dir override');
    }
    if ($applier->mapRelativePath('admin/index.php') !== 'myadmin/index.php') {
        fail('map admin path');
    } else {
        ok('map admin path');
    }
    if ($applier->mapRelativePath('crons/cron.php') !== '__crons__/cron.php') {
        fail('map crons path');
    } else {
        ok('map crons path');
    }

    // --- StateStore lock / interrupt detection ---
    $store = new StateStore($work);
    if (!$store->acquireLock()) {
        fail('acquire lock');
    } else {
        ok('acquire lock');
    }
    $store->mergeState(['phase' => 'copy', 'status' => 'running', 'message' => 'copying']);
    $store->releaseLock();
    if (!$store->looksInterrupted()) {
        fail('looksInterrupted expected true');
    } else {
        ok('looksInterrupted after release');
    }

    // --- atomic copy preserves configuration.php skip ---
    $src = $tmpRoot . '/src.txt';
    $dst = $whmcs . '/includes/new.txt';
    file_put_contents($src, "hello-world");
    $applier->atomicCopy($src, $dst);
    if (!is_file($dst) || file_get_contents($dst) !== "hello-world") {
        fail('atomicCopy');
    } else {
        ok('atomicCopy');
    }
    if ($applier->shouldSkipRelative('configuration.php') !== true) {
        fail('skip configuration.php');
    } else {
        ok('skip configuration.php');
    }
    if ($applier->shouldSkipRelative('vendor/whmcs/whmcs-foundation/lib/License.php') !== true) {
        fail('skip License.php');
    } else {
        ok('skip License.php');
    }

    // --- full staged job with zip (if extension available) ---
    if (!class_exists('ZipArchive')) {
        ok('skip zip-based job tests (zip extension missing)');
        rrmdir($tmpRoot);
        exit($failed > 0 ? 1 : 0);
    }

    $pkg = $tmpRoot . '/pkg';
    @mkdir($pkg . '/whmcs/install/bin', 0755, true);
    @mkdir($pkg . '/whmcs/admin', 0755, true);
    @mkdir($pkg . '/whmcs/crons', 0755, true);
    @mkdir($pkg . '/whmcs/includes', 0755, true);
    file_put_contents($pkg . '/whmcs/init.php', "<?php\n// new init v9\n");
    file_put_contents($pkg . '/whmcs/configuration.php', "<?php\n// MUST NOT overwrite\n");
    @mkdir($pkg . '/whmcs/vendor/whmcs/whmcs-foundation/lib', 0755, true);
    file_put_contents($pkg . '/whmcs/vendor/whmcs/whmcs-foundation/lib/License.php', "<?php\n// STOCK LICENSE FROM ZIP\n");
    file_put_contents($pkg . '/whmcs/includes/new.php', "new file\n");
    file_put_contents($pkg . '/whmcs/includes/old.php', "replaced old\n");
    file_put_contents($pkg . '/whmcs/admin/index.php', "new admin\n");
    file_put_contents($pkg . '/whmcs/crons/cron.php', "new cron\n");
    file_put_contents($pkg . '/whmcs/install/install.php', "<?php\n// install ui\n");
    // Fake installer that writes a marker and exits 0.
    $installer = <<<'PHP'
<?php
file_put_contents(dirname(__DIR__, 2) . '/.installer_ran', 'ok');
fwrite(STDOUT, "Program Completed\n");
exit(0);
PHP;
    file_put_contents($pkg . '/whmcs/install/bin/installer.php', $installer);

    // Many filler files so a tiny budget interrupts mid-copy.
    for ($i = 0; $i < 80; $i++) {
        file_put_contents($pkg . '/whmcs/includes/fill_' . $i . '.php', "fill $i\n");
    }

    $zipPath = $work . '/whmcs-9.1.1-release.1.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail('create fixture zip');
        rrmdir($tmpRoot);
        exit(1);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pkg, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $abs = $file->getPathname();
        $rel = substr($abs, strlen($pkg) + 1);
        $zip->addFile($abs, $rel);
    }
    $zip->close();
    ok('created fixture zip');

    $store->mergeState([
        'phase' => 'download',
        'status' => 'complete',
        'zip_path' => 'whmcs-9.1.1-release.1.zip',
        'release' => [
            'version' => '9.1.1',
            'full_version' => '9.1.1-release.1',
            'file_size' => filesize($zipPath),
            'sha' => hash_file('sha256', $zipPath),
            'url' => 'https://example.invalid/whmcs.zip',
        ],
        'installed' => '9.0.9',
    ]);

    // Inject a stub installer runner by subclassing via a thin wrapper:
    // Replace detectPhpBinary to point at current PHP and skip ionCube check
    // by writing a tiny shim PHP that always reports cli+ioncube for validate,
    // while the real installer is run with current PHP.
    $job = new class ($store, $applier) extends UpdateJob {
        public function __construct(StateStore $store, UpdateApplier $applier)
        {
            parent::__construct($store, $applier, PHP_BINARY);
        }
    };

    // Monkey-patch validate by writing php_cli_pointer unused; instead override
    // UpdateApplier::validatePhpBinary via anonymous subclass.
    $applier2 = new class ($whmcs, $work, function ($m) use ($store) { $store->log($m); }) extends UpdateApplier {
        public function validatePhpBinary(string $php): array
        {
            return ['ok' => true, 'error' => '', 'path' => $php, 'output' => 'cli|8.2|1'];
        }

        public function readDbVersion(): ?string
        {
            // Pretend DB upgraded after installer marker exists.
            if (is_file($this->getWhmcsRoot() . '/.installer_ran')) {
                return '9.1.1';
            }
            return '9.0.9';
        }

        public function writeConfigSetting(string $setting, string $value): void
        {
            // No DB in smoke test — persist maintenance flag to a file.
            if ($setting === 'MaintenanceMode') {
                file_put_contents($this->getWhmcsRoot() . '/.maintenance', $value);
            }
        }

        public function readConfigSetting(string $setting)
        {
            if ($setting === 'MaintenanceMode') {
                $f = $this->getWhmcsRoot() . '/.maintenance';
                return is_file($f) ? trim((string) file_get_contents($f)) : 'off';
            }
            if ($setting === 'Version') {
                return $this->readDbVersion();
            }
            return null;
        }
    };

    $job = new UpdateJob($store, $applier2, PHP_BINARY);
    $start = $job->start(['yes' => true, 'no_backup' => false]);
    if (!$start['ok']) {
        fail('job start: ' . $start['error']);
    } else {
        ok('job start');
    }

    // Run a few tiny-budget ticks then stop to simulate interrupt.
    $ticks = 0;
    $interrupted = false;
    for ($i = 0; $i < 8; $i++) {
        $ticks++;
        $res = $job->tick(1);
        $phase = $res['state']['phase'] ?? '';
        if (in_array($phase, ['copy', 'copy_install', 'backup', 'extract'], true) && empty($res['done'])) {
            $interrupted = true;
            break;
        }
        if (!empty($res['done']) || !$res['ok']) {
            break;
        }
    }
    ok("ran $ticks ticks before interrupt simulation (phase=" . ($job->statusPayload()['phase'] ?? '?') . ')');

    // Force interrupted appearance.
    $store->releaseLock();
    $store->mergeState(['status' => 'running']);
    if (!$store->looksInterrupted() && ($job->statusPayload()['status'] ?? '') !== 'interrupted') {
        // looksInterrupted requires running + no lock — we set that.
        if (!$store->looksInterrupted()) {
            fail('expected interrupted after forced running without lock');
        } else {
            ok('interrupted detection');
        }
    } else {
        ok('interrupted detection');
    }

    // Resume and finish.
    $resume = $job->start(['yes' => true]);
    if (!$resume['ok']) {
        fail('resume start: ' . $resume['error']);
    } else {
        ok('resume start');
    }

    $final = $job->runToCompletion(8, 5000);
    if (empty($final['done']) || empty($final['ok'])) {
        fail('runToCompletion: ' . ($final['error'] ?: json_encode($final['state'] ?? [])));
    } else {
        ok('runToCompletion done');
    }

    // Assertions on live install.
    if (trim((string) file_get_contents($whmcs . '/configuration.php')) !== trim($configBody)) {
        fail('configuration.php was overwritten');
    } else {
        ok('configuration.php preserved');
    }
    $licenseLive = trim((string) file_get_contents($whmcs . '/vendor/whmcs/whmcs-foundation/lib/License.php'));
    if ($licenseLive !== '<?php' . "\n" . '// CUSTOM LICENSE') {
        fail('License.php was overwritten');
    } else {
        ok('License.php preserved');
    }
    if (!is_file($whmcs . '/includes/new.php')) {
        fail('new.php missing');
    } else {
        ok('new.php copied');
    }
    if (trim((string) file_get_contents($whmcs . '/includes/old.php')) !== 'replaced old') {
        fail('old.php not replaced');
    } else {
        ok('old.php replaced');
    }
    if (trim((string) file_get_contents($whmcs . '/myadmin/index.php')) !== 'new admin') {
        fail('custom admin not mapped');
    } else {
        ok('custom admin mapped');
    }
    if (trim((string) file_get_contents($whmcs . '/custom_crons/cron.php')) !== 'new cron') {
        fail('custom crons not mapped');
    } else {
        ok('custom crons mapped');
    }
    if (is_dir($whmcs . '/install')) {
        fail('install/ still present');
    } else {
        ok('install/ removed');
    }
    if (!is_file($whmcs . '/.installer_ran')) {
        fail('installer did not run');
    } else {
        ok('installer ran');
    }

    // Backup should contain original old.php if backup phase ran.
    $backupDirs = array_values(array_filter(scandir($work) ?: [], static function ($e) {
        return strpos($e, 'backup_') === 0;
    }));
    if ($backupDirs === []) {
        fail('no backup directory');
    } else {
        $b = $work . '/' . $backupDirs[0] . '/includes/old.php';
        if (is_file($b) && trim((string) file_get_contents($b)) === 'old') {
            ok('backup kept original old.php');
        } else {
            // May be missing if file was new-only; check admin original.
            $ba = $work . '/' . $backupDirs[0] . '/myadmin/index.php';
            if (is_file($ba) && trim((string) file_get_contents($ba)) === 'old admin') {
                ok('backup kept original admin index');
            } else {
                fail('backup missing original content');
            }
        }
    }

    rrmdir($tmpRoot);
    exit($failed > 0 ? 1 : 0);
}
