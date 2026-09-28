# FarosCMS Code Map

FarosCMS is a single-entry PHP application (`public/index.php` → `FarosCMS\App::handle()`), with Markdown/YAML content files, Twig templates, and a SQLite system database.

## Request flow

1. `App::handle()` configures the session, sends security headers, and routes `/admin/*` to `handleAdmin()` and everything else to `handleFront()`.
2. `handleAdmin()` checks the CSRF token on every POST, handles sign-in/out, re-reads the signed-in user, checks the route capability (`PermissionService::canAccessAction()`), runs a due scheduled backup, and dispatches to a `handle*()` method.
3. Handlers render Twig templates from `admin/templates/` (namespace `@admin`) or the active theme in `themes/<theme>/`.

## Classes in `src/`

| Class | Responsibility |
|-------|----------------|
| `App` | Routing, request handlers, settings, menus, taxonomies, forms, CSV import/export, template rendering. Still the largest file; new behaviour should go into a focused class. |
| `ContentRepository` / `ContentItem` | Reads Markdown files with YAML front matter; listing, lookup, and frontend search. |
| `ContentIndex` | SQLite `content_index` kept in sync with the files; admin search and staleness checks. |
| `Auth` | Session sign-in (SQLite users, YAML fallback), session rotation, per-request user refresh, shipped-password detection. |
| `UserRepository` | SQLite users: CRUD, YAML import, superadmin guarantee. |
| `PermissionService` | Role capabilities (`superadmin`, `admin`, `user`) and route-to-capability mapping. |
| `LoginThrottle` | Failed sign-in counting and blocking (`login_attempts`). |
| `SystemDatabase` | SQLite connection, migrations, and close/reopen for restores. |
| `SystemMetaRepository` | `system_meta` key/value storage (settings YAML, cached status JSON). |
| `ActivityLogRepository`, `EmailLogRepository`, `NotificationRepository`, `BackupRunRepository` | Log, notification, and backup-history tables. |
| `BackupService` | Archive creation with checksum manifest, listing, retention, verification, and staged restore. |
| `S3BackupStorage` | S3-compatible upload, listing, and pruning with SigV4 signing. |
| `UpdateService` | Local version, GitHub `VERSION`/`CHANGELOG` source, cached update status. |
| `FormSubmissionRepository` | JSON form submissions per form slug: listing, filtering, storing, deleting. |
| `Mailer` | SMTP and Amazon SES sending. |
| `MediaLibrary` | Media uploads (type allowlist, SVG checks), YAML metadata, tags, listing, legacy adoption. |
| `Format` | Shared display formatters (byte sizes). |

## Admin front end

- `admin/templates/base.twig` is the shell (sidebar, header, notifications, toast host).
- `admin/templates/partials/ui.twig` holds shared macros: `flash`, `badge`, `status_badge`, `filter_button`, `empty_state`, `pagination`.
- `public/assets/js/admin.js` provides data-attribute behaviours: dropdowns, tabs, filters, off-canvas, modals, toasts, sortable tables, confirmation modal, copy-to-clipboard, select-all, and the CSRF safety net.
- Styles: `public/assets/css/admin.build.css` is the compiled Tailwind build (see README), `public/assets/css/app.css` holds the few custom rules.

## Where data lives

See `system-database.md` for SQLite and `backups.md` for what backups contain. Content, uploads, and theme translations are files; settings, users, logs, and indexes are in SQLite.

## Refactoring direction

`App.php` is being split incrementally, keeping behaviour identical: extract a cohesive group of private methods into a class, delegate from `App`, and run the admin regression checks. Candidates, in order of size and independence: menus, taxonomies, CSV import/export, settings (defaults, load/save, form mapping), translations.
