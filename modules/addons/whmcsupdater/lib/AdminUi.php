<?php
/**
 * Admin area UI for Addons > WHMCS Updater.
 *
 * All user-visible strings come from the language array — no hardcoded copy.
 */

namespace WHMCSUpdater;

class AdminUi
{
    /**
     * Handle POST actions and render the full management page.
     *
     * @param array $vars
     * @param array $lang
     */
    public static function render(array $vars, array $lang): string
    {
        $moduleLink = $vars['modulelink'] ?? 'addonmodules.php?module=whmcsupdater';
        $messages = [];
        $errors = [];
        $editProxy = null;

        $action = (string) ($_REQUEST['pp_action'] ?? '');

        // Manual-update AJAX endpoints return JSON and stop page rendering.
        if (strpos($action, 'mu_') === 0) {
            self::handleManualUpdateAjax($action, $lang, $moduleLink);
            return '';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // WHMCS check_token exits/redirects on failure; that is intentional.
            if (function_exists('check_token')) {
                check_token('WHMCS.default');
            }
            $outcome = self::handlePost($action, $lang);
            $messages = array_merge($messages, $outcome['messages']);
            $errors = array_merge($errors, $outcome['errors']);
            if (!empty($outcome['edit_id'])) {
                $repo = new ProxyRepository();
                $editProxy = $repo->find((int) $outcome['edit_id']);
            }
        } elseif ($action === 'edit' && !empty($_GET['id'])) {
            $repo = new ProxyRepository();
            $editProxy = $repo->find((int) $_GET['id']);
            if (!$editProxy) {
                $errors[] = Lang::get($lang, 'msg_not_found');
            }
        }

        $repo = new ProxyRepository();
        $checker = new ProxyChecker(
            Settings::get('health_check_url'),
            Settings::int('connect_timeout', 10),
            Settings::bool('socks5_remote_dns', true)
        );
        $selector = Settings::makeSelector();

        $proxies = $repo->all();
        $logs = $repo->recentLogs(40);
        $cached = $selector->getCached();
        $isRtl = Lang::isRtl($vars, $lang);
        $dir = $isRtl ? 'rtl' : 'ltr';

        $muStatus = [];
        try {
            $muStatus = (new ManualUpdater())->statusPayload();
        } catch (\Throwable $e) {
            $muStatus = ['phase' => 'idle', 'status' => 'error', 'message' => $e->getMessage(), 'error' => $e->getMessage()];
        }

        $html = [];
        $html[] = '<div id="whmcsupdater-admin" dir="' . self::e($dir) . '" style="max-width:1200px;">';
        $html[] = self::styles();
        $html[] = '<h2>' . self::e(Lang::get($lang, 'module_title')) . '</h2>';
        $html[] = '<p class="pp-intro">' . self::e(Lang::get($lang, 'module_intro')) . '</p>';

        if (!$checker->supportsHttpsProxy()) {
            $html[] = '<div class="pp-alert pp-warn">' . self::e(sprintf(
                Lang::get($lang, 'warn_curl_https'),
                $checker->curlVersion()
            )) . '</div>';
        }

        foreach ($errors as $error) {
            $html[] = '<div class="pp-alert pp-error">' . self::e($error) . '</div>';
        }
        foreach ($messages as $message) {
            $html[] = '<div class="pp-alert pp-ok">' . self::e($message) . '</div>';
        }

        $html[] = self::renderManualUpdate($lang, $moduleLink, $muStatus);
        $html[] = self::renderStatus($lang, $cached, $repo, $checker);
        $html[] = self::renderForm($lang, $moduleLink, $editProxy);
        $html[] = self::renderList($lang, $moduleLink, $proxies);
        $html[] = self::renderImport($lang, $moduleLink);
        $html[] = self::renderLogs($lang, $logs);
        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * Handle mu_* POST actions as JSON responses.
     *
     * Apply is started here (issues token) but advanced via update_runner.php
     * so progress continues even when WHMCS goes into maintenance/Err 2.
     */
    private static function handleManualUpdateAjax(string $action, array $lang, string $moduleLink): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
            exit;
        }

        if (function_exists('check_token')) {
            check_token('WHMCS.default');
        }

