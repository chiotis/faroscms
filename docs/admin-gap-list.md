# FarosCMS Admin Gap List

This list compares the current FarosCMS implementation with:

- the prepared admin HTML template in `_reference/admin interface templates`
- the phpFlat baseline expectations from the installed `phpflat` skill

It intentionally lists gaps only. Existing FarosCMS functionality such as content editing, media/files, menus, taxonomies, settings, translations, forms, imports, exports, SMTP/SES sending, and backups is not repeated except where a missing layer remains.

## Current FarosCMS Baseline

Implemented now:

- Admin authentication with session login/logout.
- SQLite-backed users with YAML import fallback.
- Users list/edit UI with roles, status, password changes, and Google account fields.
- Role capability checks for admin modules, superadmin-only user management, self-profile editing, and forbidden responses.
- Activity log storage and `/admin/activity-logs` with level, action, actor, subject, date, and search filters.
- Email log storage and `/admin/email-logs` with provider, recipient, status, date, search, details, and clear action.
- Notification storage, topbar dropdown, unread badge, mark-read actions, and derived failed-email/backup/system notifications.
- Dashboard landing page at `/admin` with content counts, users count, backup status, recent activity, recent email attempts, storage usage, system checks, and quick actions.
- Dedicated `/admin/backups` module with full/database backup actions, downloads, delete action, policy summary, local archive list, and SQLite run history.
- Dedicated `/admin/updates` module with local `VERSION`, GitHub raw `VERSION` source, changelog, cached status (auto-refreshed every 12 hours), update-available notifications, preflight checks, and disabled install state.
- Google Sign-In settings and OAuth login flow for existing active users.
- Content list, create, edit, save, delete, import, and export.
- Page/post/project/form content types from Markdown/YAML files.
- Standalone Forms module (`/admin/forms`) with form builder fields, submissions browser, CSV export, notifications, honeypot, and rate limiting.
- Media/files library with uploads, metadata, filters, tags, bulk selection, delete, and direct URLs.
- Menus list/create/edit with language/translation handling.
- Taxonomies list/edit with YAML-backed terms.
- Settings tabs for general, theme, menus, email/SMTP/SES, backups, updates, backup remote storage, APIs placeholder, and system/raw YAML.
- Translations editor.
- Frontend routing, archives, taxonomy pages, search, sitemap, robots, language links, and theme rendering.
- Local backup snapshots integrated inside Settings.

## Gaps From The Admin Template

### High Priority

- Update install workflow: `/admin/updates` has status, notifications, and a verified pre-update backup gate. One-click install (release manifest, package checksum, staged swap, rollback) is designed in `docs/update-workflow.md` but not built.

### Medium Priority

- Files: by decision `/admin/files` stays a redirect to the media document list; the media library is the single file manager.
- Bulk actions: media, content (publish/draft/delete), and form submissions have bulk actions; users and backups intentionally do not.
- Off-canvas panels are used for log and submission details, and a shared modal handles destructive confirmations. Quick-edit panels are not built.
- Profile dropdown parity: the template has a full profile workflow; FarosCMS links to the user editor but does not yet have a separate self-profile screen.

### Lower Priority / Design-System Parity

- Components catalog: the template includes `components.html`; FarosCMS does not need this in production, but it could be kept as an internal UI reference.
- Version badge: the admin shell reads the local `VERSION` file.
- Notification archive/list page: the topbar center exists, but there is no dedicated notification history screen yet.
- Empty states consistency: many screens have empty states, but not every admin module follows the same template pattern.
- Strict icon-only action buttons everywhere: most tables use icon actions, but the pattern is not fully normalized across every admin screen.

## Gaps From The phpFlat Baseline

### Architecture / Storage

- SQLite system layer consumers: users and basic permissions are now wired into application flows; indexing, relationships, activity, search, and email logs are not yet wired.
- Rebuildable system DB: the database can be recreated, but content indexing/rebuild tooling is not implemented yet.
- `system/` directory structure: phpFlat expects `system/Controllers`, `system/Models`, `system/Core`, and `system/Views`. FarosCMS currently uses `src/`, `admin/templates/`, and Twig.
- Controller/model separation: request handlers still live in `src/App.php`; storage and services are being extracted into focused classes (see `docs/architecture.md`).
- No Composer/no framework rule: phpFlat baseline prefers no Composer and no templating engine. FarosCMS currently uses Composer-vendored Twig, Symfony YAML, and League CommonMark inherited from PicolinoCMS.

### Auth / Users / Permissions

- Role enforcement: basic `superadmin`, `admin`, and `user` capabilities are enforced in admin routes. A richer custom permission matrix is still missing.
- User CRUD: basic create/edit/deactivate/password change exists; hard delete, invitations, and richer reset flows are missing.
- Current-user profile editing: available through the user editor, but not yet as a dedicated self-profile workflow.
- Permission checks per module/action: basic checks exist for the current admin modules; finer action-level permissions and configurable policies are still missing.
- User activity metadata: missing last login, status, invite state, created/updated metadata, and audit history.

### Logs / Audit / Email

- Clear activity logs action: missing.
- Logging around sensitive actions exists for the main admin workflows, but deeper coverage for every failed branch and frontend form/mail activity is still pending.

### UI System

- Compact off-canvas editing (quick edit) is not built.
- Tables as the primary layout for all list screens: mostly true, but not uniform across all modules.
- Badge colors are centralized in `admin/templates/partials/ui.twig` (`status_badge`); a few older screens still use inline classes.

### Operational Features

- Backups have retention, remote upload/test/pruning, checksum manifests, verification, and a staged, reversible restore of data areas.
- Update install is still manual (git or ZIP). Remote release manifest, package download/checksum, staged install, and rollback are designed but not built.
- System health lives in `/admin/system` (checks, scheduled tasks, extensions, environment, content index with rebuild).
- Cache management is missing: clear/rebuild cache, cache status, cache size.

## Suggested Implementation Order

1. Build one-click install following `docs/update-workflow.md` Part 2.
2. Longer-term architecture pass: decide whether FarosCMS should stay Twig/Composer based or move closer to strict phpFlat structure.

## Notes

- Not every phpFlat baseline gap must be closed immediately. Some are architecture choices, especially Twig/Composer and `src/` versus `system/`.
- Logs should follow Users soon, because dashboard, notifications, email logs, update notices, and auditability all depend on persisted events.
