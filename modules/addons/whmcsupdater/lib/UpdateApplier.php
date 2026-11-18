<?php
/**
 * Low-level file operations for applying a WHMCS release package.
 *
 * Intentionally free of WHMCS bootstrap so interrupted upgrades can finish
 * while install/ is present (Down for Maintenance Err 2).
 */

namespace WHMCSUpdater;

class UpdateApplier
{
    /** @var string Absolute WHMCS root */
    private $whmcsRoot;

    /** @var string Absolute work directory */
    private $workDir;

    /** @var callable|null */
    private $logger;

    /** @var string */
    private $adminDirName = 'admin';

    /** @var string Absolute path to crons directory */
    private $cronsDir = '';

    /** @var \PDO|null */
    private $pdo = null;

    /** @var bool */
    private $pdoTried = false;

    public function __construct(string $whmcsRoot, string $workDir, ?callable $logger = null)
    {
        $this->whmcsRoot = rtrim($whmcsRoot, '/');
        $this->workDir = rtrim($workDir, '/');
        $this->logger = $logger;
        $this->loadPathOverrides();
    }

    private function loadPathOverrides(): void
    {
        $configFile = $this->whmcsRoot . '/configuration.php';
        $customadminpath = 'admin';
        $crons_dir = $this->whmcsRoot . '/crons';

        if (is_file($configFile)) {
            $license = null;
            $db_host = null;
            $db_username = null;
            $db_password = null;
            $db_name = null;
            $cc_encryption_hash = null;
            $templates_compiledir = null;
            include $configFile;
        }

        if (!empty($customadminpath) && is_string($customadminpath)) {
            $this->adminDirName = trim(str_replace('/', '', $customadminpath));
            if ($this->adminDirName === '') {
                $this->adminDirName = 'admin';
            }
        }

        if (!empty($crons_dir) && is_string($crons_dir)) {
            $this->cronsDir = rtrim($crons_dir, '/');
        } else {
            $this->cronsDir = $this->whmcsRoot . '/crons';
        }
    }

    public function getAdminDirName(): string
    {
        return $this->adminDirName;
    }

    public function getCronsDir(): string
    {
        return $this->cronsDir;
    }

    public function getWhmcsRoot(): string
    {
        return $this->whmcsRoot;
    }

    /**
     * @return array{ok:bool,errors:array<int,string>,info:array<string,mixed>}
     */
    public function preflight(string $zipPath): array
    {
        $errors = [];
        $info = [
            'admin_dir' => $this->adminDirName,
            'crons_dir' => $this->cronsDir,
            'zip' => $zipPath,
        ];

        if (!class_exists('ZipArchive')) {
            $errors[] = 'zip_extension_missing';
        }
        if (!is_file($zipPath)) {
            $errors[] = 'zip_missing';
        }
        if (!is_dir($this->whmcsRoot) || !is_writable($this->whmcsRoot)) {
            $errors[] = 'whmcs_root_not_writable';
        }
        if (!is_file($this->whmcsRoot . '/configuration.php')) {
            $errors[] = 'configuration_missing';
        }
        if (!is_file($this->whmcsRoot . '/init.php')) {
            $errors[] = 'init_missing';
        }

        $free = @disk_free_space($this->whmcsRoot);
        $zipSize = is_file($zipPath) ? (int) filesize($zipPath) : 0;
        $info['free_bytes'] = $free !== false ? (int) $free : null;
        $info['zip_bytes'] = $zipSize;
        if ($free !== false && $zipSize > 0 && $free < ($zipSize * 2.5)) {
            $errors[] = 'insufficient_disk_space';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'info' => $info,
        ];
    }