        try {
            $updater = new ManualUpdater();
            self::persistPointers($updater);

            switch ($action) {
                case 'mu_status':
                    echo json_encode(['ok' => true, 'state' => $updater->statusPayload()]);
                    break;

                case 'mu_check':
                    $channel = (string) ($_POST['channel'] ?? 'stable');
                    $version = trim((string) ($_POST['version'] ?? ''));
                    $result = $updater->checkForUpdate($version !== '' ? $version : null, $channel);
                    echo json_encode([
                        'ok' => $result['ok'],
                        'error' => $result['error'],
                        'installed' => $result['installed'],
                        'release' => $result['release'],
                        'blocked' => $result['blocked'] ?? null,
                        'major_upgrade' => !empty($result['major_upgrade']),
                        'state' => $updater->statusPayload(),
                    ]);
                    break;

                case 'mu_download':
                    $budget = max(10, min(45, (int) ($_POST['budget'] ?? 20)));
                    $direct = !empty($_POST['direct']);
                    $proxyId = !empty($_POST['proxy_id']) ? (int) $_POST['proxy_id'] : null;
                    $zip = trim((string) ($_POST['zip'] ?? ''));

                    $state = $updater->getState();
                    if (empty($state['release'])) {
                        $check = $updater->checkForUpdate();
                        if (!$check['ok']) {
                            echo json_encode([
                                'ok' => false,
                                'done' => false,
                                'error' => $check['error'],
                                'state' => $updater->statusPayload(),
                            ]);
                            break;
                        }
                    }

                    $result = $updater->download(
                        $budget,
                        $zip !== '' ? $zip : null,
                        $proxyId,
                        $direct
                    );
                    echo json_encode([
                        'ok' => $result['ok'],
                        'done' => $result['done'],
                        'error' => $result['error'],
                        'state' => $updater->statusPayload(),
                    ]);
                    break;

                case 'mu_apply':
                    if (empty($_POST['confirm'])) {
                        echo json_encode([
                            'ok' => false,
                            'error' => 'confirmation_required',
                            'message' => Lang::get($lang, 'mu_confirm_required'),
                            'state' => $updater->statusPayload(),
                        ]);
                        break;
                    }
                    $doBackup = empty($_POST['no_backup']);
                    $result = $updater->startApply(true, $doBackup);
                    echo json_encode([
                        'ok' => $result['ok'],
                        'error' => $result['error'],
                        'started' => $result['ok'],
                        'runner' => '../modules/addons/whmcsupdater/update_runner.php',
                        'state' => $updater->statusPayload(),
                    ]);
                    break;

                case 'mu_finalize':
                    $token = (string) ($_POST['token'] ?? '');
                    $result = $updater->finalizeOnly($token !== '' ? $token : null);
                    echo json_encode([
                        'ok' => $result['ok'],
                        'error' => $result['error'],
                        'state' => $updater->statusPayload(),
                    ]);
                    break;

                case 'mu_rollback':
                    if (empty($_POST['confirm'])) {
                        echo json_encode([
                            'ok' => false,
                            'error' => 'confirmation_required',
                            'state' => $updater->statusPayload(),
                        ]);
                        break;
                    }
                    $result = $updater->rollback();
                    echo json_encode([
                        'ok' => $result['ok'],
                        'error' => $result['error'],
                        'state' => $updater->statusPayload(),
                    ]);
                    break;

                case 'mu_clear':
                    $updater->clearState();
                    echo json_encode(['ok' => true, 'state' => $updater->statusPayload()]);
                    break;

                default:
                    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    private static function persistPointers(ManualUpdater $updater): void
    {
        $pointerDir = dirname(__DIR__) . '/storage';
        if (!is_dir($pointerDir)) {
            @mkdir($pointerDir, 0750, true);
        }
        @file_put_contents($pointerDir . '/work_dir_pointer.txt', $updater->getWorkDir());
        $phpCli = Settings::get('php_cli_path', '');
        if ($phpCli !== '') {
            @file_put_contents($pointerDir . '/php_cli_pointer.txt', $phpCli);
        }
        // Remember the Admin (web) PHP version so CLI apply can refuse a
        // release that the live site cannot run.
        @file_put_contents($pointerDir . '/web_php_pointer.txt', PHP_VERSION);
    }

    /**
     * @param array<string,mixed> $status
     */
    private static function renderManualUpdate(array $lang, string $moduleLink, array $status): string
    {
        $tokenField = self::tokenField();
        $statusJson = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        $runnerUrl = '../modules/addons/whmcsupdater/update_runner.php';
        $statusToken = (string) ($status['status_token'] ?? '');
        $recoveryUrl = $runnerUrl . '?action=ui&token=' . rawurlencode($statusToken);
        $interrupted = !empty($status['interrupted']) || (($status['status'] ?? '') === 'interrupted');

        $html = [];
        $html[] = '<div class="pp-card" id="pp-manual-update">';
        $html[] = '<h3>' . self::e(Lang::get($lang, 'section_manual_update')) . '</h3>';
        $html[] = '<p class="pp-help">' . self::e(Lang::get($lang, 'mu_intro')) . '</p>';

        $html[] = '<div class="pp-mu-meta">';
        $html[] = '<div><strong>' . self::e(Lang::get($lang, 'mu_installed')) . ':</strong> <span dir="ltr" id="pp-mu-installed">' . self::e((string) ($status['installed'] ?? '')) . '</span></div>';
        $html[] = '<div><strong>' . self::e(Lang::get($lang, 'mu_target')) . ':</strong> <span dir="ltr" id="pp-mu-target">' . self::e(isset($status['release']['full_version']) ? (string) $status['release']['full_version'] : '—') . '</span></div>';
        $html[] = '<div><strong>' . self::e(Lang::get($lang, 'mu_phase')) . ':</strong> <span dir="ltr" id="pp-mu-phase">' . self::e((string) ($status['phase'] ?? 'idle')) . '</span></div>';
        $html[] = '<div><strong>' . self::e(Lang::get($lang, 'mu_status')) . ':</strong> <span dir="ltr" id="pp-mu-status">' . self::e((string) ($status['status'] ?? 'idle')) . '</span></div>';
        $html[] = '</div>';

        $html[] = '<div class="pp-mu-progress-wrap">';
        $html[] = '<div class="pp-mu-progress"><div id="pp-mu-bar" style="width:' . (float) ($status['percent'] ?? 0) . '%;"></div></div>';
        $html[] = '<div class="pp-mu-progress-label"><span id="pp-mu-percent">' . self::e((string) ($status['percent'] ?? 0)) . '%</span> · <span dir="ltr" id="pp-mu-bytes">' . self::e(self::formatBytes((int) ($status['bytes'] ?? 0))) . ' / ' . self::e(self::formatBytes((int) ($status['total'] ?? 0))) . '</span></div>';
        $html[] = '</div>';

        $html[] = '<p id="pp-mu-message" class="pp-help">' . self::e((string) ($status['message'] ?? '')) . '</p>';
        $html[] = '<div id="pp-mu-error" class="pp-alert pp-error" style="' . (!empty($status['error']) ? '' : 'display:none;') . '">' . self::e((string) ($status['error'] ?? '')) . '</div>';
        $blockedVisible = !empty($status['blocked']) && is_array($status['blocked']);
        $html[] = '<div id="pp-mu-blocked" class="pp-alert pp-warn" style="' . ($blockedVisible ? '' : 'display:none;') . '">'
            . self::e(self::formatBlockedNotice($lang, is_array($status['blocked'] ?? null) ? $status['blocked'] : null, $status))
            . '</div>';
        $majorVisible = !empty($status['major_upgrade']);
        $html[] = '<div id="pp-mu-major" class="pp-alert pp-warn" style="' . ($majorVisible ? '' : 'display:none;') . '">'
            . self::e(Lang::get($lang, 'mu_major_upgrade_warn'))
            . '</div>';
        $html[] = '<div id="pp-mu-manual" class="pp-alert pp-warn" style="display:none;"></div>';
        $html[] = '<div id="pp-mu-interrupted" class="pp-alert pp-warn" style="' . ($interrupted ? '' : 'display:none;') . '">' . self::e(Lang::get($lang, 'mu_interrupted')) . '</div>';

        $html[] = '<div class="pp-actions pp-mu-actions">';
        $html[] = '<button type="button" class="btn btn-default" id="pp-mu-btn-check">' . self::e(Lang::get($lang, 'mu_btn_check')) . '</button>';
        $html[] = '<button type="button" class="btn btn-primary" id="pp-mu-btn-download">' . self::e(Lang::get($lang, 'mu_btn_download')) . '</button>';
        $html[] = '<button type="button" class="btn btn-success" id="pp-mu-btn-apply">' . self::e(Lang::get($lang, $interrupted ? 'mu_btn_resume' : 'mu_btn_apply')) . '</button>';
        $html[] = '<button type="button" class="btn btn-warning" id="pp-mu-btn-finalize" style="display:none;">' . self::e(Lang::get($lang, 'mu_btn_finalize')) . '</button>';
        $html[] = '<button type="button" class="btn btn-danger" id="pp-mu-btn-rollback">' . self::e(Lang::get($lang, 'mu_btn_rollback')) . '</button>';
        $html[] = '<button type="button" class="btn btn-default" id="pp-mu-btn-clear">' . self::e(Lang::get($lang, 'mu_btn_clear')) . '</button>';
        $html[] = '</div>';

        $html[] = '<div class="pp-mu-options">';
        $html[] = '<label><input type="checkbox" id="pp-mu-confirm" value="1" /> ' . self::e(Lang::get($lang, 'mu_confirm_label')) . '</label>';
        $html[] = '<label><input type="checkbox" id="pp-mu-direct" value="1" /> ' . self::e(Lang::get($lang, 'mu_direct_label')) . '</label>';
        $html[] = '<label><input type="checkbox" id="pp-mu-no-backup" value="1" /> ' . self::e(Lang::get($lang, 'mu_no_backup_label')) . '</label>';
        $html[] = '</div>';

        $html[] = '<p class="pp-help" id="pp-mu-recovery-wrap" style="' . ($statusToken !== '' ? '' : 'display:none;') . '">'
            . self::e(Lang::get($lang, 'mu_recovery_link'))
            . ' <a dir="ltr" id="pp-mu-recovery" href="' . self::e($recoveryUrl) . '" target="_blank" rel="noopener">' . self::e($recoveryUrl) . '</a></p>';
        $html[] = '<p class="pp-help" dir="ltr">' . self::e(Lang::get($lang, 'mu_cli_hint')) . ' <code>php modules/addons/whmcsupdater/bin/update.php apply --yes</code></p>';

        $html[] = '<div style="display:none;" id="pp-mu-token-wrap">' . $tokenField . '</div>';
        $html[] = '<script type="application/json" id="pp-mu-initial">' . $statusJson . '</script>';
        $html[] = self::manualUpdateScript($moduleLink, $runnerUrl, $lang);
        $html[] = '</div>';

        return implode("\n", $html);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }

    /**
     * @param array<string,mixed> $lang
     * @param array<string,mixed>|null $blocked
     * @param array<string,mixed> $status
     */
    private static function formatBlockedNotice(array $lang, ?array $blocked, array $status): string
    {
        if ($blocked === null) {
            return '';
        }

        $newer = (string) ($blocked['full_version'] ?? $blocked['version'] ?? '');
        $target = isset($status['release']['full_version']) ? (string) $status['release']['full_version'] : '';
        $phpReq = (string) ($blocked['php_constraint'] ?? '');
        $ionReq = (string) ($blocked['ioncube_required'] ?? '');
        $phpCur = (string) ($blocked['php_current'] ?? PHP_VERSION);
        $ionCur = (string) ($blocked['ioncube_current'] ?? '');

        $template = Lang::get($lang, 'mu_blocked_path');
        $msg = str_replace(
            ['{newer}', '{target}', '{php_req}', '{ion_req}', '{php_cur}', '{ion_cur}'],
            [
                $newer,
                $target !== '' ? $target : '—',
                $phpReq !== '' ? $phpReq : '—',
                $ionReq !== '' ? $ionReq : '—',
                $phpCur,
                $ionCur !== '' ? $ionCur : 'n/a',
            ],
            $template
        );

        return $msg;
    }

    /**
     * @param array $lang
     */
    private static function manualUpdateScript(string $moduleLink, string $runnerUrl, array $lang): string
    {
        $confirmMsg = json_encode(Lang::get($lang, 'mu_confirm_apply'), JSON_UNESCAPED_UNICODE);
        $confirmRollback = json_encode(Lang::get($lang, 'mu_confirm_rollback'), JSON_UNESCAPED_UNICODE);
        $manualMsg = json_encode(Lang::get($lang, 'mu_manual_installer'), JSON_UNESCAPED_UNICODE);
        $resumeLabel = json_encode(Lang::get($lang, 'mu_btn_resume'), JSON_UNESCAPED_UNICODE);
        $applyLabel = json_encode(Lang::get($lang, 'mu_btn_apply'), JSON_UNESCAPED_UNICODE);
        $blockedTpl = json_encode(Lang::get($lang, 'mu_blocked_path'), JSON_UNESCAPED_UNICODE);
        $majorWarn = json_encode(Lang::get($lang, 'mu_major_upgrade_warn'), JSON_UNESCAPED_UNICODE);
        $moduleLinkJs = json_encode($moduleLink, JSON_UNESCAPED_SLASHES);
        $runnerUrlJs = json_encode($runnerUrl, JSON_UNESCAPED_SLASHES);

        return <<<JS
<script>
(function () {
  var moduleLink = {$moduleLinkJs};
  var runnerUrl = {$runnerUrlJs};
  var confirmMsg = {$confirmMsg};
  var confirmRollback = {$confirmRollback};
  var manualMsg = {$manualMsg};
  var resumeLabel = {$resumeLabel};
  var applyLabel = {$applyLabel};
  var blockedTpl = {$blockedTpl};
  var majorWarn = {$majorWarn};
  var busy = false;
  var applyToken = null;

  function tokenValue() {
    var el = document.querySelector('#pp-mu-token-wrap input[name="token"]');
    return el ? el.value : '';
  }

  function fmtBytes(n) {
    n = parseInt(n, 10) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
    return (n / 1073741824).toFixed(2) + ' GB';
  }

  function applyState(state) {
    if (!state) return;
    var set = function (id, val) {
      var el = document.getElementById(id);
      if (el) el.textContent = val == null ? '' : String(val);
    };
    set('pp-mu-installed', state.installed || '');
    set('pp-mu-target', (state.release && state.release.full_version) ? state.release.full_version : '—');
    set('pp-mu-phase', state.phase || 'idle');
    set('pp-mu-status', state.status || 'idle');
    set('pp-mu-message', state.message || '');
    set('pp-mu-percent', (state.percent != null ? state.percent : 0) + '%');
    set('pp-mu-bytes', fmtBytes(state.bytes) + ' / ' + fmtBytes(state.total));
    var bar = document.getElementById('pp-mu-bar');
    if (bar) bar.style.width = (state.percent || 0) + '%';
    var err = document.getElementById('pp-mu-error');
    if (err) {
      if (state.error) { err.style.display = ''; err.textContent = state.error; }
      else { err.style.display = 'none'; err.textContent = ''; }
    }
    var blockedEl = document.getElementById('pp-mu-blocked');
    if (blockedEl) {
      if (state.blocked) {
        var b = state.blocked;
        var txt = String(blockedTpl || '')
          .replace('{newer}', b.full_version || b.version || '')
          .replace('{target}', (state.release && state.release.full_version) ? state.release.full_version : '—')
          .replace('{php_req}', b.php_constraint || '—')
          .replace('{ion_req}', b.ioncube_required || '—')
          .replace('{php_cur}', b.php_current || '')
          .replace('{ion_cur}', b.ioncube_current || 'n/a');
        blockedEl.style.display = '';
        blockedEl.textContent = txt;
      } else {
        blockedEl.style.display = 'none';
        blockedEl.textContent = '';
      }
    }
    var majorEl = document.getElementById('pp-mu-major');
    if (majorEl) {
      if (state.major_upgrade) {
        majorEl.style.display = '';
        majorEl.textContent = majorWarn;
      } else {
        majorEl.style.display = 'none';
      }
    }
    var manual = document.getElementById('pp-mu-manual');
    var fin = document.getElementById('pp-mu-btn-finalize');
    if (state.needs_manual_installer || state.status === 'awaiting_manual') {
      if (manual) { manual.style.display = ''; manual.textContent = manualMsg; }
      if (fin) fin.style.display = '';
    } else {
      if (manual) manual.style.display = 'none';
      if (fin) fin.style.display = 'none';
    }
    var interrupted = document.getElementById('pp-mu-interrupted');
    var applyBtn = document.getElementById('pp-mu-btn-apply');
    if (state.interrupted || state.status === 'interrupted') {
      if (interrupted) interrupted.style.display = '';
      if (applyBtn) applyBtn.textContent = resumeLabel;
    } else {
      if (interrupted) interrupted.style.display = 'none';
      if (applyBtn && state.status !== 'running') applyBtn.textContent = applyLabel;
    }
    if (state.status_token) {
      applyToken = state.status_token;
      var wrap = document.getElementById('pp-mu-recovery-wrap');
      var link = document.getElementById('pp-mu-recovery');
      var href = runnerUrl + '?action=ui&token=' + encodeURIComponent(state.status_token);
      if (wrap) wrap.style.display = '';
      if (link) { link.href = href; link.textContent = href; }
    }
  }

  function post(action, fields) {
    var body = new FormData();
    body.append('pp_action', action);
    body.append('token', tokenValue());
    if (fields) {
      Object.keys(fields).forEach(function (k) {
        if (fields[k] !== undefined && fields[k] !== null && fields[k] !== false) {
          body.append(k, fields[k] === true ? '1' : String(fields[k]));
        }
      });
    }
    return fetch(moduleLink, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); });
  }

  function runner(action, fields) {
    var body = new FormData();
    body.append('action', action);
    body.append('token', applyToken || '');
    if (fields) {
      Object.keys(fields).forEach(function (k) {
        body.append(k, String(fields[k]));
      });
    }
    return fetch(runnerUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (r) { return r.json(); });
  }

  function setBusy(v) {
    busy = !!v;
    ['check','download','apply','finalize','rollback','clear'].forEach(function (name) {
      var btn = document.getElementById('pp-mu-btn-' + name);
      if (btn) btn.disabled = busy && name !== 'clear';
    });
  }

  function loopDownload() {
    if (busy) return;
    setBusy(true);
    var direct = document.getElementById('pp-mu-direct');
    post('mu_download', {
      budget: 20,
      direct: direct && direct.checked
    }).then(function (res) {
      if (res.state) applyState(res.state);
      if (res.ok && res.done) { setBusy(false); return; }
      if (!res.ok) { setBusy(false); return; }
      setBusy(false);
      setTimeout(loopDownload, 400);
    }).catch(function (e) {
      setBusy(false);
      var err = document.getElementById('pp-mu-error');
      if (err) { err.style.display = ''; err.textContent = String(e); }
    });
  }

  function loopApplySteps() {
    if (!applyToken) { setBusy(false); return; }
    runner('step', {budget: 12}).then(function (res) {
      if (res.state) applyState(res.state);
      if (res.needs_manual_installer || (res.state && res.state.status === 'awaiting_manual')) {
        setBusy(false);
        return;
      }
      if (!res.ok) { setBusy(false); return; }
      if (res.done) { setBusy(false); return; }
      setTimeout(loopApplySteps, 350);
    }).catch(function (e) {
      // Admin may be offline; keep stepping via runner.
      setTimeout(loopApplySteps, 1500);
    });
  }

  document.getElementById('pp-mu-btn-check').addEventListener('click', function () {
    if (busy) return;
    setBusy(true);
    post('mu_check', {}).then(function (res) {
      if (res.state) applyState(res.state);
      else if (res.release) applyState(Object.assign({phase:'catalog',status:res.ok?'ready':'error'}, res));
      setBusy(false);
    }).catch(function (e) { setBusy(false); alert(e); });
  });

  document.getElementById('pp-mu-btn-download').addEventListener('click', function () {
    loopDownload();
  });

  document.getElementById('pp-mu-btn-apply').addEventListener('click', function () {
    if (busy) return;
    var confirmBox = document.getElementById('pp-mu-confirm');
    if (!confirmBox || !confirmBox.checked) {
      alert(confirmMsg);
      return;
    }
    if (!window.confirm(confirmMsg)) return;
    setBusy(true);
    var noBackup = document.getElementById('pp-mu-no-backup');
    post('mu_apply', {
      confirm: 1,
      no_backup: noBackup && noBackup.checked
    }).then(function (res) {
      if (res.state) applyState(res.state);
      if (res.ok && res.state && res.state.status_token) {
        applyToken = res.state.status_token;
        loopApplySteps();
        return;
      }
      setBusy(false);
    }).catch(function (e) {
      var initial = {};
      try { initial = JSON.parse(document.getElementById('pp-mu-initial').textContent || '{}'); } catch (err) {}
      applyToken = (initial && initial.status_token) || applyToken;
      if (applyToken) loopApplySteps();
      else setBusy(false);
    });
  });

  document.getElementById('pp-mu-btn-finalize').addEventListener('click', function () {
    if (busy) return;
    setBusy(true);
    if (applyToken) {
      runner('continue_manual', {budget: 12}).then(function (res) {
        if (res.state) applyState(res.state);
        if (res.ok && !res.done) { loopApplySteps(); return; }
        setBusy(false);
      }).catch(function () { setBusy(false); });
      return;
    }
    post('mu_status', {}).then(function (res) {
      var token = (res.state && res.state.status_token) || '';
      applyToken = token;
      return post('mu_finalize', { token: token });
    }).then(function (res) {
      if (res.state) applyState(res.state);
      setBusy(false);
    }).catch(function () { setBusy(false); });
  });

  document.getElementById('pp-mu-btn-rollback').addEventListener('click', function () {
    if (busy) return;
    if (!window.confirm(confirmRollback)) return;
    setBusy(true);
    post('mu_rollback', { confirm: 1 }).then(function (res) {
      if (res.state) applyState(res.state);
      setBusy(false);
    }).catch(function (e) { setBusy(false); alert(e); });
  });

  document.getElementById('pp-mu-btn-clear').addEventListener('click', function () {
    post('mu_clear', {}).then(function (res) {
      if (res.state) applyState(res.state);
    });
  });

  try {
    var initial = JSON.parse(document.getElementById('pp-mu-initial').textContent || '{}');
    applyState(initial);
    if (initial && initial.status_token) applyToken = initial.status_token;
  } catch (e) {}
})();
</script>
JS;
    }

