<?php
/**
 * Smoke-test pure Manual Updater helpers without a live WHMCS install.
 *
 * Usage: php tests/smoke_manual_update.php
 */

namespace {
    require_once dirname(__DIR__) . '/modules/addons/whmcsupdater/lib/bootstrap.php';
}

namespace {
    use WHMCSUpdater\ReleaseCatalog;
    use WHMCSUpdater\UpdateApplier;

    $failed = 0;

    function fail(string $msg) {
        global $failed;
        echo "FAIL $msg\n";
        $failed++;
    }

    function ok(string $msg) {
        echo "OK  $msg\n";
    }

    $catalog = new ReleaseCatalog();

    // --- version normalization ---
    if ($catalog->normalizeVersion('9.1.1-release.1') !== '9.1.1') {
        fail('normalizeVersion release suffix');
    } else {
        ok('normalizeVersion release suffix');
    }
    if ($catalog->normalizeVersion('9.0.9') !== '9.0.9') {
        fail('normalizeVersion plain');
    } else {
        ok('normalizeVersion plain');
    }

    // --- PHP constraint checks ---
    if (!$catalog->phpSatisfies('~8.2.0 || ~8.3.0 || ~8.4.0', '8.2.5')) {
        fail('phpSatisfies 8.2.5');
    } else {
        ok('phpSatisfies 8.2.5');
    }
    if ($catalog->phpSatisfies('~8.2.0 || ~8.3.0', '8.1.0')) {
        fail('phpSatisfies should reject 8.1');
    } else {
        ok('phpSatisfies rejects 8.1');
    }
    if (!$catalog->phpSatisfies('~8.4.0', '8.4.1')) {
        fail('phpSatisfies 8.4.1');
    } else {
        ok('phpSatisfies 8.4.1');
    }

    $constraint813 = '^7.2.0 || ^7.3.0 || ^7.4.0 || ~8.1.0 || ~8.2.0 || ~8.3.0';
    if (!$catalog->phpSatisfies($constraint813, '7.4.33')) {
        fail('phpSatisfies 8.13 constraint with 7.4');
    } else {
        ok('phpSatisfies 8.13 constraint with 7.4');
    }
    if ($catalog->phpSatisfies($constraint813, '8.0.0')) {
        fail('phpSatisfies should reject PHP 8.0 for 8.13 constraint');
    } else {
        ok('phpSatisfies rejects PHP 8.0 for 8.13 constraint');
    }
    if (!$catalog->phpSatisfies($constraint813, '8.3.0')) {
        fail('phpSatisfies 8.13 constraint with 8.3');
    } else {
        ok('phpSatisfies 8.13 constraint with 8.3');
    }

