# WHMCS Updater

Resumable WHMCS upgrades for self-hosted installs, with an optional **Proxy Pool** for Automatic Updater traffic.

Compatible with **WHMCS 8.x and 9.x** · **PHP 7.2–8.4** (match your WHMCS version) · Linux

---

## Features

### Manual update

- Resumable ZIP download with Range resume, stall detection, and proxy failover
- SHA-256 / file-size verification before any live files are touched
- Staged apply (`plan → extract → backup → copy → verify → upgrade → finalize`) in short time budgets so PHP-FPM timeouts cannot leave an unrecoverable half-copy
- Custom `admin` / `crons` path mapping from `configuration.php`
- Never overwrites `configuration.php` or `vendor/whmcs/whmcs-foundation/lib/License.php`
- Explicit file rollback from the last backup directory

### Recovery

- Standalone recovery endpoint and CLI that **do not bootstrap WHMCS** (safe during Err 2)
- One-time `status_token` auth for status / step / finalize / rollback

### Proxy Pool

- HTTP, HTTPS, and SOCKS5 (`socks5h` remote DNS) for WHMCS update traffic
- Selection strategies: fastest healthy, priority, random, round-robin
- Health checks against the WHMCS releases endpoint
- Admin UI (English + Farsi/RTL) to manage proxies, import lists, and view activity logs

---

## Requirements

| Requirement | Notes |
|-------------|--------|
| Linux | Supported runtime |
| WHMCS 8.x or 9.x | Self-hosted |
| PHP 7.2–8.4 | Must match the target WHMCS release (see catalog `require.php`) |
| PHP cURL | Required for proxy + download |
| PHP Zip | Required for manual extract/apply |
| libcurl SOCKS5 | Standard on modern builds |
| cURL ≥ 7.52 | Recommended for HTTPS proxies |
| PHP CLI + ionCube | Needed for non-interactive `installer.php -u -n` |

Database backup before apply remains **your** responsibility.

---

## Repository layout

```text
WHMCSUpdater/
├── README.md
├── LICENSE
├── .gitignore
├── docs/
│   └── VERIFICATION.md
├── tests/
└── modules/addons/whmcsupdater/   # Install this folder into WHMCS
    ├── whmcsupdater.php
    ├── hooks.php
    ├── update_runner.php
    ├── bin/update.php
    ├── lang/
    ├── lib/
    └── storage/
```

---

## Installation

1. Download a release zip from GitHub, or clone the repository.
2. Copy `modules/addons/whmcsupdater/` into your WHMCS tree:

   ```text
   <whmcs_root>/modules/addons/whmcsupdater/
   ```

3. In Admin: **System Settings → Addon Modules**
4. Click **Activate** next to **WHMCS Updater**
5. Grant access to the desired admin roles
6. Open **Configure** and set options (see below)
7. Open **Addons → WHMCS Updater**

### Publishing a GitHub release asset

```bash
cd modules/addons
zip -r whmcsupdater-1.0.zip whmcsupdater \
  -x "whmcsupdater/storage/manual_update/*" \
  -x "whmcsupdater/storage/work_dir_pointer.txt" \
  -x "whmcsupdater/storage/php_cli_pointer.txt" \
  -x "whmcsupdater/storage/web_php_pointer.txt"
```

Users install by extracting that zip into `<whmcs_root>/modules/addons/`.

### Migrating from Proxy Updater / Proxy Pool

1. Upload `whmcsupdater` **beside** the old `proxyupdater` or `proxypool` folder (do not deactivate the old addon first — that drops its tables).
2. Activate **WHMCS Updater**. Tables, addon settings, and work-directory files migrate automatically.
3. Finish any interrupted update.
4. Deactivate the old addon and delete its folder.

---

## Upgrading WHMCS 8.x to 9.x

WHMCS 9.x requires PHP 8.2+ (and a matching ionCube Loader). If your 8.x site still runs on PHP 7.x or 8.1, you cannot jump straight to 9.x.

Typical path:

1. On the current PHP version, update to the newest compatible **8.13.x** release.
2. Switch **web and CLI** PHP to 8.2 or 8.3 and install the required ionCube Loader.
3. Check for updates again and apply **9.x**.

The Manual Update UI shows when a newer release is blocked by PHP/ionCube and which compatible target you can apply now. Always back up the database and verify themes / third-party modules before a major upgrade.

