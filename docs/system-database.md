# FarosCMS System Database

FarosCMS uses a hybrid storage model:

- Markdown files remain the source of truth for content and content metadata.
- Site/theme/admin settings are stored in SQLite system metadata.
- Uploaded files remain on the filesystem.
- SQLite stores system/admin data that benefits from querying, filtering, history, and indexes.

The system database lives at `storage/db/app.sqlite`. Runtime database files are ignored by git; only `storage/db/.gitkeep` is tracked.

## Current Schema

The initial foundation migration creates:

- `schema_migrations`
- `system_meta`
- `users`
- `activity_logs`
- `email_logs`
- `notifications`
- `content_index`
- `backup_runs`

The users, settings metadata, activity logs, email logs, notifications, and backup runs tables are now wired into admin flows. The remaining tables are intentionally not fully wired into admin flows yet; they exist so the next features can build on stable storage.

## Boundaries

Do not move page/post/project content and content frontmatter into SQLite as the primary source of truth.

SQLite should be used for:

- site/theme/admin settings
- users and roles
- permission metadata
- activity logs
- email logs
- notifications
- content/search indexes rebuilt from flat files
- backup/update history
- rate-limit and operational state

If `storage/db/app.sqlite` is deleted, content remains available from Markdown files, but admin/system settings and historical system data such as logs are lost unless restored from backup.

## Settings Storage

- `system_meta.site_settings` and `system_meta.theme_settings` hold YAML documents managed from Admin `Settings`.
- The repository no longer ships `content/settings/site.yaml` or `content/settings/theme.yaml`. A fresh install starts from the code defaults.
- Installs upgraded from the YAML settings era keep their values: if a legacy `content/settings/*.yaml` file exists and the matching SQLite key does not, the file is imported once on first load.
- Settings saves fail loudly when SQLite is unavailable instead of silently discarding the change.
- Theme settings saved from the form keep any extra keys a custom theme stores in `theme_settings`.