    /**
     * @return array{messages:array,errors:array,edit_id?:int}
     */
    /**
     * @return array{messages:array,errors:array,edit_id?:int}
     */
    private static function handlePost(string $action, array $lang): array
    {
        $messages = [];
        $errors = [];
        $repo = new ProxyRepository();
        $selector = Settings::makeSelector();

        switch ($action) {
            case 'save':
                $validated = $repo->validateFields($_POST);
                if (!$validated['ok']) {
                    $errors[] = sprintf(
                        Lang::get($lang, 'msg_validation'),
                        self::errorLabel($lang, $validated['error'] ?? 'invalid_url')
                    );
                    break;
                }
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    if (!$repo->find($id)) {
                        $errors[] = Lang::get($lang, 'msg_not_found');
                        break;
                    }
                    $repo->update($id, $validated['data']);
                    $messages[] = Lang::get($lang, 'msg_updated');
                } else {
                    $repo->create($validated['data']);
                    $messages[] = Lang::get($lang, 'msg_created');
                }
                $selector->invalidateCache();
                break;

            case 'delete':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0 || !$repo->delete($id)) {
                    $errors[] = Lang::get($lang, 'msg_not_found');
                } else {
                    $messages[] = Lang::get($lang, 'msg_deleted');
                    $selector->invalidateCache();
                }
                break;

