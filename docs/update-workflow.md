# FarosCMS Update Workflow

This is the design for updating a live FarosCMS site safely. Part 1 is built. Part 2 (one-click install) is designed here but stays disabled in the admin until every safeguard below exists.

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

### Manual update procedure (until Part 2 ships)

1. Admin `Updates`: **Check status**, read the release notes.
2. **Create verified backup** and wait for "Ready".
3. Update the code with git (`git pull --ff-only`) or a ZIP deploy, as described in `update.md`. Never overwrite `content/`, `public/uploads/`, `custom/`, `storage/`.
4. Open `/admin`. Pending SQLite migrations run automatically on the first request.
5. Check the homepage, one content page, `/admin`, and `/sitemap.xml`.
6. If something is wrong: restore code with git (`git checkout <previous-commit>`), and if data changed, restore the pre-update backup from `Backups`.

## Part 2: One-Click Install (Designed, Not Enabled)

### Release manifest

Each release publishes `release.json` next to `VERSION`:

```json
{
  "version": "0.2.0",
  "min_php": "8.1",
  "package_url": "https://github.com/chiotis/faroscms/releases/download/v0.2.0/faroscms-0.2.0.zip",
  "sha256": "…",
  "size": 1234567,
  "requires_backup": true
}
```

The `sha256` is what makes download verification meaningful. A branch archive (`archive/refs/heads/main.zip`) changes on every push and cannot be pinned, so one-click install only accepts tagged release packages.

### Install steps

| # | Step | Failure handling |
|---|------|------------------|
| 1 | Preflight: PHP version ≥ `min_php`, writable code paths, free disk ≥ 3× package size, ZipArchive and cURL present. | Stop. Nothing changed. |
| 2 | Pre-update backup gate: a verified `pre-update` backup under 24 hours old for the current version. Offer to create one. | Stop. |
| 3 | Download the package to `storage/updates/<version>/package.zip` with a size limit. | Delete the partial file, stop. |
| 4 | Verify SHA-256 against `release.json`. | Delete, stop. |
| 5 | Extract to `storage/updates/<version>/staged/` with the same entry validation restore uses (no absolute paths, no `..`). The package must contain `VERSION` equal to the manifest version, plus `src/App.php` and `public/index.php`. | Delete staging, stop. |
| 6 | Maintenance mode: write `storage/maintenance.flag`. The frontend answers 503 with `Retry-After`; the admin stays available to the updating user. | Remove the flag, stop. |
| 7 | Swap code paths one by one (`src/`, `admin/`, `vendor/`, `public/assets/`, `public/admin-assets/`, `public/index.php`, `VERSION`, `CHANGELOG.md`, `update.md`, `themes/default/`). `custom/` is never touched. Each live path moves to `storage/updates/<version>/previous/`. | Reverse every completed rename, remove the flag, stop. |
| 8 | Clear OPcache (`opcache_reset()` when available). | Continue; report a warning. |
| 9 | Post-update checks in a fresh request: `/admin` returns 200, `/` returns 200, SQLite migrations applied, `VERSION` reports the new version. | Automatic rollback: reverse step 7, restore the database from the pre-update backup if migrations ran, remove the flag. |
| 10 | Remove the maintenance flag, log `updates.install_success`, notify, and keep `previous/` for one release as a manual rollback source. | — |

### Explicitly out of scope

- Updating `custom/`. It is the site owner's code; the theme's compatibility rules (`docs/theming.md`) keep it working across updates.
- Rolling back database migrations. The pre-update backup is the rollback path for data.
- Auto-updates without a human clicking **Install**.

### What must exist before the Install button is enabled

- [ ] `release.json` published by the release process, with SHA-256.
- [ ] Package download with size limit and checksum verification.
- [ ] Maintenance mode flag honoured by `App::handle()`.
- [ ] Code-path swap with reverse-order rollback (reuse the restore swap helpers).
- [ ] Post-update health check in a separate request, with automatic rollback.
- [ ] End-to-end test on a staging copy, including a forced failure at step 9.
