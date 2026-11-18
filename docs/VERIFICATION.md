# Live WHMCS 8.x / 9.x verification checklist

This addon cannot fully validate ionCube-encoded updater internals without a real WHMCS install. Use this checklist after deployment on both 8.x and 9.x.

## 1. Admin updater script name

Assumption in `hooks.php`: basename is one of `update.php`, `systemupdate.php`, `updater.php`, or a request with `module`/`action` containing `update`.

**Verify:**

1. Open **Utilities → Update WHMCS**.
2. Note the browser URL path (and any AJAX endpoints in DevTools → Network).
3. Confirm `whmcsupdater_is_update_admin_request()` matches those paths.
4. After **Check for Updates**, open **Addons → WHMCS Updater → Activity Log** and look for `context = admin_update` and `result = applied`.

If the script name differs on your 8.x or 9.x build, add it to `$updateScripts` in `hooks.php`.

## 2. Cron update task class names

Assumption: task class/name contains `update`, `updater`, `checkforupdates`, `systemupdate`, or `composerupdate`.

**Verify:**

```sql
SELECT id, name, class_name, is_enabled
FROM tbltask
WHERE name LIKE '%update%' OR class_name LIKE '%Update%';
```

If your WHMCS build uses a different name, extend the `$patterns` array in `whmcsupdater_is_update_task()`.

Confirm cron application via Activity Log entries with `context = cron_update`.

## 3. SOCKS5 through the internal Composer downloader

cURL and Guzzle honour `ALL_PROXY` / `https_proxy` with `socks5h://…`.

If WHMCS’s bundled Composer uses PHP streams instead of cURL for dist downloads, SOCKS5 may fail for the ZIP fetch even when the health check succeeds.

**Verify with a SOCKS5-only proxy:**

1. Disable all non-SOCKS5 proxies.
2. Run **Check for Updates** and, if offered, start an update.
3. Watch the proxy’s connection log for traffic to:
   - `releases.whmcs.com`
   - `pki.whmcs.com`
4. Check `tblupdatelog` and `mod_whmcsupdater_log`.

**If SOCKS5 fails only for the ZIP download:** add a local HTTP CONNECT → SOCKS5 bridge that listens on `127.0.0.1` during the update and point the env vars at that bridge. That bridge is intentionally out of scope until proven necessary.

## 4. Language / RTL

1. Set the admin language to **English** — UI must be fully English.
2. Set the admin language to **Farsi** — UI must be fully Persian with RTL layout; host/port/URLs remain LTR.
3. Confirm every key in `lang/farsi.php` exists in `lang/english.php` (see `tests/check_lang_keys.php`).

## 5. Proxy schemes

Test at least one of each:

| Scheme  | Expected |
|---------|----------|
| `http`  | Health check OK; updater uses proxy |
| `https` | Requires cURL ≥ 7.52 |
| `socks5`| Prefer remote DNS (`socks5h`) enabled in settings |

## 6. Manual updater (resumable + staged apply)

On a test WHMCS 8.x or 9.x install with a slow/unstable proxy:

1. Open **Addons → WHMCS Updater → Manual Update**.
2. Click **Check for Updates** — target release, size, and installed version should appear. If a newer major release is blocked by PHP/ionCube, the blocked notice must explain the path.
3. Click **Download / Resume**. Throttle or kill the proxy mid-transfer; the `.part` file under the work directory should keep growing after **Download / Resume** again (or after automatic failover to another pool proxy).
4. Confirm progress reaches 100% and the message reports SHA-256 verification success.
5. Take a DB backup. Tick the confirmation checkbox and click **Apply Update**.
6. Watch phases advance (`plan` → `extract` → `backup` → `copy` → `verify_files` → `upgrade` → `finalize`). Save the **Recovery page** link shown in the UI.
7. Optionally kill PHP mid-copy; reload the addon (or open the recovery page) and click **Resume Interrupted Update** — it must continue from the checkpoint, not rewrite `maintenance_prev.json`.
8. After completion, confirm:
   - `install/` is gone
   - `.whmcs-relocating` is gone
   - Admin Area version matches the target
   - `storage/manual_update/manual_update.log` shows the staged pipeline
9. CLI equivalent on the same host:

```bash
php modules/addons/whmcsupdater/bin/update.php status
php modules/addons/whmcsupdater/bin/update.php run --yes
# Safe during Err 2 (no WHMCS bootstrap):
php modules/addons/whmcsupdater/bin/update.php apply --yes
php modules/addons/whmcsupdater/bin/update.php finalize
```

10. If `proc_open` / PHP CLI+ionCube is unavailable, apply should leave files verified and show the manual `/install/install.php` instructions; after finishing in the browser, **Continue after Manual Installer** (or `update.php finalize`) must verify the DB version and remove `install/`.

## 7. Upgrading WHMCS 8.x to 9.x

1. On PHP 7.x / 8.1, **Check for Updates** should offer the newest compatible 8.13.x and show 9.x as blocked with PHP/ionCube requirements.
2. Apply 8.13.x, then switch web + CLI PHP to 8.2/8.3 and update ionCube.
3. **Check for Updates** again — 9.x should become the target (subject to ionCube).
4. Confirm `plan` refuses apply if web PHP (from `storage/web_php_pointer.txt`) is still incompatible.
5. If CLI PHP is incompatible but web PHP is fine, apply should copy files and fall through to the browser installer.

## 8. Finishing an interrupted legacy apply

If a previous `proxyupdater` or `proxypool` apply stopped on `copy / running`:

1. Upload `modules/addons/whmcsupdater/` beside the old module.
2. Activate **WHMCS Updater** (do not deactivate the old addon first).
3. Confirm DB backup, click **Resume Interrupted Update**.
4. After success, deactivate the old addon and delete its folder.
