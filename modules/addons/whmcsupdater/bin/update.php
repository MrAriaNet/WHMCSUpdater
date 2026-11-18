#!/usr/bin/env php
<?php
/**
 * WHMCS Updater — resumable WHMCS updater (CLI).
 *
 * Commands that do NOT bootstrap WHMCS (safe during Err 2):
 *   php update.php status
 *   php update.php apply --yes [--no-backup]
 *   php update.php step [--budget=12]
 *   php update.php rollback
 *   php update.php token
 *   php update.php finalize
 *
 * Commands that require WHMCS (catalog / download via proxy pool):
 *   php update.php check [--version=X] [--channel=stable]
 *   php update.php download [--version=X] [--zip=PATH] [--proxy-id=N] [--direct]
 *   php update.php run --yes [download options] [--no-backup]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

define('WHMCSUPDATER_STANDALONE', true);

$options = parseCliArgs($argv);
$command = $options['command'] ?? 'status';

$whmcsRoot = $options['whmcs-root'] ?? detectRootFromScript();
$workDir = $options['work-dir'] ?? null;
$phpCli = $options['php'] ?? null;

require_once dirname(__DIR__) . '/lib/bootstrap.php';

use WHMCSUpdater\ManualUpdater;
use WHMCSUpdater\UpdateJob;

$standaloneCommands = ['status', 'apply', 'step', 'rollback', 'token', 'finalize', 'help', '--help', '-h'];

if (in_array($command, $standaloneCommands, true)) {
    try {
        $job = UpdateJob::createStandalone($whmcsRoot, $workDir, $phpCli);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Failed to initialize updater: ' . $e->getMessage() . "\n");
        exit(1);
    }

    switch ($command) {
        case 'status':
            echo json_encode($job->statusPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);

        case 'token':
            $token = $job->getStore()->issueToken();
            echo $token . "\n";
            exit(0);

        case 'step':
            $budget = isset($options['budget']) ? (int) $options['budget'] : UpdateJob::DEFAULT_BUDGET;
            $result = $job->tick($budget);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            exit(!empty($result['done']) ? 0 : (($result['ok'] ?? false) ? 0 : 3));

        case 'apply':
            if (empty($options['yes'])) {
                fwrite(STDERR, "Refusing to apply without --yes (database backup is your responsibility).\n");
                exit(4);
            }
            $start = $job->start([
                'yes' => true,
                'no_backup' => !empty($options['no-backup']),
            ]);
            if (!$start['ok']) {
                echo json_encode($start, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                exit(6);
            }
            $result = $job->runToCompletion(isset($options['budget']) ? (int) $options['budget'] : UpdateJob::DEFAULT_BUDGET);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            if (!empty($result['needs_manual_installer'])) {
                fwrite(STDERR, "Files ready. Open /install/install.php, then run:\n");
                fwrite(STDERR, "  php update.php finalize\n");
                exit(5);
            }
            exit(!empty($result['done']) && !empty($result['ok']) ? 0 : 6);

        case 'finalize':
            $job->continueAfterManual();
            $result = $job->runToCompletion();
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            exit(!empty($result['done']) && !empty($result['ok']) ? 0 : 7);

        case 'rollback':
            $result = $job->rollback();
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            exit($result['ok'] ? 0 : 8);

        case 'help':
        case '--help':
        case '-h':
            printUsage();
            exit(0);
    }
}

// WHMCS-backed commands.
if (!$whmcsRoot || !is_file($whmcsRoot . '/init.php')) {
    fwrite(STDERR, "Unable to locate WHMCS root. Pass --whmcs-root=/path/to/whmcs\n");
    exit(1);
}

if (!defined('CLIENTAREA')) {
    define('CLIENTAREA', true);
}
require_once $whmcsRoot . '/init.php';

try {
    $updater = new ManualUpdater($whmcsRoot, $workDir, $phpCli);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to initialize updater: ' . $e->getMessage() . "\n");
    exit(1);
}

switch ($command) {
    case 'check':
        $result = $updater->checkForUpdate(
            $options['version'] ?? null,
            $options['channel'] ?? 'stable',
            !empty($options['direct']),
            isset($options['proxy-id']) ? (int) $options['proxy-id'] : null
        );
        echo json_encode([
            'ok' => $result['ok'],
            'installed' => $result['installed'],
            'error' => $result['error'],
            'release' => $result['release'],
            'blocked' => $result['blocked'] ?? null,
            'major_upgrade' => !empty($result['major_upgrade']),
            'message' => $updater->getState()['message'] ?? '',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit($result['ok'] ? 0 : 2);

    case 'download':
        $check = $updater->checkForUpdate(
            $options['version'] ?? null,
            $options['channel'] ?? 'stable',
            !empty($options['direct']),
            isset($options['proxy-id']) ? (int) $options['proxy-id'] : null
        );
        if (!$check['ok']) {
            fwrite(STDERR, 'No update target: ' . $check['error'] . "\n");
            exit(2);
        }
        $result = $updater->download(
            0,
            $options['zip'] ?? null,
            isset($options['proxy-id']) ? (int) $options['proxy-id'] : null,
            !empty($options['direct']),
            isset($options['min-speed']) ? (int) $options['min-speed'] : 1024
        );
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit($result['ok'] && $result['done'] ? 0 : 3);

    case 'run':
        if (empty($options['yes'])) {
            fwrite(STDERR, "Refusing to run full update without --yes (database backup is your responsibility).\n");
            exit(4);
        }
        $result = $updater->run([
            'version' => $options['version'] ?? null,
            'channel' => $options['channel'] ?? 'stable',
            'zip' => $options['zip'] ?? null,
            'proxy_id' => isset($options['proxy-id']) ? (int) $options['proxy-id'] : null,
            'direct' => !empty($options['direct']),
            'min_speed' => isset($options['min-speed']) ? (int) $options['min-speed'] : 1024,
            'yes' => true,
            'no_backup' => !empty($options['no-backup']),
        ]);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (!empty($result['needs_manual_installer'])) {
            fwrite(STDERR, "Files ready. Open /install/install.php, then run finalize.\n");
            exit(5);
        }
        exit($result['ok'] ? 0 : 8);

    default:
        fwrite(STDERR, "Unknown command: {$command}\n\n");
        printUsage();
        exit(1);
}

/**
 * @param array $argv
 * @return array<string,mixed>
 */