    /**
     * Dry-run writability of destination directories without writing files.
     *
     * @return array{ok:bool,error:string}
     */
    public function writeDryRun(): array
    {
        $probe = $this->whmcsRoot . '/.whmcsupdater_write_probe';
        if (@file_put_contents($probe, 'ok') === false) {
            return ['ok' => false, 'error' => 'whmcs_root_not_writable'];
        }
        @unlink($probe);

        $admin = $this->whmcsRoot . '/' . $this->adminDirName;
        if (is_dir($admin) && !is_writable($admin)) {
            return ['ok' => false, 'error' => 'admin_dir_not_writable'];
        }

        if (is_dir($this->cronsDir) && !is_writable($this->cronsDir)) {
            return ['ok' => false, 'error' => 'crons_dir_not_writable'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Extract ZIP entries incrementally by index.
     *
     * @return array{ok:bool,done:bool,error:string,extracted:int,total:int,package_root:string}
     */
    public function extractChunk(string $zipPath, string $staging, int $startIndex, int $budgetSeconds, int $maxFiles = 400): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'done' => false, 'error' => 'zip_extension_missing', 'extracted' => 0, 'total' => 0, 'package_root' => ''];
        }

        if (!is_dir($staging) && !@mkdir($staging, 0750, true) && !is_dir($staging)) {
            return ['ok' => false, 'done' => false, 'error' => 'cannot_create_staging', 'extracted' => 0, 'total' => 0, 'package_root' => ''];
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            return ['ok' => false, 'done' => false, 'error' => 'zip_open_failed:' . $opened, 'extracted' => 0, 'total' => 0, 'package_root' => ''];
        }

        $total = $zip->numFiles;
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $extracted = 0;
        $index = max(0, $startIndex);

        while ($index < $total && $extracted < $maxFiles && microtime(true) < $deadline) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if ($name === '' || !$this->isSafeZipName($name)) {
                $index++;
                continue;
            }

            $target = $staging . '/' . $name;
            if (substr($name, -1) === '/') {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                    $zip->close();
                    return ['ok' => false, 'done' => false, 'error' => 'cannot_mkdir_staging:' . $name, 'extracted' => $extracted, 'total' => $total, 'package_root' => ''];
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                    $zip->close();
                    return ['ok' => false, 'done' => false, 'error' => 'cannot_mkdir_staging_parent:' . $name, 'extracted' => $extracted, 'total' => $total, 'package_root' => ''];
                }
                if (!$zip->extractTo($staging, [$name])) {
                    // Fallback: stream copy for stubborn entries.
                    $stream = $zip->getStream($name);
                    if ($stream === false) {
                        $zip->close();
                        return ['ok' => false, 'done' => false, 'error' => 'zip_extract_failed:' . $name, 'extracted' => $extracted, 'total' => $total, 'package_root' => ''];
                    }
                    $out = @fopen($target, 'wb');
                    if ($out === false) {
                        fclose($stream);
                        $zip->close();
                        return ['ok' => false, 'done' => false, 'error' => 'cannot_write_staging:' . $name, 'extracted' => $extracted, 'total' => $total, 'package_root' => ''];
                    }
                    stream_copy_to_stream($stream, $out);
                    fclose($out);
                    fclose($stream);
                }
            }