---

## Configuration

| Setting | Purpose |
|---------|---------|
| Apply Mode | `update_only` (default) or `all_outbound` inside Admin |
| Selection Strategy | fastest / priority / random / round-robin |
| Health Check URL | Default: `https://releases.whmcs.com/v2/packages.json` |
| Connect Timeout | Seconds for proxy connect |
| Max Selection Attempts | How many proxies to try |
| Selected Proxy Cache TTL | Reuse last healthy proxy |
| SOCKS5 Remote DNS | Prefer `socks5h` (recommended) |
| No Healthy Proxy Behavior | Fall back to direct, or abort |
| NO_PROXY List | Hosts that bypass the proxy |
| Manual Update Work Directory | Absolute path preferred (outside web root) |
| PHP CLI Binary Path | Absolute path to CLI PHP with ionCube for installer |

---

## Proxy URL formats

```text
http://host:8080
http://user:pass@host:8080
https://host:8443
socks5://host:1080
socks5://user:pass@host:1080
socks5h://host:1080
```

Bulk import: one URL per line. Lines starting with `#` are ignored.

---

## How the proxy hook works

WHMCS does not expose an official outbound-proxy setting for the Automatic Updater, and the updater code is ionCube-encoded. This addon:

1. Loads early via `hooks.php`
2. Detects Admin “Update WHMCS” requests and update-related cron tasks
3. Selects a healthy proxy from the pool
4. Sets `http_proxy`, `https_proxy`, `CGI_HTTP_PROXY`, `all_proxy`, and `NO_PROXY` in `putenv`, `$_SERVER`, and `$_ENV` so libcurl / Guzzle / Composer honour them

See [docs/VERIFICATION.md](docs/VERIFICATION.md) to confirm script names and cron task classes on your WHMCS build.

---

## Manual update

Use **Addons → WHMCS Updater → Manual Update**, or the CLI.

### Admin UI

1. **Check for Updates** — then optionally pick a specific **8.x** or **9.x** release from the version list (or leave **Latest compatible (auto)**)
2. **Download / Resume** (safe to retry; uses `.part` + HTTP Range)
3. Confirm you have a **database backup**
4. **Apply Update** (or **Resume Interrupted Update** if a previous run was killed)
5. Keep the **Recovery page** link — it works even when Admin is offline
6. If CLI installer is unavailable: finish `/install/install.php` in the browser, then **Continue after Manual Installer**

### CLI

From the WHMCS root:

```bash
# Catalog + download (bootstraps WHMCS for proxy pool / Capsule)
php modules/addons/whmcsupdater/bin/update.php check
php modules/addons/whmcsupdater/bin/update.php download

# Apply / status / rollback do NOT bootstrap WHMCS (safe during Err 2)
php modules/addons/whmcsupdater/bin/update.php status
php modules/addons/whmcsupdater/bin/update.php apply --yes
php modules/addons/whmcsupdater/bin/update.php finalize
php modules/addons/whmcsupdater/bin/update.php rollback

# All-in-one
php modules/addons/whmcsupdater/bin/update.php run --yes
```

Useful options:

```text
--whmcs-root=/path/to/whmcs
--work-dir=/path/outside/webroot
--php=/usr/bin/php
--zip=/path/to/whmcs-release.zip
--version=9.1.1
--channel=stable|beta|rc|any
--proxy-id=N
--direct
--min-speed=1024
--no-backup
--yes
```

### Apply pipeline

```text
plan → extract → manifest → backup → maintenance
  → copy → copy_install → verify_files → upgrade → verify_db → finalize
```

Interrupted applies show as **interrupted** and can be resumed from the last checkpoint.

### Files never overwritten

| Path | Reason |
|------|--------|
| `configuration.php` | DB credentials / custom paths |
| `vendor/whmcs/whmcs-foundation/lib/License.php` | Preserve custom license implementations |

Soft-skip (create only if missing): `attachments/`, `downloads/`, `templates_c/`

---

## Standalone recovery

`modules/addons/whmcsupdater/update_runner.php` accepts a one-time `status_token` and supports:

- `action=status`
- `action=step` (advance one budgeted tick)
- `action=start`
- `action=continue_manual` / `finalize`
- `action=rollback`
- `action=ui` — simple HTML recovery page