function parseCliArgs(array $argv): array
{
    $out = ['command' => 'status'];
    $args = array_slice($argv, 1);
    if ($args === []) {
        return $out;
    }

    if (isset($args[0]) && $args[0] !== '' && $args[0][0] !== '-') {
        $out['command'] = strtolower((string) array_shift($args));
    }

    foreach ($args as $arg) {
        if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
            continue;
        }
        if (preg_match('/^--(.+)$/', $arg, $m)) {
            $out[$m[1]] = true;
            continue;
        }
    }

    return $out;
}

function detectRootFromScript(): ?string
{
    $candidate = dirname(__DIR__, 4);
    if (is_file($candidate . '/init.php')) {
        return $candidate;
    }

    return null;
}

function printUsage(): void
{
    $usage = <<<TXT
WHMCS Updater — resumable WHMCS updater

Standalone (no WHMCS bootstrap — safe during maintenance/Err 2):
  status
  apply --yes [--no-backup]
  step [--budget=12]
  finalize
  rollback
  token

Requires WHMCS:
  check [--version=] [--channel=stable|beta|rc|any]
  download [--version=] [--zip=PATH] [--proxy-id=N] [--direct] [--min-speed=1024]
  run --yes [download options] [--no-backup]

Options:
  --whmcs-root=PATH
  --work-dir=PATH
  --php=PATH                 PHP CLI binary for installer.php
  --yes                      Confirm destructive apply/run
  --no-backup                Skip file backup before overwrite

Examples:
  php update.php check
  php update.php download
  php update.php apply --yes
  php update.php run --yes

TXT;
    fwrite(STDOUT, $usage);
}