            $index++;
            $extracted++;
        }

        $zip->close();
        $done = ($index >= $total);
        $packageRoot = '';
        if ($done) {
            $root = $this->findPackageRoot($staging);
            if ($root === null) {
                return ['ok' => false, 'done' => false, 'error' => 'package_root_not_found', 'extracted' => $extracted, 'total' => $total, 'package_root' => ''];
            }
            $packageRoot = $root;
        }

        return [
            'ok' => true,
            'done' => $done,
            'error' => '',
            'extracted' => $extracted,
            'total' => $total,
            'next_index' => $index,
            'package_root' => $packageRoot,
        ];
    }

    public function isSafeZipName(string $name): bool
    {
        if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false) {
            return false;
        }
        $parts = explode('/', $name);
        foreach ($parts as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Build a JSONL manifest of package files with mapped destinations.
     *
     * @return array{ok:bool,error:string,count:int,path:string}
     */
    public function buildManifest(string $packageRoot, string $manifestPath): array
    {
        $packageRoot = rtrim($packageRoot, '/');
        if (!is_dir($packageRoot)) {
            return ['ok' => false, 'error' => 'package_root_missing', 'count' => 0, 'path' => $manifestPath];
        }

        $fh = @fopen($manifestPath, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'cannot_write_manifest', 'count' => 0, 'path' => $manifestPath];
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $abs = $item->getPathname();
            $rel = substr($abs, strlen($packageRoot) + 1);
            $skip = $this->shouldSkipRelative($rel);
            $destRel = $this->mapRelativePath($rel);
            $live = $this->resolveLivePath($destRel);
            $row = [
                'rel' => $rel,
                'dest' => $destRel,
                'live' => $live,
                'size' => (int) $item->getSize(),
                'crc' => $this->crc32File($abs),
                'skip' => $skip,
                'install' => (strpos($rel, 'install/') === 0 || $rel === 'install'),
            ];
            fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
            $count++;
        }
        fclose($fh);

        return ['ok' => true, 'error' => '', 'count' => $count, 'path' => $manifestPath];
    }

    /**
     * Backup overlapping live files. Never overwrite an existing backup entry.
     *
     * @return array{ok:bool,done:bool,error:string,backed_up:int,next_line:int}
     */
    public function backupChunk(string $manifestPath, string $backupDir, int $startLine, int $budgetSeconds, int $maxFiles = 300): array
    {
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            return ['ok' => false, 'done' => false, 'error' => 'cannot_create_backup', 'backed_up' => 0, 'next_line' => $startLine];
        }

        $lines = $this->readManifestSlice($manifestPath, $startLine, 100000);
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $backed = 0;
        $line = $startLine;
        $processed = 0;

        foreach ($lines as $entry) {
            if ($processed >= $maxFiles || microtime(true) >= $deadline) {
                break;
            }
            $line++;
            $processed++;

            if (!empty($entry['skip'])) {
                continue;
            }
            $live = (string) ($entry['live'] ?? '');
            $destRel = (string) ($entry['dest'] ?? '');
            if ($live === '' || !is_file($live)) {
                continue;
            }

            $backupTarget = $backupDir . '/' . $destRel;
            if (is_file($backupTarget)) {
                // Keep the original from the interrupted run.
                continue;
            }
            $parent = dirname($backupTarget);
            if (!is_dir($parent) && !@mkdir($parent, 0750, true) && !is_dir($parent)) {
                return ['ok' => false, 'done' => false, 'error' => 'cannot_create_backup_subdir:' . $destRel, 'backed_up' => $backed, 'next_line' => $line - 1];
            }
            if (!@copy($live, $backupTarget)) {
                return ['ok' => false, 'done' => false, 'error' => 'backup_copy_failed:' . $destRel, 'backed_up' => $backed, 'next_line' => $line - 1];
            }
            $backed++;
        }

        $done = ($line >= $this->countManifestLines($manifestPath));

        return ['ok' => true, 'done' => $done, 'error' => '', 'backed_up' => $backed, 'next_line' => $line];
    }

    /**
     * Copy package files atomically (temp + rename). install/ files when $installOnly.
     *
     * @return array{ok:bool,done:bool,error:string,copied:int,next_line:int}
     */
    public function copyChunk(string $packageRoot, string $manifestPath, int $startLine, bool $installOnly, int $budgetSeconds, int $maxFiles = 250): array
    {
        $packageRoot = rtrim($packageRoot, '/');
        $lines = $this->readManifestSlice($manifestPath, $startLine, 100000);
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $copied = 0;
        $line = $startLine;
        $processed = 0;

        foreach ($lines as $entry) {
            if ($processed >= $maxFiles || microtime(true) >= $deadline) {
                break;
            }
            $line++;
            $processed++;

            $isInstall = !empty($entry['install']);
            if ($installOnly && !$isInstall) {
                continue;
            }
            if (!$installOnly && $isInstall) {
                continue;
            }
            if (!empty($entry['skip'])) {
                // Soft-skip: create missing runtime stubs only when destination absent.
                $rel = (string) ($entry['rel'] ?? '');
                if ($this->isSoftSkipRelative($rel)) {
                    $live = (string) ($entry['live'] ?? '');
                    if ($live !== '' && !is_file($live)) {
                        $src = $packageRoot . '/' . $rel;
                        if (is_file($src)) {
                            $this->atomicCopy($src, $live);
                            $copied++;
                        }
                    }
                }
                continue;
            }

            $rel = (string) ($entry['rel'] ?? '');
            $live = (string) ($entry['live'] ?? '');
            $src = $packageRoot . '/' . $rel;
            if (!is_file($src)) {
                return ['ok' => false, 'done' => false, 'error' => 'source_missing:' . $rel, 'copied' => $copied, 'next_line' => $line - 1];
            }
            try {
                $this->atomicCopy($src, $live);
            } catch (\Throwable $e) {
                return ['ok' => false, 'done' => false, 'error' => $e->getMessage(), 'copied' => $copied, 'next_line' => $line - 1];
            }
            $copied++;
        }

        $total = $this->countManifestLines($manifestPath);
        $done = ($line >= $total);

        return ['ok' => true, 'done' => $done, 'error' => '', 'copied' => $copied, 'next_line' => $line];
    }

    /**
     * Verify live files against manifest CRC/size; retry once on mismatch.
     *
     * @return array{ok:bool,done:bool,error:string,checked:int,mismatches:array<int,string>,next_line:int}
     */
    public function verifyChunk(string $packageRoot, string $manifestPath, int $startLine, int $budgetSeconds, int $maxFiles = 300): array
    {
        $packageRoot = rtrim($packageRoot, '/');
        $lines = $this->readManifestSlice($manifestPath, $startLine, 100000);
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $checked = 0;
        $line = $startLine;
        $processed = 0;
        $mismatches = [];

        foreach ($lines as $entry) {
            if ($processed >= $maxFiles || microtime(true) >= $deadline) {
                break;
            }
            $line++;
            $processed++;

            if (!empty($entry['skip']) && !$this->isSoftSkipRelative((string) ($entry['rel'] ?? ''))) {
                continue;
            }
            if (!empty($entry['skip']) && $this->isSoftSkipRelative((string) ($entry['rel'] ?? ''))) {
                // Soft-skipped files that already existed are intentionally left alone.
                $live = (string) ($entry['live'] ?? '');
                if ($live === '' || !is_file($live)) {
                    continue;
                }
            }

            $rel = (string) ($entry['rel'] ?? '');
            $live = (string) ($entry['live'] ?? '');
            $expectedSize = (int) ($entry['size'] ?? 0);
            $expectedCrc = (string) ($entry['crc'] ?? '');
            if ($live === '' || !is_file($live)) {
                // Retry copy then recheck.
                $src = $packageRoot . '/' . $rel;
                if (is_file($src) && empty($entry['skip'])) {
                    try {
                        $this->atomicCopy($src, $live);
                    } catch (\Throwable $e) {
                        $mismatches[] = $rel . ':missing';
                        continue;
                    }
                } elseif (!empty($entry['skip'])) {
                    continue;
                } else {
                    $mismatches[] = $rel . ':missing';
                    continue;
                }
            }

            $size = (int) filesize($live);
            $crc = $this->crc32File($live);
            if (($expectedSize > 0 && $size !== $expectedSize) || ($expectedCrc !== '' && $crc !== $expectedCrc)) {
                $src = $packageRoot . '/' . $rel;
                if (is_file($src) && empty($entry['skip'])) {
                    try {
                        $this->atomicCopy($src, $live);
                        $size = (int) filesize($live);
                        $crc = $this->crc32File($live);
                    } catch (\Throwable $e) {
                        $mismatches[] = $rel . ':recopy_failed';
                        continue;
                    }
                }
                if (($expectedSize > 0 && $size !== $expectedSize) || ($expectedCrc !== '' && $crc !== $expectedCrc)) {
                    $mismatches[] = $rel;
                    continue;
                }
            }
            $checked++;
        }

        if ($mismatches !== []) {
            return [
                'ok' => false,
                'done' => false,
                'error' => 'verify_mismatch:' . implode(',', array_slice($mismatches, 0, 20)),
                'checked' => $checked,
                'mismatches' => $mismatches,
                'next_line' => $line - 1,
            ];
        }

        $done = ($line >= $this->countManifestLines($manifestPath));

        return ['ok' => true, 'done' => $done, 'error' => '', 'checked' => $checked, 'mismatches' => [], 'next_line' => $line];
    }

    public function atomicCopy(string $source, string $dest): void
    {
        $parent = dirname($dest);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new \RuntimeException('cannot_mkdir_parent:' . $dest);
        }

        $tmp = $dest . '.whmcsupdater.tmp.' . getmypid();
        if (!@copy($source, $tmp)) {
            @unlink($tmp);
            throw new \RuntimeException('copy_failed:' . $dest);
        }

        // Preserve mode roughly.
        $mode = @fileperms($source);
        if ($mode !== false) {
            @chmod($tmp, $mode & 0777);
        }

        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot_finalize_copy:' . $dest);
        }
    }

    public function findPackageRoot(string $staging): ?string
    {
        $staging = rtrim($staging, '/');
        if ($this->looksLikeWhmcsRoot($staging)) {
            return $staging;
        }

        $entries = @scandir($staging) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $staging . '/' . $entry;
            if (is_dir($path) && $this->looksLikeWhmcsRoot($path)) {
                return $path;
            }
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $staging . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $sub = @scandir($path) ?: [];
            foreach ($sub as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }
                $childPath = $path . '/' . $child;
                if (is_dir($childPath) && $this->looksLikeWhmcsRoot($childPath)) {
                    return $childPath;
                }
            }
        }

        return null;
    }

    private function looksLikeWhmcsRoot(string $path): bool
    {
        return is_file($path . '/init.php') && is_dir($path . '/install');
    }

    public function shouldSkipRelative(string $rel): bool
    {
        $rel = ltrim($rel, '/');
        if ($rel === 'configuration.php') {
            return true;
        }
        // Preserve custom / nulled license files across upgrades.
        if ($rel === 'vendor/whmcs/whmcs-foundation/lib/License.php') {
            return true;
        }

        return $this->isSoftSkipRelative($rel);
    }

    public function isSoftSkipRelative(string $rel): bool
    {
        $rel = ltrim($rel, '/');
        $skipPrefixes = [
            'attachments/',
            'downloads/',
            'templates_c/',
        ];
        foreach ($skipPrefixes as $prefix) {
            if (strpos($rel, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    public function mapRelativePath(string $rel): string
    {
        $rel = ltrim($rel, '/');

        if ($rel === 'admin' || strpos($rel, 'admin/') === 0) {
            if ($this->adminDirName !== 'admin') {
                return $this->adminDirName . substr($rel, strlen('admin'));
            }
        }

        if ($rel === 'crons' || strpos($rel, 'crons/') === 0) {
            return '__crons__' . substr($rel, strlen('crons'));
        }

        return $rel;
    }

    public function resolveLivePath(string $destRel): string
    {
        if (strpos($destRel, '__crons__') === 0) {
            $suffix = substr($destRel, strlen('__crons__'));
            return $this->cronsDir . $suffix;
        }

        return $this->whmcsRoot . '/' . $destRel;
    }

    /**
     * Enable maintenance mode once; never overwrite a saved previous value.
     */
    public function ensureMaintenanceOn(): void
    {
        $stateFile = $this->workDir . '/maintenance_prev.json';
        if (!is_file($stateFile)) {
            $prev = $this->readConfigSetting('MaintenanceMode');
            file_put_contents($stateFile, json_encode([
                'previous' => $prev,
                'enabled_at' => time(),
            ]));
            $this->writeConfigSetting('MaintenanceMode', 'on');
            $this->log('Maintenance mode enabled (previous=' . ($prev ?? 'null') . ').');
            return;
        }
        // Already recorded — just ensure current value is on.
        $this->writeConfigSetting('MaintenanceMode', 'on');
    }

    public function restorePreviousMaintenanceMode(): void
    {
        $stateFile = $this->workDir . '/maintenance_prev.json';
        $previous = 'off';
        if (is_file($stateFile)) {
            $data = json_decode((string) file_get_contents($stateFile), true);
            if (is_array($data) && array_key_exists('previous', $data)) {
                $previous = (string) $data['previous'];
            }
            @unlink($stateFile);
        }
        $this->writeConfigSetting('MaintenanceMode', $previous === 'on' ? 'on' : 'off');
        $this->log('Maintenance mode restored to: ' . $previous);
    }

    /**
     * Restore files from a previous backup directory.
     */
    public function restoreBackup(string $backupDir): bool
    {
        $backupDir = rtrim($backupDir, '/');
        if (!is_dir($backupDir)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $rel = substr($item->getPathname(), strlen($backupDir) + 1);
            $target = $this->whmcsRoot . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    @mkdir($parent, 0755, true);
                }
                $this->atomicCopy($item->getPathname(), $target);
            }
        }

        return true;
    }

    /**
     * Start or poll the WHMCS CLI installer upgrade.
     *
     * @return array{ok:bool,done:bool,error:string,output:string,exit_code:int,method:string,pid:?int}
     */
    public function runInstallerUpgrade(?string $phpBinary = null, ?string $pidFile = null, ?string $logFile = null): array
    {
        $installer = $this->whmcsRoot . '/install/bin/installer.php';
        if (!is_file($installer)) {
            return [
                'ok' => false,
                'done' => true,
                'output' => '',
                'error' => 'installer_missing',
                'exit_code' => -1,
                'method' => 'none',
                'pid' => null,
            ];
        }

        $pidFile = $pidFile ?: ($this->workDir . '/installer.pid');
        $logFile = $logFile ?: ($this->workDir . '/installer.log');

        // Poll an already-running installer.
        if (is_file($pidFile)) {
            $meta = json_decode((string) file_get_contents($pidFile), true);
            $pid = is_array($meta) ? (int) ($meta['pid'] ?? 0) : 0;
            if ($pid > 0 && $this->pidAlive($pid)) {
                return [
                    'ok' => true,
                    'done' => false,
                    'output' => $this->tailFile($logFile, 4000),
                    'error' => '',
                    'exit_code' => -1,
                    'method' => 'background',
                    'pid' => $pid,
                ];
            }

            $exitCode = is_array($meta) ? (int) ($meta['exit_code'] ?? -1) : -1;
            $output = $this->tailFile($logFile, 8000);
            $ok = ($exitCode === 0)
                || (stripos($output, 'Program Completed') !== false)
                || (stripos($output, 'Upgrade completed') !== false);
            @unlink($pidFile);

            return [
                'ok' => $ok,
                'done' => true,
                'output' => $output,
                'error' => $ok ? '' : 'installer_failed',
                'exit_code' => $exitCode,
                'method' => 'background',
                'pid' => null,
            ];
        }

        if (!function_exists('proc_open')) {
            return [
                'ok' => false,
                'done' => true,
                'output' => '',
                'error' => 'proc_open_disabled',
                'exit_code' => -1,
                'method' => 'manual',
                'pid' => null,
            ];
        }

        $php = $phpBinary ?: $this->detectPhpBinary();
        $validated = $this->validatePhpBinary($php);
        if (!$validated['ok']) {
            return [
                'ok' => false,
                'done' => true,
                'output' => $validated['output'],
                'error' => $validated['error'],
                'exit_code' => -1,
                'method' => 'manual',
                'pid' => null,
            ];
        }
        $php = $validated['path'];

        $cmd = [
            $php,
            '-f',
            $installer,
            '--',
            '-u',
            '-n',
        ];

        $this->log('Starting installer: ' . implode(' ', array_map('strval', $cmd)));
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] CMD " . implode(' ', $cmd) . "\n", FILE_APPEND);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $process = @proc_open($cmd, $descriptors, $pipes, $this->whmcsRoot . '/install');
        if (!is_resource($process)) {
            $shellCmd = escapeshellarg($php)
                . ' -f ' . escapeshellarg($installer)
                . ' -- -u -n';
            $process = @proc_open($shellCmd, $descriptors, $pipes, $this->whmcsRoot . '/install');
        }

        if (!is_resource($process)) {
            return [
                'ok' => false,
                'done' => true,
                'output' => '',
                'error' => 'proc_open_failed',
                'exit_code' => -1,
                'method' => 'proc_open',
                'pid' => null,
            ];
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        file_put_contents($pidFile, json_encode([
            'pid' => $pid,
            'started_at' => time(),
            'exit_code' => null,
        ]));

        // Detach: don't wait in this tick. Next tick polls the pid file.
        // stdout/stderr were redirected to files so closing stdin is enough.
        if (!empty($status['running'])) {
            // Register shutdown to avoid zombie without blocking this request.
            register_shutdown_function(static function () use ($process, $pidFile) {
                $st = @proc_get_status($process);
                if (is_array($st) && empty($st['running'])) {
                    $code = (int) ($st['exitcode'] ?? -1);
                    if ($code === -1) {
                        $code = @proc_close($process);
                    } else {
                        @proc_close($process);
                    }
                    if (is_file($pidFile)) {
                        $meta = json_decode((string) @file_get_contents($pidFile), true);
                        if (!is_array($meta)) {
                            $meta = [];
                        }
                        $meta['exit_code'] = $code;
                        $meta['finished_at'] = time();
                        @file_put_contents($pidFile, json_encode($meta));
                    }
                }
            });

            return [
                'ok' => true,
                'done' => false,
                'output' => '',
                'error' => '',
                'exit_code' => -1,
                'method' => 'background',
                'pid' => $pid,
            ];
        }

        // Finished immediately.
        $exitCode = (int) ($status['exitcode'] ?? -1);
        if ($exitCode === -1) {
            $exitCode = proc_close($process);
        } else {
            proc_close($process);
        }
        $output = $this->tailFile($logFile, 8000);
        $ok = ($exitCode === 0)
            || (stripos($output, 'Program Completed') !== false)
            || (stripos($output, 'Upgrade completed') !== false);
        @unlink($pidFile);

        return [
            'ok' => $ok,
            'done' => true,
            'output' => $output,
            'error' => $ok ? '' : 'installer_failed',
            'exit_code' => $exitCode,
            'method' => 'background',
            'pid' => null,
        ];
    }

    /**
     * @return array{ok:bool,error:string,path:string,output:string,php_version:string,ioncube_version:string}
     */
    public function validatePhpBinary(string $php): array
    {
        $empty = [
            'ok' => false,
            'error' => 'php_binary_missing',
            'path' => $php,
            'output' => '',
            'php_version' => '',
            'ioncube_version' => '',
        ];

        if ($php === '' || !is_file($php)) {
            // Allow bare "php" from PATH (no directory separators).
            if ($php === '' || strpos($php, '/') !== false) {
                return $empty;
            }
        }

        if (!function_exists('proc_open')) {
            $empty['error'] = 'proc_open_disabled';
            return $empty;
        }

        $cmd = escapeshellarg($php) . ' -r ' . escapeshellarg(
            '$ion="";'
            . 'if(function_exists("ioncube_loader_version")){$ion=(string)ioncube_loader_version();}'
            . 'elseif(function_exists("ioncube_loader_iversion")){$iv=(int)ioncube_loader_iversion();$ion=sprintf("%d.%d.%d",intdiv($iv,10000),intdiv($iv%10000,100),$iv%100);}'
            . 'echo PHP_SAPI,"|",PHP_VERSION,"|",(extension_loaded("ionCube Loader")||extension_loaded("ioncube_loader")||$ion!==""?"1":"0"),"|",$ion;'
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            $empty['error'] = 'php_binary_unusable';
            return $empty;
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) ?: '';
        $err = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $parts = explode('|', trim($out));
        $sapi = $parts[0] ?? '';
        $ver = $parts[1] ?? '';
        $ion = ($parts[2] ?? '0') === '1';
        $ionVer = $parts[3] ?? '';
        if ($sapi !== 'cli') {
            return [
                'ok' => false,
                'error' => 'php_not_cli_sapi:' . $sapi,
                'path' => $php,
                'output' => $out . $err,
                'php_version' => $ver,
                'ioncube_version' => $ionVer,
            ];
        }
        $want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $verMm = preg_match('/^(\d+\.\d+)/', $ver, $m) ? $m[1] : $ver;
        if ($verMm !== '' && $verMm !== $want) {
            // Soft warning — still allow if major.minor differs but log it.
            $this->log('PHP CLI version ' . $ver . ' differs from web ' . $want);
        }
        if (!$ion) {
            return [
                'ok' => false,
                'error' => 'php_cli_missing_ioncube',
                'path' => $php,
                'output' => $out . $err,
                'php_version' => $ver,
                'ioncube_version' => '',
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'path' => $php,
            'output' => $out,
            'php_version' => $ver,
            'ioncube_version' => $ionVer,
        ];
    }

    public function detectPhpBinary(?string $configured = null): string
    {
        if ($configured !== null && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        if (defined('PHP_BINARY') && PHP_BINARY && is_file(PHP_BINARY)) {
            $bin = PHP_BINARY;
            if (stripos($bin, 'php-fpm') === false && stripos($bin, 'php-cgi') === false) {
                return $bin;
            }
        }

        if (defined('PHP_BINDIR')) {
            $candidate = rtrim(PHP_BINDIR, '/') . '/php';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $candidates = ['php', 'php8.3', 'php8.2', 'php83', 'php82'];
        foreach ($candidates as $candidate) {
            $which = $this->which($candidate);
            if ($which !== null) {
                return $which;
            }
        }

        return 'php';
    }

    private function which(string $binary): ?string
    {
        $output = [];
        $code = 0;
        @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0])) {
            $path = trim($output[0]);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function readDbVersion(): ?string
    {
        $value = $this->readConfigSetting('Version');

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * @return array{ok:bool,error:string}
     */
    public function finalize(bool $restoreMaintenance = true): array
    {
        $installDir = $this->whmcsRoot . '/install';
        if (is_dir($installDir)) {
            $this->log('Removing install directory…');
            if (!$this->rrmdir($installDir)) {
                return ['ok' => false, 'error' => 'cannot_remove_install'];
            }
        }

        $relocating = $this->whmcsRoot . '/.whmcs-relocating';
        if (is_file($relocating)) {
            @unlink($relocating);
            $this->log('Removed .whmcs-relocating marker.');
        }

        $templatesC = $this->whmcsRoot . '/templates_c';
        if (is_dir($templatesC)) {
            $this->clearDirectoryContents($templatesC);
            $this->log('Cleared templates_c.');
        }

        if ($restoreMaintenance) {
            $this->restorePreviousMaintenanceMode();
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $this->log('Finalize complete.');

        return ['ok' => true, 'error' => ''];
    }

    public function clearDirectoryContents(string $dir): void
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'index.php') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    public function rrmdir(string $dir): bool
    {
        $dir = rtrim($dir, '/');
        if ($dir === '' || $dir === '/' || !is_dir($dir)) {
            return !is_dir($dir);
        }

        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                if (!$this->rrmdir($path)) {
                    return false;
                }
            } else {
                if (!@unlink($path)) {
                    return false;
                }
            }
        }

        return @rmdir($dir);
    }

    public function crc32File(string $path): string
    {
        $hash = hash_file('crc32b', $path);
        return is_string($hash) ? $hash : '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function readManifestSlice(string $path, int $startLine, int $limit): array
    {
        if (!is_file($path)) {
            return [];
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $lineNo = 0;
        $out = [];
        while (($raw = fgets($fh)) !== false) {
            if ($lineNo < $startLine) {
                $lineNo++;
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
            $lineNo++;
        }
        fclose($fh);

        return $out;
    }

    public function countManifestLines(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        $count = 0;
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return 0;
        }
        while (fgets($fh) !== false) {
            $count++;
        }
        fclose($fh);

        return $count;
    }

    private function tailFile(string $path, int $maxBytes): string
    {
        if (!is_file($path)) {
            return '';
        }
        $size = (int) filesize($path);
        if ($size <= $maxBytes) {
            return (string) file_get_contents($path);
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        fseek($fh, -$maxBytes, SEEK_END);
        $data = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $data;
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }

    /**
     * @return string|null
     */
    public function readConfigSetting(string $setting)
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                $row = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->where('setting', $setting)
                    ->first();
                return $row ? (string) $row->value : null;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        $pdo = $this->pdoFromConfig();
        if (!$pdo) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT value FROM tblconfiguration WHERE setting = ? LIMIT 1');
        $stmt->execute([$setting]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function writeConfigSetting(string $setting, string $value): void
    {
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                $exists = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->where('setting', $setting)
                    ->exists();
                if ($exists) {
                    \WHMCS\Database\Capsule::table('tblconfiguration')
                        ->where('setting', $setting)
                        ->update(['value' => $value]);
                } else {
                    \WHMCS\Database\Capsule::table('tblconfiguration')->insert([
                        'setting' => $setting,
                        'value' => $value,
                    ]);
                }
                return;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        $pdo = $this->pdoFromConfig();
        if (!$pdo) {
            return;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tblconfiguration WHERE setting = ?');
        $stmt->execute([$setting]);
        if ((int) $stmt->fetchColumn() > 0) {
            $upd = $pdo->prepare('UPDATE tblconfiguration SET value = ? WHERE setting = ?');
            $upd->execute([$value, $setting]);
        } else {
            $ins = $pdo->prepare('INSERT INTO tblconfiguration (setting, value) VALUES (?, ?)');
            $ins->execute([$setting, $value]);
        }
    }

    /**
     * @return \PDO|null
     */
    public function pdoFromConfig()
    {
        if ($this->pdoTried) {
            return $this->pdo;
        }
        $this->pdoTried = true;

        $configFile = $this->whmcsRoot . '/configuration.php';
        if (!is_file($configFile)) {
            return null;
        }

        $db_host = 'localhost';
        $db_username = '';
        $db_password = '';
        $db_name = '';
        $db_port = '';
        include $configFile;

        try {
            $dsn = 'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4';
            if (!empty($db_port)) {
                $dsn .= ';port=' . $db_port;
            }
            $this->pdo = new \PDO($dsn, (string) $db_username, (string) $db_password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable $e) {
            $this->pdo = null;
        }

        return $this->pdo;
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }
}
