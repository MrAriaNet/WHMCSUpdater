<?php
/**
 * Atomic state, lock, token, and log helpers for the staged updater.
 *
 * Works without bootstrapping WHMCS so interrupted upgrades can continue
 * while the install/ directory is present (Down for Maintenance Err 2).
 */

namespace WHMCSUpdater;

class StateStore
{
    public const STATE_FILE = 'state.json';
    public const LOCK_FILE = 'update.lock';
    public const LOG_FILE = 'manual_update.log';
    public const TOKEN_FILE = 'status_token';

    /** Stale lock age (seconds) when owner process cannot be checked. */
    public const LOCK_STALE_SECONDS = 120;

    /** @var string */
    private $workDir;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(string $workDir)
    {
        $this->workDir = rtrim($workDir, '/');
        $this->ensureWorkDir();
    }

    public function getWorkDir(): string
    {
        return $this->workDir;
    }

    private function ensureWorkDir(): void
    {
        if (!is_dir($this->workDir) && !@mkdir($this->workDir, 0750, true) && !is_dir($this->workDir)) {
            throw new \RuntimeException('Cannot create work directory: ' . $this->workDir);
        }

        $htaccess = $this->workDir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        $index = $this->workDir . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getState(): array
    {
        $path = $this->workDir . '/' . self::STATE_FILE;
        if (!is_file($path)) {
            return [
                'phase' => 'idle',
                'status' => 'idle',
                'message' => '',
                'error' => '',
                'bytes' => 0,
                'total' => 0,
                'percent' => 0,
            ];
        }

        $raw = (string) @file_get_contents($path);
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [
            'phase' => 'idle',
            'status' => 'idle',
            'message' => '',
            'error' => 'state_corrupt',
        ];
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function mergeState(array $patch): array
    {
        $state = $this->getState();
        foreach ($patch as $key => $value) {
            $state[$key] = $value;
        }
        $state['updated_at'] = time();
        $this->writeState($state);

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    public function writeState(array $state): void
    {
        $path = $this->workDir . '/' . self::STATE_FILE;
        $tmp = $path . '.tmp.' . getmypid();
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('cannot_write_state');
        }
        if (!@rename($tmp, $path)) {
            @copy($tmp, $path);
            @unlink($tmp);
        }
    }

    public function clearState(): void
    {
        @unlink($this->workDir . '/' . self::STATE_FILE);
        @unlink($this->workDir . '/' . self::TOKEN_FILE);
        @unlink($this->workDir . '/' . self::LOCK_FILE);
    }

    public function issueToken(): string
    {
        $token = bin2hex(random_bytes(24));
        @file_put_contents($this->workDir . '/' . self::TOKEN_FILE, $token, LOCK_EX);
        $this->mergeState(['status_token' => $token]);

        return $token;
    }

    public function clearToken(): void
    {
        @unlink($this->workDir . '/' . self::TOKEN_FILE);
        $state = $this->getState();
        if (isset($state['status_token'])) {
            unset($state['status_token']);
            $this->writeState($state);
        }
    }

    public function validateToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $expected = '';
        $file = $this->workDir . '/' . self::TOKEN_FILE;
        if (is_file($file)) {
            $expected = trim((string) file_get_contents($file));
        }
        if ($expected === '') {
            $state = $this->getState();
            $expected = (string) ($state['status_token'] ?? '');
        }

        return $expected !== '' && hash_equals($expected, $token);
    }

    public function acquireLock(int $staleSeconds = self::LOCK_STALE_SECONDS): bool
    {
        if ($this->lockHandle !== null) {
            return true;
        }

        $path = $this->workDir . '/' . self::LOCK_FILE;
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            // Another process holds the lock — check staleness via mtime/pid.
            $meta = $this->readLockMeta($handle);
            $age = time() - (int) ($meta['updated_at'] ?? filemtime($path) ?: time());
            $pid = (int) ($meta['pid'] ?? 0);
            if ($age < $staleSeconds && $pid > 0 && $this->pidIsAlive($pid)) {
                fclose($handle);
                return false;
            }
            // Stale lock: try to take over.
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                return false;
            }
        }

        $payload = json_encode([
            'pid' => getmypid(),
            'updated_at' => time(),
            'sapi' => PHP_SAPI,
        ]);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $payload !== false ? $payload : (string) getmypid());
        fflush($handle);

        $this->lockHandle = $handle;

        return true;
    }

    public function touchLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }
        $payload = json_encode([
            'pid' => getmypid(),
            'updated_at' => time(),
            'sapi' => PHP_SAPI,
        ]);
        ftruncate($this->lockHandle, 0);
        rewind($this->lockHandle);
        fwrite($this->lockHandle, $payload !== false ? $payload : (string) getmypid());
        fflush($this->lockHandle);
    }

    public function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
        @unlink($this->workDir . '/' . self::LOCK_FILE);
    }

    public function isLockHeld(): bool
    {
        $path = $this->workDir . '/' . self::LOCK_FILE;
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        $got = flock($handle, LOCK_EX | LOCK_NB);
        if ($got) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }
        fclose($handle);

        $meta = $this->readLockFile($path);
        $age = time() - (int) ($meta['updated_at'] ?? filemtime($path) ?: 0);
        $pid = (int) ($meta['pid'] ?? 0);
        if ($age >= self::LOCK_STALE_SECONDS) {
            return false;
        }
        if ($pid > 0 && !$this->pidIsAlive($pid) && $age > 15) {
            return false;
        }

        return true;
    }

    /**
     * True when state says running/applying but no live lock owner exists.
     */
    public function looksInterrupted(): bool
    {
        $state = $this->getState();
        $status = (string) ($state['status'] ?? 'idle');
        if (!in_array($status, ['running', 'applying'], true)) {
            return false;
        }

        return !$this->isLockHeld();
    }

    public function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        @file_put_contents($this->workDir . '/' . self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $line);
        }
    }

    /**
     * Store a path relative to the work directory when possible.
     */
    public function relativizePath(string $absolute): string
    {
        $prefix = $this->workDir . '/';
        if (strpos($absolute, $prefix) === 0) {
            return substr($absolute, strlen($prefix));
        }

        return $absolute;
    }

    public function resolvePath(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (strpos($stored, '/') === 0) {
            return $stored;
        }

        return $this->workDir . '/' . ltrim($stored, '/');
    }

    /**
     * @param resource $handle
     * @return array<string,mixed>
     */
    private function readLockMeta($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
        if (ctype_digit(trim($raw))) {
            return ['pid' => (int) trim($raw), 'updated_at' => time()];
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function readLockFile(string $path): array
    {
        $raw = (string) @file_get_contents($path);
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
        if (ctype_digit(trim($raw))) {
            return ['pid' => (int) trim($raw), 'updated_at' => @filemtime($path) ?: 0];
        }

        return ['updated_at' => @filemtime($path) ?: 0];
    }

    private function pidIsAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        // Without posix we cannot prove liveness; treat unknown as alive only
        // for a short window via mtime checks at the caller.
        return true;
    }
}
