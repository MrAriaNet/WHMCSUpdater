<?php
/**
 * Persistence layer for proxy pool records.
 */

namespace WHMCSUpdater;

use WHMCS\Database\Capsule;

class ProxyRepository
{
    public const SCHEMES = ['http', 'https', 'socks5'];

    /**
     * @return array<int, object>
     */
    public function all(): array
    {
        return Capsule::table('mod_whmcsupdater_proxies')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return array<int, object>
     */
    public function enabled(): array
    {
        return Capsule::table('mod_whmcsupdater_proxies')
            ->where('enabled', 1)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function find(int $id): ?object
    {
        $row = Capsule::table('mod_whmcsupdater_proxies')->where('id', $id)->first();

        return $row ?: null;
    }

    /**
     * Insert a proxy from validated field values.
     *
     * @param array $data
     * @return int Inserted ID
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table('mod_whmcsupdater_proxies')->insertGetId([
            'label' => $data['label'] ?? '',
            'scheme' => $data['scheme'],
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'username' => $data['username'] !== '' ? $data['username'] : null,
            'password' => $this->encryptPassword($data['password'] ?? ''),
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'priority' => (int) ($data['priority'] ?? 100),
            'last_status' => 'unknown',
            'last_latency_ms' => null,
            'fail_count' => 0,
            'last_checked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Update an existing proxy. Empty password leaves the stored value unchanged.
     *
     * @param array $data
     */
    public function update(int $id, array $data): bool
    {
        $payload = [
            'label' => $data['label'] ?? '',
            'scheme' => $data['scheme'],
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'username' => $data['username'] !== '' ? $data['username'] : null,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'priority' => (int) ($data['priority'] ?? 100),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('password', $data) && $data['password'] !== '') {
            $payload['password'] = $this->encryptPassword($data['password']);
        }

        return Capsule::table('mod_whmcsupdater_proxies')->where('id', $id)->update($payload) !== false;
    }

    public function delete(int $id): bool
    {
        return Capsule::table('mod_whmcsupdater_proxies')->where('id', $id)->delete() > 0;
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        return Capsule::table('mod_whmcsupdater_proxies')->where('id', $id)->update([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) !== false;
    }

    /**
     * Persist health-check results for a proxy.
     */
    public function recordCheck(int $id, bool $ok, ?int $latencyMs, string $message = ''): void
    {
        $update = [
            'last_status' => $ok ? 'healthy' : 'failed',
            'last_latency_ms' => $ok ? $latencyMs : null,
            'last_checked_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($ok) {
            $update['fail_count'] = 0;
        } else {
            Capsule::table('mod_whmcsupdater_proxies')->where('id', $id)->increment('fail_count');
        }

        Capsule::table('mod_whmcsupdater_proxies')->where('id', $id)->update($update);

        // Suppress unused-variable notice when callers pass diagnostic text for logging elsewhere.
        unset($message);
    }

    /**
     * Write a selection / apply event to the addon log.
     */
    public function log(?int $proxyId, ?string $proxyLabel, string $context, string $result, string $message = ''): void
    {
        Capsule::table('mod_whmcsupdater_log')->insert([
            'proxy_id' => $proxyId,
            'proxy_label' => $proxyLabel,
            'context' => $context,
            'result' => $result,
            'message' => $message !== '' ? $message : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param int $limit
     * @return array<int, object>
     */
    public function recentLogs(int $limit = 50): array
    {
        return Capsule::table('mod_whmcsupdater_log')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Read a key from the lightweight state table (cache / round-robin cursor).
     */
    public function getState(string $key, $default = null)
    {
        $row = Capsule::table('mod_whmcsupdater_state')->where('state_key', $key)->first();
        if (!$row) {
            return $default;
        }

        $decoded = json_decode((string) $row->state_value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row->state_value;
    }

    /**
     * Persist a key in the state table.
     *
     * @param mixed $value
     */
    public function setState(string $key, $value): void
    {
        $encoded = is_string($value) ? $value : json_encode($value);
        $now = date('Y-m-d H:i:s');

        $exists = Capsule::table('mod_whmcsupdater_state')->where('state_key', $key)->exists();
        if ($exists) {
            Capsule::table('mod_whmcsupdater_state')->where('state_key', $key)->update([
                'state_value' => $encoded,
                'updated_at' => $now,
            ]);
        } else {
            Capsule::table('mod_whmcsupdater_state')->insert([
                'state_key' => $key,
                'state_value' => $encoded,
                'updated_at' => $now,
            ]);
        }
    }

    public function clearState(string $key): void
    {
        Capsule::table('mod_whmcsupdater_state')->where('state_key', $key)->delete();
    }

    /**
     * Build a proxy URL suitable for CURLOPT_PROXY / environment variables.
     *
     * @param object $proxy Database row
     * @param bool $socks5RemoteDns Use socks5h when scheme is socks5
     */
    public function buildUrl(object $proxy, bool $socks5RemoteDns = true): string
    {
        $scheme = $proxy->scheme;
        if ($scheme === 'socks5' && $socks5RemoteDns) {
            $scheme = 'socks5h';
        }

        $auth = '';
        $username = (string) ($proxy->username ?? '');
        $password = $this->decryptPassword($proxy->password ?? null);
        if ($username !== '') {
            $auth = rawurlencode($username);
            if ($password !== '') {
                $auth .= ':' . rawurlencode($password);
            }
            $auth .= '@';
        }

        return sprintf('%s://%s%s:%d', $scheme, $auth, $proxy->host, (int) $proxy->port);
    }

    /**
     * Human-readable label without credentials.
     */
    public function displayLabel(object $proxy): string
    {
        $base = sprintf('%s://%s:%d', $proxy->scheme, $proxy->host, (int) $proxy->port);
        $label = trim((string) ($proxy->label ?? ''));

        return $label !== '' ? sprintf('%s (%s)', $label, $base) : $base;
    }

    /**
     * Parse a proxy URL into validated fields.
     *
     * Accepts: scheme://[user[:pass]@]host:port
     *
     * @return array{ok:bool,error?:string,data?:array}
     */
    public function parseUrl(string $url, string $defaultLabel = ''): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'error' => 'empty_url'];
        }

        // Ensure a scheme is present so parse_url behaves consistently.
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return ['ok' => false, 'error' => 'missing_scheme'];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host']) || empty($parts['port'])) {
            return ['ok' => false, 'error' => 'invalid_url'];
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'socks5h') {
            $scheme = 'socks5';
        }

        if (!in_array($scheme, self::SCHEMES, true)) {
            return ['ok' => false, 'error' => 'unsupported_scheme'];
        }

        $port = (int) $parts['port'];
        if ($port < 1 || $port > 65535) {
            return ['ok' => false, 'error' => 'invalid_port'];
        }

        return [
            'ok' => true,
            'data' => [
                'label' => $defaultLabel,
                'scheme' => $scheme,
                'host' => $parts['host'],
                'port' => $port,
                'username' => isset($parts['user']) ? rawurldecode($parts['user']) : '',
                'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
                'enabled' => true,
                'priority' => 100,
            ],
        ];
    }

    /**
     * Validate form field values for create/update.
     *
     * @param array $input
     * @return array{ok:bool,error?:string,data?:array}
     */
    public function validateFields(array $input): array
    {
        $scheme = strtolower(trim((string) ($input['scheme'] ?? '')));
        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 0);

        if (!in_array($scheme, self::SCHEMES, true)) {
            return ['ok' => false, 'error' => 'unsupported_scheme'];
        }
        if ($host === '') {
            return ['ok' => false, 'error' => 'missing_host'];
        }
        if ($port < 1 || $port > 65535) {
            return ['ok' => false, 'error' => 'invalid_port'];
        }

        return [
            'ok' => true,
            'data' => [
                'label' => trim((string) ($input['label'] ?? '')),
                'scheme' => $scheme,
                'host' => $host,
                'port' => $port,
                'username' => trim((string) ($input['username'] ?? '')),
                'password' => (string) ($input['password'] ?? ''),
                'enabled' => !empty($input['enabled']),
                'priority' => max(0, (int) ($input['priority'] ?? 100)),
            ],
        ];
    }

    private function encryptPassword(string $password): ?string
    {
        if ($password === '') {
            return null;
        }

        if (function_exists('encrypt')) {
            return encrypt($password);
        }

        // Fallback for environments without WHMCS helpers (e.g. CLI smoke tests).
        return base64_encode($password);
    }

    public function decryptPassword(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        if (function_exists('decrypt')) {
            $plain = decrypt($stored);
            return is_string($plain) ? $plain : '';
        }

        $decoded = base64_decode($stored, true);

        return $decoded !== false ? $decoded : '';
    }
}
