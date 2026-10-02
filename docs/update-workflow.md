# FarosCMS Update Workflow

How a live FarosCMS site is updated safely. Part 1 (status, backup before updating) and Part 2 (installing from the admin) are built.

## Principles

1. **Data and code are separate.** Data lives in `content/`, `public/uploads/`, `custom/`, and `storage/db/app.sqlite`. Everything else is code, including the whole `themes/default/` folder. An update replaces code only. A restore replaces data only.
2. **No update without a verified way back.** A full backup that passed checksum verification, is under 24 hours old, and was taken on the version being replaced must exist before anything changes.
3. **Stage, then swap.** New code is fully downloaded, verified, and unpacked beside the live tree before a single live file moves. The swap is a sequence of renames that can be undone in reverse.
4. **Every step is logged** to the activity log and ends with a notification, whether it succeeds or not.

## Part 1: Built Now

### Update status

- `UpdateService` reads the configured GitHub source (`Settings > Updates`): raw `VERSION`, raw `CHANGELOG.md`, and the archive URL. Private repositories use the stored GitHub token.
- The remote status is cached in `system_meta.update_status`. It is refreshed at most every 12 hours (hourly while the source is unreachable) or on demand with **Check status**.
- A new release creates one `update.available` notification per version, and the sidebar version badge turns blue.

### Pre-update backup

- `Updates > Pre-update backup > Create verified backup` takes a full snapshot with reason `pre-update`, verifies it immediately, and records it in `system_meta.pre_update_backup`.
- The preflight list reports **Verified pre-update backup: ready** only when that archive still exists, passed verification, is under 24 hours old, and matches the current version.

### Backups and restore

See [backups.md](backups.md). Every archive now carries a SHA-256 manifest, the database copy is taken with `VACUUM INTO`, and restore is verified, staged, reversible, and preceded by a safety snapshot.

### Manual update procedure (when the admin cannot write to the code)

1. Admin `Updates`: **Check status**, read the release notes.
2. **Create verified backup** and wait for "Ready".
3. Update the code with git (`git pull --ff-only`) or by unzipping the release package over the site, as described in `update.md`. Never overwrite `content/`, `public/uploads/`, `custom/`, `storage/` (the package contains none of them).
4. Open `/admin`. Pending SQLite migrations run automatically on the first request.
5. Check the homepage, one content page, `/admin`, and `/sitemap.xml`.
6. If something is wrong: restore code with git (`git checkout <previous-commit>`), and if data changed, restore the pre-update backup from `Backups`.

## Part 2: Install from the admin (built)

The Install button on Admin > Updates installs a release package with every safeguard below. The guide for people is [update.md](../update.md); this is how it works.

### Release package and manifest

`scripts/build-release.php` builds `faroscms-<version>.zip` from an allow-list (`src`, `admin`, `vendor`, `themes`, `starter`, `public/assets`, and a few single files), so a site's own data can never be in it, and the same version always builds to the same bytes. It also writes `release.json`:

```json
{
  "version": "0.2.0",
  "min_php": "8.1",
  "package": "faroscms-0.2.0.zip",
  "package_url": "https://github.com/chiotis/faroscms/releases/download/v0.2.0/faroscms-0.2.0.zip",
  "sha256": "…",
  "size": 1234567,
  "files": 1100,
  "requires_backup": true
}
```

The Release workflow runs the script when `VERSION` changes and attaches both files to the GitHub Release. The site reads `https://github.com/<repository>/releases/latest/download/release.json` (or the address in Settings > Updates), validates it (`UpdateService::validateManifest`: version, PHP, https address, SHA-256, size within 100 MB) and keeps it for as long as the update status.

### Install steps (`UpdateInstaller`)

| # | Step | Failure handling |
|---|------|------------------|
| 1 | Preflight: a newer version, PHP version, ZipArchive, code folders writable, free disk ≥ 3× the package, no update running. | Stop. Nothing changed. |
| 2 | Gate: a verified `pre-update` backup under 24 hours old, for the version being replaced (unless the manifest says `requires_backup: false`). | Stop. |
| 3 | Download to `storage/updates/<version>/package.zip`, https only (http for this machine in tests), stopping past the manifest's size. | Remove the run folder, stop. |
| 4 | Verify the size and SHA-256 against the manifest. | Remove, stop. |
| 5 | Unpack into `staged/`. Every entry must be on the allow-list (`UpdateInstaller::isAllowedPath`: no `content/`, `custom/`, `storage/`, `public/uploads/*`, no `..`, no absolute or backslash paths, no links), at most 20,000 files and 300 MB; `VERSION` must equal the manifest's, and `src/App.php`, `public/index.php`, `vendor/autoload.php` and `themes/default/theme.yaml` must exist. | Remove, stop. |
| 6 | Copy the database (`VACUUM INTO`) and note how many migrations it has. | Remove, stop. |
| 7 | Maintenance mode: `storage/maintenance.flag` with a token. Public requests get 503 with `Retry-After`; the admin stays open; a request with the token (`X-Faros-Update`) gets through. A flag older than 15 minutes is ignored. | — |
| 8 | Swap: each folder (`src`, `admin`, `vendor`, `starter`, `public/assets`, each `themes/<name>` in the package) and file (`VERSION` last) is renamed into `previous/` and the staged one renamed in. | Reverse every completed rename, remove the flag, stop. |
| 9 | Clear OPcache. Ask the site in a request of its own (`/admin/login` must answer 200, `/` must not fail, neither may show a PHP error), carrying the maintenance token. | If it answered badly: reverse step 8, put the database copy back **if the migrations changed**, remove the flag, report `rolled_back`. If it could not be reached: keep the new version, report `unverified`. |
| 10 | Remove the flag, log `updates.install_*`, notify, keep `previous/` and the database copy for the last two updates. | — |

Classes the rest of the install needs are loaded before step 8, so the running request never reads half of its code from the old version and half from the new.

### Putting the old version back

`UpdateInstaller::rollback` reverses the swap from what `report.json` and `previous/` hold. It does not restore the database (it has been used since); the backup taken before the update is the way back for data.

### Explicitly out of scope

- Updating `custom/`. It is the site owner's code; the theme's compatibility rules (`docs/theming.md`) keep it working across updates.
- Rolling back database migrations other than right after a failed start. The pre-update backup is the rollback path for data.
- Updates without a person pressing **Install**.
- Private repositories: the manifest must be reachable without a token.

### What was verified

`tests/unit/update-installer.php` (the installer with replaceable download and health check: good installs, every kind of bad package, the checks, a new version that does not start, one that changed the database, a swap that fails half way, rolling back, and the network helpers against a local server) and `tests/http/update_install_test.py` (a real package built from the code and served locally: the install button, the install, the roll back, a version that answers every request with an error being undone on its own, a package with the wrong checksum, and the maintenance page, with the site's pages, uploads, custom files and settings checked byte for byte throughout). `tests/http/fresh_install_test.py` unpacks the package into an empty folder and opens every page, so anything the package forgot shows up.