Example:

```text
https://example.com/modules/addons/whmcsupdater/update_runner.php?action=ui&token=YOUR_TOKEN
```

---

## Languages

| Language | File | Notes |
|----------|------|--------|
| English (default) | `lang/english.php` | Source of truth for keys |
| Farsi (Persian) | `lang/farsi.php` | RTL layout; host/port/URLs stay LTR |

Missing keys fall back to English. UI follows the logged-in admin language.

---

## Security notes

- Work directory is denied via `.htaccess` (`storage/`, `storage/manual_update/`)
- Prefer an absolute **Manual Update Work Directory** outside the web root
- `update_runner.php` requires a random `status_token` issued when apply starts
- Token is cleared after a successful finalize
- Deactivating the addon drops its tables (see Uninstall)

---

## Uninstall

1. **System Settings → Addon Modules → Deactivate** WHMCS Updater
2. This drops `mod_whmcsupdater_proxies`, `mod_whmcsupdater_log`, and `mod_whmcsupdater_state`
3. Optionally delete `modules/addons/whmcsupdater/`

---

## Tests

Offline smoke tests (no live WHMCS required):

```bash
php tests/check_lang_keys.php
php tests/smoke_parse_url.php
php tests/smoke_manual_update.php
php -d extension=zip tests/smoke_update_job.php
```

Live checklist: [docs/VERIFICATION.md](docs/VERIFICATION.md)

---

## Changelog

Addon module version is set in `whmcsupdater_config()` (`modules/addons/whmcsupdater/whmcsupdater.php`) and must match the section below. Current release: **1.0**.

### 1.0 — 2026-11-24

Initial public release of **WHMCS Updater** (Linux / WHMCS 8.x & 9.x).

**Proxy Pool**

- Route Automatic Updater traffic through a managed pool of HTTP, HTTPS, and SOCKS5 (`socks5h`) proxies
- Selection strategies: fastest healthy, priority, random, round-robin
- Health checks against the WHMCS releases endpoint; connect timeout, attempt limits, and selected-proxy cache TTL
- Apply modes: update traffic only, or all outbound Admin requests
- Early hook injection of proxy env vars for libcurl / Guzzle / Composer
- Admin UI to add, enable/disable, import, health-check, and log proxy activity (English + Farsi/RTL)

**Manual update**

- Resumable ZIP download (`.part` + HTTP Range), stall detection, and automatic proxy failover
- SHA-256 / file-size verification before any live files are touched
- Staged apply pipeline with short time budgets and checkpoint resume:
  `plan → extract → manifest → backup → maintenance → copy → copy_install → verify_files → upgrade → verify_db → finalize`
- Custom `admin` / `crons` path mapping from `configuration.php`
- Never overwrites `configuration.php` or `vendor/whmcs/whmcs-foundation/lib/License.php`
- Soft-skip for `attachments/`, `downloads/`, `templates_c/` (create only if missing)
- Explicit file rollback from the last backup directory
- CLI (`bin/update.php`) and Admin UI progress / resume controls

**Recovery**

- Standalone `update_runner.php` and CLI apply paths that do not bootstrap WHMCS (safe during Err 2)
- One-time `status_token` auth for status / step / start / finalize / rollback / HTML recovery UI

**WHMCS 8.x ↔ 9.x**

- Compatible with WHMCS 8.x and 9.x; PHP/ionCube constraints taken from the release catalog
- Manual Update version picker: Latest compatible (auto), or a specific **8.x** / **9.x** release
- Incompatible targets shown disabled with an “(incompatible)” label and PHP/ionCube guidance
- Major-upgrade warnings when crossing series

**Post-update UX**

- “Check for Updates” when already current shows a green “Your system is up to date.” message (not a red error); cleared on page refresh
- After a successful update, Admin UI and Recovery UI optionally offer clearing the Smarty template cache (`templates_c` / `$templates_compiledir`); CLI still clears by default (`--keep-templates` to skip)

---

## License

MIT — see [LICENSE](LICENSE).

## Disclaimer

This project is an independent addon for self-hosted WHMCS. It is not affiliated with or endorsed by WHMCS Ltd. Use on production systems only after backups and staging tests. You are responsible for complying with WHMCS licensing and your local laws.
