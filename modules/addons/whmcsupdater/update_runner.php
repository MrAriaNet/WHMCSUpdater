<?php
/**
 * Standalone status / step / rollback endpoint for the staged updater.
 *
 * Intentionally does NOT bootstrap WHMCS so it remains reachable while the
 * install/ directory is present (Down for Maintenance Err 2).
 *
 * Auth: status_token issued when apply starts (hash_equals).
 *
 * Actions (JSON):
 *   ?action=status&token=…
 *   ?action=step&token=…&budget=12
 *   ?action=rollback&token=…
 *   ?action=continue_manual&token=…
 *   ?action=clear_templates&token=… (token optional after successful complete)
 *
 * Browser recovery UI:
 *   ?action=ui&token=…
 */

declare(strict_types=1);

define('WHMCSUPDATER_STANDALONE', true);

require_once __DIR__ . '/lib/bootstrap.php';

use WHMCSUpdater\StateStore;
use WHMCSUpdater\UpdateJob;

$workDir = resolveWorkDir();
if ($workDir === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'work_dir_unavailable']);
    exit;
}

$token = (string) ($_REQUEST['token'] ?? '');
$store = new StateStore($workDir);
if ($token === '' || !$store->validateToken($token)) {
    // Allow status of idle without token only for empty state — otherwise 403.
    // After a successful update the apply token is cleared; still allow status /
    // clear_templates while the completed state remains on disk.
    $actionProbe = strtolower((string) ($_REQUEST['action'] ?? 'status'));
    $stateProbe = $store->getState();
    $postComplete = in_array($actionProbe, ['status', 'clear_templates'], true)
        && ($stateProbe['phase'] ?? '') === 'done'
        && ($stateProbe['status'] ?? '') === 'complete';
    $idleEmpty = $actionProbe === 'status'
        && !is_file($workDir . '/' . StateStore::TOKEN_FILE)
        && !is_file($workDir . '/' . StateStore::STATE_FILE);
    if (!$idleEmpty && !$postComplete) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'invalid_token']);
        exit;
    }
}

$action = strtolower((string) ($_REQUEST['action'] ?? 'status'));
$wantsHtml = ($action === 'ui') || (isset($_GET['format']) && $_GET['format'] === 'html');

$phpCli = readPhpCliPointer();
$job = UpdateJob::createStandalone(detectWhmcsRoot(), $workDir, $phpCli);

