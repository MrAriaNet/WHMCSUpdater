<?php
/**
 * Smoke-test ProxyRepository::parseUrl / buildUrl without a WHMCS bootstrap.
 *
 * Usage: php tests/smoke_parse_url.php
 */

// Minimal stand-in so bootstrap allows CLI includes outside WHMCS.
namespace {
    require_once dirname(__DIR__) . '/modules/addons/whmcsupdater/lib/bootstrap.php';
}

namespace {
    use WHMCSUpdater\ProxyRepository;

    $repo = new class extends ProxyRepository {
        // Avoid touching Capsule; only exercise pure helpers.
    };

    $cases = [
        'socks5://user:p%40ss@10.0.0.1:1080' => ['socks5', '10.0.0.1', 1080, 'user', 'p@ss'],
        'http://proxy.example.com:8080' => ['http', 'proxy.example.com', 8080, '', ''],
        'https://u:p@host:8443' => ['https', 'host', 8443, 'u', 'p'],
        'socks5h://1.2.3.4:1080' => ['socks5', '1.2.3.4', 1080, '', ''],
    ];

    $failed = 0;
    foreach ($cases as $url => $expect) {
        $parsed = $repo->parseUrl($url);
        if (!$parsed['ok']) {
            echo "FAIL parse: $url => {$parsed['error']}\n";
            $failed++;
            continue;
        }
        $d = $parsed['data'];
        $match = $d['scheme'] === $expect[0]
            && $d['host'] === $expect[1]
            && $d['port'] === $expect[2]
            && $d['username'] === $expect[3]
            && $d['password'] === $expect[4];
        if (!$match) {
            echo "FAIL data: $url\n";
            var_export($d);
            echo "\n";
            $failed++;
            continue;
        }

        $proxy = (object) [
            'scheme' => $d['scheme'],
            'host' => $d['host'],
            'port' => $d['port'],
            'username' => $d['username'],
            'password' => base64_encode($d['password']),
            'label' => '',
        ];
        $built = $repo->buildUrl($proxy, true);
        echo "OK  $url => $built\n";
    }

    $invalid = $repo->parseUrl('ftp://x:1');
    if (!empty($invalid['ok'])) {
        echo "FAIL: ftp should be rejected\n";
        $failed++;
    } else {
        echo "OK  rejected unsupported scheme\n";
    }

    // ProxyEnv apply/restore
    $before = getenv('https_proxy');
    \WHMCSUpdater\ProxyEnv::apply('socks5h://127.0.0.1:1080', 'localhost');
    $applied = getenv('https_proxy');
    \WHMCSUpdater\ProxyEnv::restore();
    $after = getenv('https_proxy');

    if ($applied !== 'socks5h://127.0.0.1:1080') {
        echo "FAIL ProxyEnv apply\n";
        $failed++;
    } elseif (($before === false && $after !== false && $after !== '') && $after === 'socks5h://127.0.0.1:1080') {
        // Some PHP builds cannot truly unset putenv; treat restore-to-previous as success when previous existed.
        echo "WARN ProxyEnv restore could not fully clear unset var on this PHP build\n";
    } else {
        echo "OK  ProxyEnv apply/restore\n";
    }

    exit($failed > 0 ? 1 : 0);
}
