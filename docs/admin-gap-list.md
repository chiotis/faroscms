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
- Dedicated `/admin/updates` module with local `VERSION`, GitHub raw `VERSION` source, changelog, read-only status check, preflight checks, and disabled install state.
- Google Sign-In settings and OAuth login flow for existing active users.
- Content list, create, edit, save, delete, import, and export.
- Page/post/project/form content types from Markdown/YAML files.
- Form builder fields, form submissions, CSV export, notifications, honeypot, and rate limiting.
- Media/files library with uploads, metadata, filters, tags, bulk selection, delete, and direct URLs.
- Menus list/create/edit with language/translation handling.
- Taxonomies list/edit with YAML-backed terms.
- Settings tabs for general, theme, menus, email/SMTP/SES, backups, updates, backup remote storage, APIs placeholder, and system/raw YAML.
- Translations editor.
- Frontend routing, archives, taxonomy pages, search, sitemap, robots, language links, and theme rendering.
- Local backup snapshots integrated inside Settings.

## Gaps From The Admin Template

### High Priority

- Update install workflow: `/admin/updates` exists in read-only mode, but FarosCMS still has no package download, checksum verification, backup-before-update, install action, or rollback action.
- Update-derived notifications: the topbar notification center exists, but it does not yet derive notifications from update status.

### Medium Priority

- Separate Forms list template parity: FarosCMS manages forms as a content type and has form-specific edit behavior, but it does not yet have a faithful standalone `forms-list.html` experience from the template.
- Full file manager parity: FarosCMS maps `/admin/files` to the media document list. The core file behavior exists, but the URL/module identity is not a standalone Files section matching the template.
- Filter toggle parity: the template uses hidden filter panels opened by a Filter button on most list screens. Some FarosCMS screens still show filters inline or vary by module.
- Sortable table controls: template tables imply structured list workflows. FarosCMS lists are rendered in stable order, but admin-side sortable columns are not implemented broadly.
- Bulk actions: the media library has bulk selection, but content, users, logs, backups, and other list screens do not yet have template-style bulk actions.
- Modal/off-canvas detail panels: admin JS supports modals/off-canvas, but FarosCMS does not yet use them for log details, destructive confirmations, profile panels, or quick edits as the template suggests.
- Profile dropdown parity: the template has a full profile workflow; FarosCMS links to the user editor but does not yet have a separate self-profile screen.

### Lower Priority / Design-System Parity

- Components catalog: the template includes `components.html`; FarosCMS does not need this in production, but it could be kept as an internal UI reference.
- Version badge: the admin shell reads the local `VERSION` file.
- Notification archive/list page: the topbar center exists, but there is no dedicated notification history screen yet.
- Empty states consistency: many screens have empty states, but not every admin module follows the same template pattern.
- Toast placement/timing consistency: FarosCMS has shared toast support, but older modules still contain local toast markup and top-right placement.
- Strict icon-only action buttons everywhere: most tables use icon actions, but the pattern is not fully normalized across every admin screen.

## Gaps From The phpFlat Baseline

### Architecture / Storage

- SQLite system layer consumers: users and basic permissions are now wired into application flows; indexing, relationships, activity, search, and email logs are not yet wired.
- Rebuildable system DB: the database can be recreated, but content indexing/rebuild tooling is not implemented yet.
- `system/` directory structure: phpFlat expects `system/Controllers`, `system/Models`, `system/Core`, and `system/Views`. FarosCMS currently uses `src/`, `admin/templates/`, and Twig.
- Controller/model separation: FarosCMS still has most behavior in `src/App.php`, so admin modules are not split into controllers/models.
- No Composer/no framework rule: phpFlat baseline prefers no Composer and no templating engine. FarosCMS currently uses Composer-vendored Twig, Symfony YAML, and League CommonMark inherited from PicolinoCMS.
- Local compiled Tailwind only: phpFlat says no runtime CDN. FarosCMS still uses the Tailwind CDN in admin templates while also loading local CSS.

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

- Hidden-by-default filters across all lists: partially missing.
- Bottom-left toast pattern with auto-hide countdown: FarosCMS has dismissable toasts, but no visual countdown and placement is not fully unified.
- Off-canvas panels for log details and compact editing: JS support exists; product usage is missing.
- Tables as the primary layout for all list screens: mostly true, but not uniform across all modules.
- Sortable columns: missing.
- Consistent badge color logic by value across all modules: partially implemented, not centralized.

### Operational Features

- Admin-configurable backup retention, remote storage destination settings, and backup history are present, but remote upload/test/pruning and restore-from-backup are still intentionally missing until the safety flows are designed.
- Serious update workflow is missing: remote manifest fetch, package download, checksum verification, backup-before-update, install, and rollback.
- System health/status checks are partially wired for the dashboard: writable paths, PHP version, upload limit, memory limit, SQLite, mail status, storage usage, and disk free exist; cache/index status and scheduled task checks are still missing.
- Search/index admin tooling is missing: rebuild index, inspect search index, or index status.
- Cache management is missing: clear/rebuild cache, cache status, cache size.

## Suggested Implementation Order

1. Update notifications from the read-only Updates module.
2. Design backup-before-update, package verification, install, and rollback before enabling update actions.
3. Forms module: make forms feel like a standalone admin section.
4. UI consistency pass: filters, toasts, table sorting, badges, modals/off-canvas.
5. System health/cache/index tools.
6. Longer-term architecture pass: decide whether FarosCMS should stay Twig/Composer based or move closer to strict phpFlat structure.

## Notes

- Not every phpFlat baseline gap must be closed immediately. Some are architecture choices, especially Twig/Composer and `src/` versus `system/`.
- Logs should follow Users soon, because dashboard, notifications, email logs, update notices, and auditability all depend on persisted events.