            case 'enable':
            case 'disable':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0 || !$repo->find($id)) {
                    $errors[] = Lang::get($lang, 'msg_not_found');
                    break;
                }
                $repo->setEnabled($id, $action === 'enable');
                $messages[] = Lang::get($lang, $action === 'enable' ? 'msg_enabled' : 'msg_disabled');
                $selector->invalidateCache();
                break;

            case 'test':
                $id = (int) ($_POST['id'] ?? 0);
                $outcome = $selector->checkOne($id);
                if ($outcome['error'] === 'not_found' || !$outcome['result']) {
                    $errors[] = Lang::get($lang, 'msg_not_found');
                    break;
                }
                $result = $outcome['result'];
                if ($result['ok']) {
                    $messages[] = sprintf(
                        Lang::get($lang, 'msg_tested_ok'),
                        (int) $result['latency_ms'],
                        (int) $result['http_code']
                    );
                } else {
                    $errors[] = sprintf(
                        Lang::get($lang, 'msg_tested_fail'),
                        $result['error'] !== '' ? $result['error'] : 'unknown'
                    );
                }
                break;

            case 'test_all':
                $results = $selector->checkAll();
                $healthy = 0;
                foreach ($results as $row) {
                    if (!empty($row['result']['ok'])) {
                        $healthy++;
                    }
                }
                $messages[] = sprintf(
                    Lang::get($lang, 'msg_tested_all'),
                    count($results),
                    $healthy
                );
                break;

            case 'import':
                $raw = (string) ($_POST['import_list'] ?? '');
                $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
                $imported = 0;
                $skipped = 0;
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    $parsed = $repo->parseUrl($line);
                    if (!$parsed['ok']) {
                        $skipped++;
                        continue;
                    }
                    $repo->create($parsed['data']);
                    $imported++;
                }
                $messages[] = sprintf(
                    Lang::get($lang, 'msg_imported'),
                    $imported,
                    $skipped
                );
                if ($imported > 0) {
                    $selector->invalidateCache();
                }
                break;

            case 'clear_cache':
                $selector->invalidateCache();
                $messages[] = Lang::get($lang, 'msg_cache_cleared');
                break;

            default:
                break;
        }

        return [
            'messages' => $messages,
            'errors' => $errors,
        ];
    }

    private static function renderStatus(array $lang, $cached, ProxyRepository $repo, ProxyChecker $checker): string
    {
        $html = [];
        $html[] = '<div class="pp-card">';
        $html[] = '<h3>' . self::e(Lang::get($lang, 'section_status')) . '</h3>';
        $html[] = '<ul class="pp-status">';
        $html[] = '<li><strong>' . self::e(Lang::get($lang, 'info_curl_version')) . ':</strong> <span dir="ltr">' . self::e($checker->curlVersion()) . '</span></li>';
        $html[] = '<li><strong>' . self::e(Lang::get($lang, 'info_apply_mode')) . ':</strong> <span dir="ltr">' . self::e(Settings::get('apply_mode')) . '</span></li>';
        $html[] = '<li><strong>' . self::e(Lang::get($lang, 'info_strategy')) . ':</strong> <span dir="ltr">' . self::e(Settings::get('selection_strategy')) . '</span></li>';
        $html[] = '<li><strong>' . self::e(Lang::get($lang, 'info_fallback')) . ':</strong> <span dir="ltr">' . self::e(Settings::get('fallback_behavior')) . '</span></li>';
        $html[] = '<li><strong>' . self::e(Lang::get($lang, 'info_health_url')) . ':</strong> <span dir="ltr">' . self::e(Settings::get('health_check_url')) . '</span></li>';

        if ($cached) {
            $html[] = '<li><strong>' . self::e(Lang::get($lang, 'info_current_cache')) . ':</strong> <span dir="ltr">' . self::e($repo->displayLabel($cached)) . '</span></li>';
        } else {
            $html[] = '<li>' . self::e(Lang::get($lang, 'info_no_cache')) . '</li>';
        }
        $html[] = '</ul>';

        $html[] = '<form method="post" class="pp-inline">';
        $html[] = self::tokenField();
        $html[] = '<input type="hidden" name="pp_action" value="clear_cache" />';
        $html[] = '<button type="submit" class="btn btn-default">' . self::e(Lang::get($lang, 'btn_clear_cache')) . '</button>';
        $html[] = '</form>';
        $html[] = '</div>';

        return implode("\n", $html);
    }

    private static function renderForm(array $lang, string $moduleLink, $editProxy): string
    {
        $isEdit = is_object($editProxy);
        $values = [
            'id' => $isEdit ? (int) $editProxy->id : 0,
            'label' => $isEdit ? (string) $editProxy->label : '',
            'scheme' => $isEdit ? (string) $editProxy->scheme : 'socks5',
            'host' => $isEdit ? (string) $editProxy->host : '',
            'port' => $isEdit ? (string) $editProxy->port : '',
            'username' => $isEdit ? (string) ($editProxy->username ?? '') : '',
            'password' => '',
            'priority' => $isEdit ? (string) $editProxy->priority : '100',
            'enabled' => $isEdit ? (int) $editProxy->enabled === 1 : true,
        ];

        $html = [];
        $html[] = '<div class="pp-card">';
        $html[] = '<h3>' . self::e(Lang::get($lang, 'section_add_edit')) . '</h3>';
        $html[] = '<form method="post" action="' . self::e($moduleLink) . '" class="pp-form">';
        $html[] = self::tokenField();
        $html[] = '<input type="hidden" name="pp_action" value="save" />';
        $html[] = '<input type="hidden" name="id" value="' . (int) $values['id'] . '" />';

        $html[] = '<div class="pp-grid">';
        $html[] = self::fieldText($lang, 'label', $values['label'], 'field_label', 'field_label_help');
        $html[] = self::fieldSelect($lang, 'scheme', $values['scheme'], ProxyRepository::SCHEMES, 'field_scheme');
        $html[] = self::fieldText($lang, 'host', $values['host'], 'field_host', null, true);
        $html[] = self::fieldText($lang, 'port', $values['port'], 'field_port', null, true);
        $html[] = self::fieldText($lang, 'username', $values['username'], 'field_username');
        $html[] = self::fieldPassword($lang, 'password', 'field_password', $isEdit ? 'field_password_help' : null);
        $html[] = self::fieldText($lang, 'priority', $values['priority'], 'field_priority', 'field_priority_help');
        $html[] = '<div class="pp-field"><label><input type="checkbox" name="enabled" value="1"' . ($values['enabled'] ? ' checked' : '') . ' /> ' . self::e(Lang::get($lang, 'field_enabled')) . '</label></div>';
        $html[] = '</div>';

        $html[] = '<div class="pp-actions">';
        $html[] = '<button type="submit" class="btn btn-primary">' . self::e(Lang::get($lang, 'btn_save')) . '</button>';
        if ($isEdit) {
            $html[] = '<a class="btn btn-default" href="' . self::e($moduleLink) . '">' . self::e(Lang::get($lang, 'btn_cancel')) . '</a>';
        }
        $html[] = '</div>';
        $html[] = '</form>';
        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * @param array<int, object> $proxies
     */
    private static function renderList(array $lang, string $moduleLink, array $proxies): string
    {
        $html = [];
        $html[] = '<div class="pp-card">';
        $html[] = '<div class="pp-card-head">';
        $html[] = '<h3>' . self::e(Lang::get($lang, 'section_list')) . '</h3>';
        $html[] = '<form method="post" class="pp-inline">';
        $html[] = self::tokenField();
        $html[] = '<input type="hidden" name="pp_action" value="test_all" />';
        $html[] = '<button type="submit" class="btn btn-default">' . self::e(Lang::get($lang, 'btn_test_all')) . '</button>';
        $html[] = '</form>';
        $html[] = '</div>';

        if ($proxies === []) {
            $html[] = '<p>' . self::e(Lang::get($lang, 'msg_none')) . '</p>';
            $html[] = '</div>';
            return implode("\n", $html);
        }

        $html[] = '<div class="table-responsive"><table class="datatable pp-table" width="100%">';
        $html[] = '<thead><tr>';
        foreach (['col_id', 'col_label', 'col_scheme', 'col_host', 'col_port', 'col_priority', 'col_enabled', 'col_status', 'col_latency', 'col_fail_count', 'col_last_checked', 'col_actions'] as $col) {
            $html[] = '<th>' . self::e(Lang::get($lang, $col)) . '</th>';
        }
        $html[] = '</tr></thead><tbody>';

        foreach ($proxies as $proxy) {
            $statusKey = 'status_' . (($proxy->last_status === 'healthy' || $proxy->last_status === 'failed') ? $proxy->last_status : 'unknown');
            $statusClass = $proxy->last_status === 'healthy' ? 'pp-ok-text' : ($proxy->last_status === 'failed' ? 'pp-error-text' : '');
            $latency = $proxy->last_latency_ms !== null
                ? ((int) $proxy->last_latency_ms) . ' ' . Lang::get($lang, 'ms')
                : '—';

            $html[] = '<tr>';
            $html[] = '<td>' . (int) $proxy->id . '</td>';
            $html[] = '<td>' . self::e((string) $proxy->label) . '</td>';
            $html[] = '<td dir="ltr">' . self::e((string) $proxy->scheme) . '</td>';
            $html[] = '<td dir="ltr">' . self::e((string) $proxy->host) . '</td>';
            $html[] = '<td dir="ltr">' . (int) $proxy->port . '</td>';
            $html[] = '<td>' . (int) $proxy->priority . '</td>';
            $html[] = '<td>' . self::e(Lang::get($lang, ((int) $proxy->enabled) ? 'status_enabled' : 'status_disabled')) . '</td>';
            $html[] = '<td class="' . $statusClass . '">' . self::e(Lang::get($lang, $statusKey)) . '</td>';
            $html[] = '<td dir="ltr">' . self::e($latency) . '</td>';
            $html[] = '<td>' . (int) $proxy->fail_count . '</td>';
            $html[] = '<td dir="ltr">' . self::e((string) ($proxy->last_checked_at ?: '—')) . '</td>';
            $html[] = '<td class="pp-row-actions">';

            $html[] = '<a class="btn btn-xs btn-default" href="' . self::e($moduleLink . '&pp_action=edit&id=' . (int) $proxy->id) . '">' . self::e(Lang::get($lang, 'btn_edit')) . '</a>';

            $html[] = self::actionForm($moduleLink, 'test', (int) $proxy->id, Lang::get($lang, 'btn_test'), 'btn-info');

            if ((int) $proxy->enabled) {
                $html[] = self::actionForm($moduleLink, 'disable', (int) $proxy->id, Lang::get($lang, 'btn_disable'), 'btn-warning');
            } else {
                $html[] = self::actionForm($moduleLink, 'enable', (int) $proxy->id, Lang::get($lang, 'btn_enable'), 'btn-success');
            }

            $html[] = self::actionForm(
                $moduleLink,
                'delete',
                (int) $proxy->id,
                Lang::get($lang, 'btn_delete'),
                'btn-danger',
                Lang::get($lang, 'confirm_delete')
            );

            $html[] = '</td></tr>';
        }

        $html[] = '</tbody></table></div></div>';

        return implode("\n", $html);
    }

    private static function renderImport(array $lang, string $moduleLink): string
    {
        $html = [];
        $html[] = '<div class="pp-card">';
        $html[] = '<h3>' . self::e(Lang::get($lang, 'section_import')) . '</h3>';
        $html[] = '<form method="post" action="' . self::e($moduleLink) . '">';
        $html[] = self::tokenField();
        $html[] = '<input type="hidden" name="pp_action" value="import" />';
        $html[] = '<label>' . self::e(Lang::get($lang, 'field_import')) . '</label>';
        $html[] = '<p class="pp-help">' . self::e(Lang::get($lang, 'field_import_help')) . '</p>';
        $html[] = '<textarea name="import_list" rows="6" class="form-control" dir="ltr" placeholder="socks5://user:pass@1.2.3.4:1080&#10;http://proxy.example.com:8080"></textarea>';
        $html[] = '<div class="pp-actions"><button type="submit" class="btn btn-primary">' . self::e(Lang::get($lang, 'btn_import')) . '</button></div>';
        $html[] = '</form></div>';

        return implode("\n", $html);
    }

    /**
     * @param array<int, object> $logs
     */
    private static function renderLogs(array $lang, array $logs): string
    {
        $html = [];
        $html[] = '<div class="pp-card">';
        $html[] = '<h3>' . self::e(Lang::get($lang, 'section_logs')) . '</h3>';

        if ($logs === []) {
            $html[] = '<p>' . self::e(Lang::get($lang, 'msg_no_logs')) . '</p></div>';
            return implode("\n", $html);
        }

        $html[] = '<div class="table-responsive"><table class="datatable pp-table" width="100%">';
        $html[] = '<thead><tr>';
        foreach (['col_time', 'col_proxy', 'col_context', 'col_result', 'col_message'] as $col) {
            $html[] = '<th>' . self::e(Lang::get($lang, $col)) . '</th>';
        }
        $html[] = '</tr></thead><tbody>';

        foreach ($logs as $log) {
            $html[] = '<tr>';
            $html[] = '<td dir="ltr">' . self::e((string) $log->created_at) . '</td>';
            $html[] = '<td dir="ltr">' . self::e((string) ($log->proxy_label ?: '—')) . '</td>';
            $html[] = '<td dir="ltr">' . self::e((string) $log->context) . '</td>';
            $html[] = '<td dir="ltr">' . self::e((string) $log->result) . '</td>';
            $html[] = '<td>' . self::e((string) ($log->message ?: '')) . '</td>';
            $html[] = '</tr>';
        }

        $html[] = '</tbody></table></div></div>';

        return implode("\n", $html);
    }

    private static function actionForm(string $moduleLink, string $action, int $id, string $label, string $btnClass, ?string $confirm = null): string
    {
        $onsubmit = '';
        if ($confirm !== null && $confirm !== '') {
            $onsubmit = ' onsubmit="return confirm(\'' . self::e(addslashes($confirm)) . '\');"';
        }

        return '<form method="post" action="' . self::e($moduleLink) . '" class="pp-inline"' . $onsubmit . '>'
            . self::tokenField()
            . '<input type="hidden" name="pp_action" value="' . self::e($action) . '" />'
            . '<input type="hidden" name="id" value="' . $id . '" />'
            . '<button type="submit" class="btn btn-xs ' . self::e($btnClass) . '">' . self::e($label) . '</button>'
            . '</form>';
    }

    private static function fieldText(array $lang, string $name, string $value, string $labelKey, ?string $helpKey = null, bool $ltr = false): string
    {
        $dir = $ltr || in_array($name, ['host', 'port', 'username'], true) ? ' dir="ltr"' : '';
        $html = '<div class="pp-field">';
        $html .= '<label for="pp_' . self::e($name) . '">' . self::e(Lang::get($lang, $labelKey)) . '</label>';
        $html .= '<input class="form-control" type="text" id="pp_' . self::e($name) . '" name="' . self::e($name) . '" value="' . self::e($value) . '"' . $dir . ' />';
        if ($helpKey) {
            $html .= '<p class="pp-help">' . self::e(Lang::get($lang, $helpKey)) . '</p>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function fieldPassword(array $lang, string $name, string $labelKey, ?string $helpKey = null): string
    {
        $html = '<div class="pp-field">';
        $html .= '<label for="pp_' . self::e($name) . '">' . self::e(Lang::get($lang, $labelKey)) . '</label>';
        $html .= '<input class="form-control" type="password" id="pp_' . self::e($name) . '" name="' . self::e($name) . '" value="" dir="ltr" autocomplete="new-password" />';
        if ($helpKey) {
            $html .= '<p class="pp-help">' . self::e(Lang::get($lang, $helpKey)) . '</p>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param string[] $options
     */
    private static function fieldSelect(array $lang, string $name, string $value, array $options, string $labelKey): string
    {
        $html = '<div class="pp-field">';
        $html .= '<label for="pp_' . self::e($name) . '">' . self::e(Lang::get($lang, $labelKey)) . '</label>';
        $html .= '<select class="form-control" id="pp_' . self::e($name) . '" name="' . self::e($name) . '" dir="ltr">';
        foreach ($options as $option) {
            $selected = $option === $value ? ' selected' : '';
            $html .= '<option value="' . self::e($option) . '"' . $selected . '>' . self::e($option) . '</option>';
        }
        $html .= '</select></div>';

        return $html;
    }

    private static function errorLabel(array $lang, string $code): string
    {
        $key = 'err_' . $code;
        if (isset($lang[$key])) {
            return $lang[$key];
        }

        return $code;
    }

    private static function tokenField(): string
    {
        if (function_exists('generate_token')) {
            return generate_token('form');
        }

        return '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        return <<<'CSS'
<style>
#whmcsupdater-admin .pp-card{background:#fff;border:1px solid #dde2e8;border-radius:6px;padding:16px 18px;margin:0 0 18px;}
#whmcsupdater-admin .pp-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
#whmcsupdater-admin .pp-intro{color:#556;margin-bottom:16px;}
#whmcsupdater-admin .pp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}
#whmcsupdater-admin .pp-field label{display:block;font-weight:600;margin-bottom:4px;}
#whmcsupdater-admin .pp-help{font-size:12px;color:#778;margin:4px 0 0;}
#whmcsupdater-admin .pp-actions{margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;}
#whmcsupdater-admin .pp-inline{display:inline-block;margin:0 4px 0 0;vertical-align:middle;}
#whmcsupdater-admin .pp-row-actions{white-space:nowrap;}
#whmcsupdater-admin .pp-alert{padding:10px 12px;border-radius:4px;margin:0 0 12px;}
#whmcsupdater-admin .pp-ok{background:#e8f7ee;border:1px solid #b7e0c5;color:#1d6b3a;}
#whmcsupdater-admin .pp-error{background:#fdecea;border:1px solid #f5c2c0;color:#842029;}
#whmcsupdater-admin .pp-warn{background:#fff8e6;border:1px solid #f0d58a;color:#7a5b00;}
#whmcsupdater-admin .pp-ok-text{color:#1d6b3a;font-weight:600;}
#whmcsupdater-admin .pp-error-text{color:#842029;font-weight:600;}
#whmcsupdater-admin .pp-status{margin:0 0 12px;padding-left:18px;}
#whmcsupdater-admin[dir="rtl"] .pp-status{padding-left:0;padding-right:18px;}
#whmcsupdater-admin .pp-table td,#whmcsupdater-admin .pp-table th{vertical-align:middle;}
#whmcsupdater-admin .pp-mu-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 16px;margin:0 0 12px;}
#whmcsupdater-admin .pp-mu-progress-wrap{margin:8px 0 12px;}
#whmcsupdater-admin .pp-mu-progress{height:12px;background:#e8edf2;border-radius:6px;overflow:hidden;}
#whmcsupdater-admin .pp-mu-progress > div{height:100%;background:#2f6fed;width:0;transition:width .25s ease;}
#whmcsupdater-admin .pp-mu-progress-label{font-size:12px;color:#556;margin-top:4px;}
#whmcsupdater-admin .pp-mu-options{display:flex;flex-wrap:wrap;gap:12px 18px;margin:10px 0;}
#whmcsupdater-admin .pp-mu-options label{font-weight:500;}
#whmcsupdater-admin .pp-mu-actions{margin-top:8px;}
</style>
CSS;
    }
}
