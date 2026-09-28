# FarosCMS Backups and Restore

## Archives

Archives live in `storage/backups/` and are listed in Admin `Backups`.

| Type | Contents | Filename |
|------|----------|----------|
| Full | Every project file except `.git/`, `_reference/`, `node_modules/`, `storage/backups/`, `storage/cache/`, `storage/restore/`, `storage/updates/`, plus a consistent copy of the system database | `<site>-backup-YYYYMMDD-HHMMSS-<id>.zip` |
| Database | A consistent copy of `storage/db/app.sqlite` | `<site>-database-backup-YYYYMMDD-HHMMSS-<id>.zip` |

Every archive contains `faroscms-backup.json`:

- `type`, `reason` (`manual`, `scheduled`, `pre-update`, `pre-restore`), `created_at`, FarosCMS `version` and git `commit`
- `files`: the size and SHA-256 of every file in the archive

The database is copied with SQLite `VACUUM INTO`, so the archive holds one clean file even while WAL mode has unflushed pages. Archives are written under a temporary name and renamed when complete, so a half-written archive never appears in the list.

Archives made before manifests existed show a **No checksums** badge. They can still be restored after an explicit acknowledgement.

## Retention and remote copies

- `Settings > Backups > Keep local` limits how many archives stay in `storage/backups/` (all types together). Pre-restore safety snapshots never trigger pruning, so the archive being restored cannot be deleted by it.
- With remote storage enabled, each new archive is uploaded (streamed) to the S3-compatible bucket. Remote pruning only deletes FarosCMS archive names directly under the configured prefix; other objects in the bucket are never touched.
- A failed remote upload marks the run as a **warning**. The local archive is kept and the schedule advances.

## Verify

The shield icon in the backups list checks every file against the manifest (size and SHA-256), flags files that are missing or not listed, and rejects unsafe paths (`..`, absolute paths, backslashes).

## Restore

Restore is limited to superadmins (`backups.restore` capability).

1. Click the restore icon on an archive. The page shows the manifest, the verification result, and which areas the archive contains.
2. Choose the areas:
   - **Content**: `content/` (pages, posts, projects, forms and submissions, menus, taxonomies, media metadata)
   - **Uploads**: `public/uploads/`
   - **Theme translations**: `themes/<active theme>/lang/`
   - **System database**: `storage/db/app.sqlite` (settings, users, logs, notifications, backup history)
3. Type the archive name to confirm.

What happens next:

1. The archive is verified again. Any mismatch stops the restore.
2. A full safety snapshot of the current site is created (reason `pre-restore`).
3. The selected areas are extracted to `storage/restore/<run>/staged/`. A restored database is opened, checked with `PRAGMA integrity_check`, and must contain `system_meta`.
4. Each live area is renamed into `storage/restore/<run>/previous/` and the staged area is renamed into place. If any rename fails, every area already swapped is moved back and the half-restored copy is kept in `storage/restore/<run>/failed/`.
5. Pending migrations run on the restored database. The restore is logged there and a notification is created.

Code (`src/`, `vendor/`, `admin/`, `public/assets/`) is never restored from a backup. Use git or a release package for code.

If the restored database does not contain your user, or your user is inactive there, you are signed out on the next request.

The two most recent `storage/restore/<run>/` directories are kept for manual recovery. Delete them when you no longer need them.

## Manual recovery

If the admin itself is unusable:

1. Stop the web server or put the site in maintenance.
2. Unzip the archive somewhere else and compare `faroscms-backup.json` checksums if needed (`shasum -a 256 <file>`).
3. Copy `content/`, `public/uploads/`, `themes/<theme>/lang/`, and `storage/db/app.sqlite` into place. Remove any stale `storage/db/app.sqlite-wal` and `app.sqlite-shm` first.
4. Start the server and sign in.
