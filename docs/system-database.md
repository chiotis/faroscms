# FarosCMS System Database

FarosCMS uses a hybrid storage model:

- Markdown/YAML files remain the source of truth for content.
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

The users, activity logs, email logs, notifications, and backup runs tables are now wired into admin flows. The remaining tables are intentionally not fully wired into admin flows yet; they exist so the next features can build on stable storage.

## Boundaries

Do not move page/post/project/menu/taxonomy/settings content into SQLite as the primary source of truth.

SQLite should be used for:

- users and roles
- permission metadata
- activity logs
- email logs
- notifications
- content/search indexes rebuilt from flat files
- backup/update history
- rate-limit and operational state

The CMS should continue to run from flat files even if `storage/db/app.sqlite` is deleted and recreated. Historical system data such as logs may be lost when the database is deleted unless separately backed up.