if ($wantsHtml && $action === 'ui') {
    renderRecoveryUi($job, $token);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate');

switch ($action) {
    case 'step':
        $budget = max(5, min(45, (int) ($_REQUEST['budget'] ?? UpdateJob::DEFAULT_BUDGET)));
        $state = $job->statusPayload();
        // Auto-start if download complete but apply not armed.
        if (in_array((string) ($state['status'] ?? ''), ['complete', 'ready', 'idle'], true)
            && (string) ($state['phase'] ?? '') === 'download'
        ) {
            // Require explicit start from admin; step alone will not begin apply.
        }
        if (in_array((string) ($state['phase'] ?? ''), ['idle', 'catalog', 'download'], true)
            && (string) ($state['status'] ?? '') !== 'running'
            && empty($state['cursor'])
        ) {
            // Not an apply job yet.
            if (!in_array((string) ($state['phase'] ?? ''), UpdateJob::PHASES, true)) {
                echo json_encode([
                    'ok' => false,
                    'done' => false,
                    'error' => 'apply_not_started',
                    'state' => $state,
                ]);
                exit;
            }
        }
        $result = $job->tick($budget);
        echo json_encode($result);
        exit;

    case 'start':
        $confirm = !empty($_REQUEST['confirm']) || !empty($_REQUEST['yes']);
        $noBackup = !empty($_REQUEST['no_backup']);
        $result = $job->start([
            'yes' => $confirm,
            'no_backup' => $noBackup,
        ]);
        echo json_encode($result);
        exit;

    case 'rollback':
        $result = $job->rollback();
        echo json_encode($result);
        exit;

    case 'continue_manual':
    case 'finalize':
        $job->continueAfterManual();
        $result = $job->tick(max(5, min(45, (int) ($_REQUEST['budget'] ?? UpdateJob::DEFAULT_BUDGET))));
        echo json_encode($result);
        exit;

    case 'clear_templates':
        $result = $job->getApplier()->clearTemplateCache();
        echo json_encode([
            'ok' => $result['ok'],
            'error' => $result['error'],
            'path' => $result['path'],
            'cleared' => $result['cleared'],
            'message' => $result['ok']
                ? 'Template cache cleared successfully.'
                : ($result['error'] !== '' ? $result['error'] : 'Failed to clear template cache.'),
            'state' => $job->statusPayload(),
        ]);
        exit;

    case 'status':
    default:
        echo json_encode([
            'ok' => true,
            'state' => $job->statusPayload(),
        ]);
        exit;
}

/**
 * @return string|null
 */
function resolveWorkDir()
{
    $default = __DIR__ . '/storage/manual_update';

    $pointer = __DIR__ . '/storage/work_dir_pointer.txt';
    if (is_file($pointer)) {
        $custom = trim((string) file_get_contents($pointer));
        if ($custom !== '' && is_dir($custom)) {
            return rtrim($custom, '/');
        }
    }

    // Legacy work directories from proxyupdater / proxypool (interrupted updates).
    if (!is_file($default . '/state.json')) {
        foreach (['proxyupdater', 'proxypool'] as $legacyModule) {
            $legacy = dirname(__DIR__) . '/' . $legacyModule . '/storage/manual_update';
            if (is_dir($legacy) && is_file($legacy . '/state.json')) {
                return rtrim($legacy, '/');
            }
        }
    }

    if (is_dir($default)) {
        return rtrim($default, '/');
    }

    if (@mkdir($default, 0750, true) || is_dir($default)) {
        return rtrim($default, '/');
    }

    return null;
}

/**
 * @return string|null
 */
function readPhpCliPointer()
{
    $pointer = __DIR__ . '/storage/php_cli_pointer.txt';
    if (is_file($pointer)) {
        $path = trim((string) file_get_contents($pointer));
        return $path !== '' ? $path : null;
    }

    return null;
}

/**
 * @return string|null
 */
function detectWhmcsRoot()
{
    $candidate = dirname(__DIR__, 3);
    if (is_file($candidate . '/init.php') && is_file($candidate . '/configuration.php')) {
        return $candidate;
    }

    return null;
}

function renderRecoveryUi(UpdateJob $job, string $token): void
{
    $state = $job->statusPayload();
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
    $tokenJs = json_encode($token, JSON_UNESCAPED_SLASHES);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>WHMCS Updater — Recovery</title>
<style>
body{font-family:system-ui,sans-serif;margin:0;background:#f4f6f8;color:#222;}
.wrap{max-width:720px;margin:32px auto;padding:24px;background:#fff;border:1px solid #d7dde5;border-radius:8px;}
h1{font-size:20px;margin:0 0 8px;}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:16px 0;}
.bar{height:12px;background:#e8edf2;border-radius:6px;overflow:hidden;}
.bar>div{height:100%;background:#2f6fed;width:0;}
.msg{color:#556;margin:12px 0;}
.err{background:#fdecea;border:1px solid #f5c2c0;color:#842029;padding:10px;border-radius:4px;display:none;}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;}
button{padding:8px 14px;border-radius:4px;border:1px solid #ccd;background:#f7f9fb;cursor:pointer;}
button.primary{background:#2f6fed;color:#fff;border-color:#2f6fed;}
button.warn{background:#f0ad4e;color:#fff;border-color:#f0ad4e;}
code{font-size:12px;background:#f0f3f6;padding:2px 6px;border-radius:3px;}
</style>
</head>
<body>
<div class="wrap">
  <h1>WHMCS Updater — Recovery</h1>
  <p class="msg">This page works even when the WHMCS Admin Area is offline during an upgrade.</p>
  <div class="meta">
    <div><strong>Phase:</strong> <span id="phase"></span></div>
    <div><strong>Status:</strong> <span id="status"></span></div>
    <div><strong>Target:</strong> <span id="target"></span></div>
    <div><strong>Progress:</strong> <span id="percent"></span></div>
  </div>
  <div class="bar"><div id="bar"></div></div>
  <p class="msg" id="message"></p>
  <div class="err" id="error"></div>
  <div class="actions">
    <button class="primary" id="btn-step" type="button">Continue / Step</button>
    <button id="btn-refresh" type="button">Refresh</button>
    <button class="warn" id="btn-rollback" type="button">Rollback files</button>
  </div>
  <p class="msg">CLI: <code>php modules/addons/whmcsupdater/bin/update.php apply --yes</code></p>
</div>
<script>
(function(){
  var token = {$tokenJs};
  var state = {$json};
  var offeredTemplateClear = false;
  function apply(s){
    if(!s) return;
    document.getElementById('phase').textContent = s.phase || '';
    document.getElementById('status').textContent = s.status || '';
    document.getElementById('target').textContent = (s.release && s.release.full_version) ? s.release.full_version : '—';
    document.getElementById('percent').textContent = (s.percent != null ? s.percent : 0) + '%';
    document.getElementById('bar').style.width = (s.percent || 0) + '%';
    document.getElementById('message').textContent = s.message || '';
    var err = document.getElementById('error');
    if (s.error) { err.style.display='block'; err.textContent=s.error; }
    else { err.style.display='none'; err.textContent=''; }
  }
  function call(action, extra){
    var body = new FormData();
    body.append('action', action);
    body.append('token', token);
    if (extra) Object.keys(extra).forEach(function(k){ body.append(k, extra[k]); });
    return fetch(location.pathname, {method:'POST', body:body, credentials:'same-origin', cache:'no-store'})
      .then(function(r){ return r.json(); });
  }
  function offerClearTemplateCache(){
    if (offeredTemplateClear) return;
    offeredTemplateClear = true;
    if (!confirm('Update completed successfully. Clear the template cache (templates_c) now?')) return;
    call('clear_templates').then(function(res){
      alert(res && res.message ? res.message : (res && res.ok ? 'Template cache cleared.' : (res && res.error ? res.error : 'failed')));
    }).catch(function(e){ alert(String(e)); });
  }
  var busy=false;
  function loop(){
    if(busy) return;
    busy=true;
    call('step', {budget:12}).then(function(res){
      if(res.state) apply(res.state);
      busy=false;
      if(res.ok && res.done){
        offerClearTemplateCache();
        return;
      }
      if(res.ok && !res.done && !res.needs_manual_installer && res.state && res.state.status !== 'awaiting_manual' && res.state.status !== 'error'){
        setTimeout(loop, 400);
      }
    }).catch(function(e){ busy=false; document.getElementById('error').style.display='block'; document.getElementById('error').textContent=String(e); });
  }
  document.getElementById('btn-step').onclick=function(){ loop(); };
  document.getElementById('btn-refresh').onclick=function(){
    call('status').then(function(res){ if(res.state) apply(res.state); });
  };
  document.getElementById('btn-rollback').onclick=function(){
    if(!confirm('Restore files from the last backup?')) return;
    call('rollback').then(function(res){ if(res.state) apply(res.state); alert(res.ok ? 'Rollback complete' : (res.error||'failed')); });
  };
  apply(state);
  if (state && state.status === 'complete' && state.phase === 'done') {
    offerClearTemplateCache();
  }
})();
</script>
</body>
</html>
HTML;
}
