<?php
/**
 * Fetch and parse WHMCS release metadata from packages.json.
 */

namespace WHMCSUpdater;

class ReleaseCatalog
{
    public const PACKAGES_URL = 'https://releases.whmcs.com/v2/packages.json';

    /** @var string */
    private $packagesUrl;

    /** @var int */
    private $timeout;

    public function __construct(string $packagesUrl = self::PACKAGES_URL, int $timeout = 30)
    {
        $this->packagesUrl = $packagesUrl !== '' ? $packagesUrl : self::PACKAGES_URL;
        $this->timeout = max(5, $timeout);
    }

    /**
     * Download packages.json optionally through a proxy.
     *
     * @param object|null $proxy
     * @param ProxyRepository|null $repo
     * @param bool $socks5RemoteDns
     * @return array{ok:bool,body:string,error:string,http_code:int}
     */
    public function fetchRaw(?object $proxy = null, ?ProxyRepository $repo = null, bool $socks5RemoteDns = true): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'body' => '', 'error' => 'curl_extension_missing', 'http_code' => 0];
        }

        $ch = curl_init($this->packagesUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'WHMCSUpdater/1.0',
        ]);

        if ($proxy && $repo) {
            ProxyCurl::applyToHandle($ch, $proxy, $repo, $socks5RemoteDns);
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'ok' => false,
                'body' => '',
                'error' => sprintf('curl_%d: %s', $errno, $error),
                'http_code' => $httpCode,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 400 || !is_string($body) || $body === '') {
            return [
                'ok' => false,
                'body' => '',
                'error' => sprintf('unexpected_http_%d', $httpCode),
                'http_code' => $httpCode,
            ];
        }

        return ['ok' => true, 'body' => $body, 'error' => '', 'http_code' => $httpCode];
    }

    /**
     * Parse packages.json into a list of release metadata arrays.
     *
     * @return array{ok:bool,releases:array<int,array>,error:string}
     */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['ok' => false, 'releases' => [], 'error' => 'invalid_json'];
        }

        $packages = $data['packages']['whmcs/whmcs'] ?? null;
        if (!is_array($packages) || $packages === []) {
            return ['ok' => false, 'releases' => [], 'error' => 'missing_whmcs_package'];
        }

        $releases = [];
        foreach ($packages as $key => $pkg) {
            if (!is_array($pkg)) {
                continue;
            }

            $normalized = $this->normalizePackage($key, $pkg);
            if ($normalized !== null) {
                $releases[] = $normalized;
            }
        }

        if ($releases === []) {
            return ['ok' => false, 'releases' => [], 'error' => 'no_releases'];
        }

        usort($releases, static function (array $a, array $b) {
            return version_compare($b['version'], $a['version']);
        });

        return ['ok' => true, 'releases' => $releases, 'error' => ''];
    }

    /**
     * Choose the newest suitable release newer than the installed version.
     *
     * When a newer release exists but is skipped for PHP/ionCube reasons,
     * it is returned in `blocked` so the UI can explain the upgrade path
     * (for example: stay on 8.13.x until the server is on PHP 8.2+).
     *
     * @param array<int,array> $releases
     * @param string $installedVersion
     * @param string $channel stable|beta|rc|any
     * @param string|null $exactVersion
     * @param string|null $phpVersion Injected PHP version (defaults to PHP_VERSION)
     * @param string|null $ionCubeVersion Injected ionCube version (defaults to detected)
     * @return array{ok:bool,release:?array,error:string,blocked:?array}
     */
    public function pickTarget(
        array $releases,
        string $installedVersion,
        string $channel = 'stable',
        ?string $exactVersion = null,
        ?string $phpVersion = null,
        ?string $ionCubeVersion = null
    ): array {
        $phpVersion = $phpVersion !== null && $phpVersion !== '' ? $phpVersion : PHP_VERSION;
        $ionCubeVersion = $ionCubeVersion !== null ? $ionCubeVersion : $this->ionCubeVersion();

        if ($exactVersion !== null && $exactVersion !== '') {
            foreach ($releases as $release) {
                if (
                    $release['version'] === $exactVersion
                    || $release['full_version'] === $exactVersion
                    || $release['key'] === $exactVersion
                ) {
                    $compat = $this->checkCompatibility($release, $phpVersion, $ionCubeVersion);
                    if (!$compat['ok']) {
                        $blocked = $this->buildBlocked($release, $compat, $phpVersion, $ionCubeVersion);
                        return [
                            'ok' => false,
                            'release' => $release,
                            'error' => $compat['error'],
                            'blocked' => $blocked,
                        ];
                    }
                    return ['ok' => true, 'release' => $release, 'error' => '', 'blocked' => null];
                }
            }

            return ['ok' => false, 'release' => null, 'error' => 'version_not_found', 'blocked' => null];
        }

        $installed = $this->normalizeVersion($installedVersion);
        $blocked = null;
        foreach ($releases as $release) {
            if (!$this->matchesChannel($release, $channel)) {
                continue;
            }
            if (version_compare($release['version'], $installed, '<=')) {
                continue;
            }
            $compat = $this->checkCompatibility($release, $phpVersion, $ionCubeVersion);
            if (!$compat['ok']) {
                if ($blocked === null) {
                    $blocked = $this->buildBlocked($release, $compat, $phpVersion, $ionCubeVersion);
                }
                continue;
            }

            return [
                'ok' => true,
                'release' => $release,
                'error' => '',
                'blocked' => $blocked,
            ];
        }

        if ($blocked !== null) {
            return [
                'ok' => false,
                'release' => null,
                'error' => (string) ($blocked['error'] ?? 'php_incompatible'),
                'blocked' => $blocked,
            ];
        }

        return ['ok' => false, 'release' => null, 'error' => 'already_latest', 'blocked' => null];
    }

    /**
     * Build a UI-friendly list of selectable releases for majors 8 and 9.
     *
     * Only versions newer than the installed release are included. Series 9
     * is listed before series 8; within each series versions are descending.
     *
     * @param array<int,array> $releases
     * @param string $installedVersion
     * @param string $channel stable|beta|rc|any
     * @param string|null $phpVersion
     * @param string|null $ionCubeVersion
     * @return array<int,array{version:string,full_version:string,series:string,compatible:bool,error:string}>
     */
    public function listSelectableReleases(
        array $releases,
        string $installedVersion,
        string $channel = 'stable',
        ?string $phpVersion = null,
        ?string $ionCubeVersion = null
    ): array {
        $phpVersion = $phpVersion !== null && $phpVersion !== '' ? $phpVersion : PHP_VERSION;
        $ionCubeVersion = $ionCubeVersion !== null ? $ionCubeVersion : $this->ionCubeVersion();
        $installed = $this->normalizeVersion($installedVersion);

        $items = [];
        foreach ($releases as $release) {
            if (!$this->matchesChannel($release, $channel)) {
                continue;
            }

            $version = (string) ($release['version'] ?? '');
            if ($version === '' || version_compare($version, $installed, '<=')) {
                continue;
            }

            $major = (int) explode('.', $version . '.0')[0];
            if ($major !== 8 && $major !== 9) {
                continue;
            }

            $compat = $this->checkCompatibility($release, $phpVersion, $ionCubeVersion);
            $items[] = [
                'version' => $version,
                'full_version' => (string) ($release['full_version'] ?? $version),
                'series' => (string) $major,
                'compatible' => $compat['ok'],
                'error' => (string) ($compat['error'] ?? ''),
            ];
        }

        usort($items, static function (array $a, array $b) {
            $seriesCmp = ((int) $b['series']) <=> ((int) $a['series']);
            if ($seriesCmp !== 0) {
                return $seriesCmp;
            }

            return version_compare($b['version'], $a['version']);
        });

        return $items;
    }

    /**
     * @param array $release
     * @param string|null $phpVersion
     * @param string|null $ionCubeVersion
     * @return array{ok:bool,error:string}
     */
    public function checkCompatibility(
        array $release,
        ?string $phpVersion = null,
        ?string $ionCubeVersion = null
    ): array {
        $phpVersion = $phpVersion !== null && $phpVersion !== '' ? $phpVersion : PHP_VERSION;

        $phpConstraint = (string) ($release['php_constraint'] ?? '');
        if ($phpConstraint !== '' && !$this->phpSatisfies($phpConstraint, $phpVersion)) {
            return [
                'ok' => false,
                'error' => sprintf('php_incompatible:%s_requires_%s', $phpVersion, $phpConstraint),
            ];
        }

        $ionCubeRequired = (string) ($release['ioncube_required'] ?? '');
        if ($ionCubeRequired !== '') {
            $installed = $ionCubeVersion !== null ? $ionCubeVersion : $this->ionCubeVersion();
            if ($installed === '') {
                return ['ok' => false, 'error' => 'ioncube_missing'];
            }
            if (version_compare($installed, $ionCubeRequired, '<')) {
                return [
                    'ok' => false,
                    'error' => sprintf('ioncube_too_old:%s_requires_%s', $installed, $ionCubeRequired),
                ];
            }
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @param array $release
     * @param array{ok:bool,error:string} $compat
     * @return array<string,mixed>
     */
    public function buildBlocked(
        array $release,
        array $compat,
        string $phpVersion,
        string $ionCubeVersion
    ): array {
        return [
            'full_version' => (string) ($release['full_version'] ?? $release['version'] ?? ''),
            'version' => (string) ($release['version'] ?? ''),
            'error' => (string) ($compat['error'] ?? ''),
            'php_constraint' => (string) ($release['php_constraint'] ?? ''),
            'ioncube_required' => (string) ($release['ioncube_required'] ?? ''),
            'php_current' => $phpVersion,
            'ioncube_current' => $ionCubeVersion,
        ];
    }

    /**
     * Human-readable explanation of a blocked newer release.
     *
     * @param array<string,mixed> $blocked
     */
    public function formatBlockedMessage(array $blocked, ?string $compatibleTarget = null): string
    {
        $newer = (string) ($blocked['full_version'] ?? $blocked['version'] ?? '');
        $phpReq = (string) ($blocked['php_constraint'] ?? '');
        $ionReq = (string) ($blocked['ioncube_required'] ?? '');
        $phpCur = (string) ($blocked['php_current'] ?? PHP_VERSION);
        $ionCur = (string) ($blocked['ioncube_current'] ?? '');

        $needs = [];
        if ($phpReq !== '') {
            $needs[] = 'PHP ' . $phpReq . ' (current ' . $phpCur . ')';
        }
        if ($ionReq !== '') {
            $needs[] = 'ionCube Loader >= ' . $ionReq
                . ($ionCur !== '' ? ' (current ' . $ionCur . ')' : ' (not detected)');
        }

        $msg = 'WHMCS ' . $newer . ' is available but requires '
            . ($needs !== [] ? implode(' and ', $needs) : 'a newer PHP/ionCube stack');
        if ($compatibleTarget !== null && $compatibleTarget !== '') {
            $msg .= '. Update to ' . $compatibleTarget . ' first, then upgrade PHP/ionCube and check again.';
        } else {
            $msg .= '. Upgrade PHP/ionCube on both web and CLI, then check again.';
        }

        return $msg;
    }

    public function isMajorUpgrade(string $installedVersion, string $targetVersion): bool
    {
        $installed = $this->normalizeVersion($installedVersion);
        $target = $this->normalizeVersion($targetVersion);
        $installedMajor = (int) explode('.', $installed . '.0')[0];
        $targetMajor = (int) explode('.', $target . '.0')[0];

        return $targetMajor > $installedMajor;
    }

    public function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return '0.0.0';
        }

        // Strip channel suffixes like -release.1 / -beta.2 for comparison.
        if (preg_match('/^(\d+(?:\.\d+){1,3})/', $version, $m)) {
            return $m[1];
        }

        return $version;
    }

    /**
     * @return array|null
     */
    private function normalizePackage($key, array $pkg)
    {
        $dist = $pkg['dist'] ?? null;
        if (!is_array($dist) || empty($dist['url'])) {
            return null;
        }

        $extra = $pkg['extra'] ?? [];
        if (is_string($extra)) {
            $decoded = json_decode($extra, true);
            $extra = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($extra)) {
            $extra = [];
        }

        $fullVersion = (string) ($pkg['version'] ?? $key);
        $version = $this->normalizeVersion($fullVersion);
        $channel = $this->detectChannel($fullVersion, (string) $key);

        $require = is_array($pkg['require'] ?? null) ? $pkg['require'] : [];
        $extraRequire = is_array($extra['require'] ?? null) ? $extra['require'] : [];

        return [
            'key' => (string) $key,
            'name' => (string) ($pkg['name'] ?? 'whmcs/whmcs'),
            'version' => $version,
            'full_version' => $fullVersion,
            'channel' => $channel,
            'url' => (string) $dist['url'],
            'type' => (string) ($dist['type'] ?? 'zip'),
            'sha' => strtolower((string) ($extra['sha'] ?? '')),
            'file_size' => (int) ($extra['fileSize'] ?? 0),
            'php_constraint' => (string) ($require['php'] ?? ''),
            'ioncube_required' => (string) ($extraRequire['ionCubeLoader'] ?? ''),
            'release_notes_url' => (string) ($extra['releaseNotesUrl'] ?? ''),
            'time' => (string) ($pkg['time'] ?? ''),
            'sig' => (string) ($extra['sig'] ?? ''),
            'key_identifier' => (string) ($extra['keyIdentifier'] ?? ''),
        ];
    }

    private function detectChannel(string $fullVersion, string $key): string
    {
        $haystack = strtolower($fullVersion . ' ' . $key);
        if (strpos($haystack, 'beta') !== false) {
            return 'beta';
        }
        if (strpos($haystack, 'rc') !== false || strpos($haystack, 'release-candidate') !== false) {
            return 'rc';
        }
        if (strpos($haystack, 'alpha') !== false) {
            return 'alpha';
        }

        return 'stable';
    }

    private function matchesChannel(array $release, string $channel): bool
    {
        $channel = strtolower($channel);
        if ($channel === 'any' || $channel === 'all') {
            return true;
        }
        if ($channel === 'beta') {
            return in_array($release['channel'], ['stable', 'rc', 'beta'], true);
        }
        if ($channel === 'rc') {
            return in_array($release['channel'], ['stable', 'rc'], true);
        }

        return $release['channel'] === 'stable';
    }

    /**
     * Minimal Composer-like PHP constraint check for forms used by WHMCS
     * (e.g. "~8.2.0 || ~8.3.0 || ~8.4.0").
     */
    public function phpSatisfies(string $constraint, string $phpVersion): bool
    {
        $parts = preg_split('/\s*\|\|\s*/', trim($constraint)) ?: [];
        if ($parts === [] || $parts === ['']) {
            return true;
        }

        $php = $this->normalizeVersion($phpVersion);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '*' || $part === '') {
                return true;
            }
            if (preg_match('/^~(\d+)\.(\d+)\.(\d+)$/', $part, $m)) {
                $min = sprintf('%d.%d.%d', $m[1], $m[2], $m[3]);
                $max = sprintf('%d.%d.0', (int) $m[1], ((int) $m[2]) + 1);
                if (version_compare($php, $min, '>=') && version_compare($php, $max, '<')) {
                    return true;
                }
                continue;
            }
            if (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', $part, $m)) {
                $min = sprintf('%d.%d.%d', $m[1], $m[2], $m[3] ?? 0);
                $max = sprintf('%d.0.0', ((int) $m[1]) + 1);
                if (version_compare($php, $min, '>=') && version_compare($php, $max, '<')) {
                    return true;
                }
                continue;
            }
            if (preg_match('/^(>=|>|<=|<|==|=)?\s*(\d+(?:\.\d+){0,3})$/', $part, $m)) {
                $op = $m[1] !== '' ? ($m[1] === '=' ? '==' : $m[1]) : '==';
                if (version_compare($php, $m[2], $op)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function ionCubeVersion(): string
    {
        if (!extension_loaded('ionCube Loader') && !extension_loaded('ioncube_loader')) {
            // Some builds only expose the loader via the helper function.
            if (!function_exists('ioncube_loader_version') && !function_exists('ioncube_loader_iversion')) {
                return '';
            }
        }

        if (function_exists('ioncube_loader_version')) {
            $v = (string) ioncube_loader_version();
            if (preg_match('/(\d+(?:\.\d+){0,2})/', $v, $m)) {
                return $m[1];
            }
        }

        if (function_exists('ioncube_loader_iversion')) {
            $iv = (int) ioncube_loader_iversion();
            // 150000 => 15.0.0
            $major = intdiv($iv, 10000);
            $minor = intdiv($iv % 10000, 100);
            $patch = $iv % 100;

            return sprintf('%d.%d.%d', $major, $minor, $patch);
        }

        return '';
    }
}