    // --- parse packages.json fixture (8.x + 9.x) ---
    $fixture = <<<'JSON'
{
  "packages": {
    "whmcs/whmcs": {
      "9.1.1-release.1": {
        "name": "whmcs/whmcs",
        "version": "9.1.1",
        "require": { "php": "~8.2.0 || ~8.3.0 || ~8.4.0" },
        "dist": { "url": "https://releases.whmcs.com/v2/pkgs/whmcs-9.1.1-release.1.zip", "type": "zip" },
        "extra": {
          "sha": "1a2711a8b62d4507a09c3473c8e1f8c91dd38fd02de99a21daab3b09c3c15878",
          "fileSize": 220966964,
          "require": { "ionCubeLoader": "15.0.0" }
        }
      },
      "9.0.9-release.1": {
        "name": "whmcs/whmcs",
        "version": "9.0.9",
        "require": { "php": "~8.2.0 || ~8.3.0" },
        "dist": { "url": "https://releases.whmcs.com/v2/pkgs/whmcs-9.0.9-release.1.zip", "type": "zip" },
        "extra": {
          "sha": "580feaca9553d3ca11d34536913088433f583a14bdccbf348a0dd92ebee9cc29",
          "fileSize": 175737330,
          "require": { "ionCubeLoader": "13.0.2" }
        }
      },
      "9.1.0-beta.2": {
        "name": "whmcs/whmcs",
        "version": "9.1.0-beta.2",
        "require": { "php": "~8.2.0 || ~8.3.0" },
        "dist": { "url": "https://releases.whmcs.com/v2/pkgs/whmcs-9.1.0-beta.2.zip", "type": "zip" },
        "extra": { "sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "fileSize": 100 }
      },
      "8.13.7-release.1": {
        "name": "whmcs/whmcs",
        "version": "8.13.7",
        "require": { "php": "^7.2.0 || ^7.3.0 || ^7.4.0 || ~8.1.0 || ~8.2.0 || ~8.3.0" },
        "dist": { "url": "https://releases.whmcs.com/v2/pkgs/whmcs-8.13.7-release.1.zip", "type": "zip" },
        "extra": {
          "sha": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
          "fileSize": 150000000,
          "require": { "ionCubeLoader": "10.2.0" }
        }
      },
      "8.9.0-release.1": {
        "name": "whmcs/whmcs",
        "version": "8.9.0",
        "require": { "php": "^7.2.0 || ^7.3.0 || ^7.4.0 || ~8.1.0" },
        "dist": { "url": "https://releases.whmcs.com/v2/pkgs/whmcs-8.9.0-release.1.zip", "type": "zip" },
        "extra": {
          "sha": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
          "fileSize": 140000000,
          "require": { "ionCubeLoader": "10.2.0" }
        }
      }
    }
  }
}
JSON;

    $parsed = $catalog->parse($fixture);
    if (!$parsed['ok'] || count($parsed['releases']) < 5) {
        fail('parse packages fixture: ' . ($parsed['error'] ?? 'unknown'));
    } else {
        ok('parse packages fixture (' . count($parsed['releases']) . ' releases)');
    }

    // Injected environment: 8.9.0 on PHP 8.1 → target 8.13.7, blocked 9.1.1 (PHP)
    $s1 = $catalog->pickTarget($parsed['releases'], '8.9.0', 'stable', null, '8.1.0', '12.0.1');
    if (!$s1['ok'] || ($s1['release']['version'] ?? '') !== '8.13.7') {
        fail('8.9 on PHP 8.1 should pick 8.13.7, got ' . ($s1['release']['version'] ?? $s1['error']));
    } elseif (($s1['blocked']['version'] ?? '') !== '9.1.1') {
        fail('8.9 on PHP 8.1 should block 9.1.1, got ' . json_encode($s1['blocked']));
    } elseif (strpos((string) ($s1['blocked']['error'] ?? ''), 'php_incompatible') !== 0) {
        fail('blocked error should be php_incompatible, got ' . ($s1['blocked']['error'] ?? ''));
    } else {
        ok('8.9 on PHP 8.1 → target 8.13.7, blocked 9.1.1 (PHP)');
    }

    // 8.13.7 on PHP 8.2 with ionCube 13.0.2 → target 9.0.9, blocked 9.1.1 (ionCube)
    $s2 = $catalog->pickTarget($parsed['releases'], '8.13.7', 'stable', null, '8.2.0', '13.0.2');
    if (!$s2['ok'] || ($s2['release']['version'] ?? '') !== '9.0.9') {
        fail('8.13 on PHP 8.2/ion13 should pick 9.0.9, got ' . ($s2['release']['version'] ?? $s2['error']));
    } elseif (($s2['blocked']['version'] ?? '') !== '9.1.1') {
        fail('8.13 on PHP 8.2/ion13 should block 9.1.1, got ' . json_encode($s2['blocked']));
    } elseif (strpos((string) ($s2['blocked']['error'] ?? ''), 'ioncube_too_old') !== 0) {
        fail('blocked error should be ioncube_too_old, got ' . ($s2['blocked']['error'] ?? ''));
    } else {
        ok('8.13 on PHP 8.2/ion13 → target 9.0.9, blocked 9.1.1 (ionCube)');
    }

    // 8.13.7 on PHP 8.3 with ionCube 15 → target 9.1.1
    $s3 = $catalog->pickTarget($parsed['releases'], '8.13.7', 'stable', null, '8.3.0', '15.0.0');
    if (!$s3['ok'] || ($s3['release']['version'] ?? '') !== '9.1.1') {
        fail('8.13 on PHP 8.3/ion15 should pick 9.1.1, got ' . ($s3['release']['version'] ?? $s3['error']));
    } elseif ($s3['blocked'] !== null) {
        fail('8.13 on PHP 8.3/ion15 should have no blocked release');
    } else {
        ok('8.13 on PHP 8.3/ion15 → target 9.1.1');
    }

    if (!$catalog->isMajorUpgrade('8.13.7', '9.0.9')) {
        fail('isMajorUpgrade 8→9');
    } else {
        ok('isMajorUpgrade 8→9');
    }
    if ($catalog->isMajorUpgrade('9.0.9', '9.1.1')) {
        fail('isMajorUpgrade should be false within 9.x');
    } else {
        ok('isMajorUpgrade false within 9.x');
    }

    // Exact version with injected compatible stack
    $pickedExact = $catalog->pickTarget($parsed['releases'], '9.0.9', 'stable', '9.1.1', '8.3.0', '15.0.0');
    if (!$pickedExact['ok'] || ($pickedExact['release']['version'] ?? '') !== '9.1.1') {
        fail('pickTarget exact 9.1.1 => ' . ($pickedExact['error'] ?? 'bad release'));
    } else {
        ok('pickTarget exact 9.1.1');
    }

    $already = $catalog->pickTarget($parsed['releases'], '9.1.1', 'stable', null, '8.3.0', '15.0.0');
    if ($already['ok'] || $already['error'] !== 'already_latest') {
        fail('unexpected already_latest error: ' . ($already['error'] ?? 'ok'));
    } else {
        ok('already_latest / no newer for 9.1.1');
    }

    // Beta channel should see beta packages.
    $betaOnly = null;
    foreach ($parsed['releases'] as $r) {
        if ($r['channel'] === 'beta') {
            $betaOnly = $r;
            break;
        }
    }
    if (!$betaOnly) {
        fail('fixture missing beta release');
    } else {
        ok('fixture has beta release ' . $betaOnly['full_version']);
    }

    // --- path mapping ---
    $tmpRoot = sys_get_temp_dir() . '/pp_mu_test_' . getmypid();
    @mkdir($tmpRoot, 0755, true);
    file_put_contents($tmpRoot . '/configuration.php', "<?php\n\$customadminpath = 'myadmin';\n\$crons_dir = '{$tmpRoot}/custom_crons';\n");
    file_put_contents($tmpRoot . '/init.php', "<?php\n");
    @mkdir($tmpRoot . '/custom_crons', 0755, true);

    $work = $tmpRoot . '/work';
    @mkdir($work, 0755, true);
    $applier = new UpdateApplier($tmpRoot, $work);

    if ($applier->getAdminDirName() !== 'myadmin') {
        fail('admin dir override, got ' . $applier->getAdminDirName());
    } else {
        ok('admin dir override myadmin');
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

    if ($applier->mapRelativePath('includes/foo.php') !== 'includes/foo.php') {
        fail('map normal path');
    } else {
        ok('map normal path');
    }

    // --- package root detection ---
    $staging = $work . '/staging';
    @mkdir($staging . '/whmcs/install', 0755, true);
    file_put_contents($staging . '/whmcs/init.php', "<?php\n");
    file_put_contents($staging . '/whmcs/install/install.php', "<?php\n");
    $root = $applier->findPackageRoot($staging);
    $rootNorm = $root !== null ? $root : '';
    if ($root === null || substr($rootNorm, -6) !== '/whmcs') {
        fail('findPackageRoot nested whmcs/');
    } else {
        ok('findPackageRoot nested whmcs/');
    }

    // Cleanup temp tree
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($tmpRoot);

    // --- ProxyCurl type mapping ---
    $httpType = \WHMCSUpdater\ProxyCurl::curlProxyType('http');
    $socksType = \WHMCSUpdater\ProxyCurl::curlProxyType('socks5', true);
    if ($httpType === null && !defined('CURLPROXY_HTTP')) {
        ok('ProxyCurl http type unavailable (no curl constants)');
    } elseif ($httpType === null) {
        fail('ProxyCurl http type null with constants present');
    } else {
        ok('ProxyCurl http type');
    }
    if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
        if ($socksType !== CURLPROXY_SOCKS5_HOSTNAME) {
            fail('ProxyCurl socks5h type');
        } else {
            ok('ProxyCurl socks5h type');
        }
    } else {
        ok('ProxyCurl socks5 type (no HOSTNAME constant)');
    }

    exit($failed > 0 ? 1 : 0);
}
