# FarosCMS Code Map

FarosCMS is a single-entry PHP application (`public/index.php` → `FarosCMS\App::handle()`), with Markdown/YAML content files, Twig templates, and a SQLite system database.

## Request flow

1. `App::handle()` configures the session, sends security headers, and routes `/admin/*` to `handleAdmin()` and everything else to `handleFront()`.
2. `handleAdmin()` checks the CSRF token on every POST, handles sign-in/out, re-reads the signed-in user, checks the route capability (`PermissionService::canAccessAction()`), runs a due scheduled backup, and dispatches to a `handle*()` method.
3. Handlers render Twig templates from `admin/templates/` (namespace `@admin`) or the frontend theme: `custom/` first, then `themes/default/` (see `theming.md`).

Before the application boots, `public/index.php` hands `/_themes/…` and `/_custom/…` requests to `ThemeAssets` and missing `/uploads/_v/…` image variants to `Images`.

## Classes in `src/`

| Class | Responsibility |
|-------|----------------|
| `App` | Routing, request handlers, settings, menus, taxonomies, forms, CSV import/export, template rendering. Still the largest file; new behaviour should go into a focused class. |
| `ContentRepository` / `ContentItem` | Reads Markdown files with YAML front matter; listing, lookup, and frontend search. |
| `ContentIndex` | SQLite `content_index` kept in sync with the files; admin search and staleness checks. |
| `Auth` | Session sign-in (SQLite users, YAML fallback), session rotation, per-request user refresh, shipped-password detection. |
| `UserRepository` | SQLite users: CRUD, YAML import, superadmin guarantee. |
| `PermissionService` | Role capabilities (`superadmin`, `admin`, `editor`, `user`) and route-to-capability mapping; unmapped routes are administrator-only. |
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
| `Theme` | Frontend theme manifest (`theme.yaml`), settings schema and resolution, template lookup across `custom/` and the theme, asset URLs, layered translations and `custom/lang` overrides. |
| `ThemeAssets` | Serves theme and `custom/` assets from outside `public/` with type allowlist, path checks, and caching. |
| `FieldSchema` | Declarative field definitions (text, markdown, link, image, select, toggle, number, repeater, …): defaults, validation of stored values, and form input handling. Used by theme settings and blocks. |
| `ContentTypes` | Content type definitions from `themes/<theme>/content-types/` and `custom/content-types/` (merged per field): declared fields, checked values, display lists, archive settings, and writing the site's file from Admin > Content types. |
| `BlockRegistry` | Block definitions from `themes/<theme>/blocks/*/block.yaml` and `custom/blocks/`, with shared presentation fields (variant, tone, spacing, anchor, hidden); editor definitions and storage sanitising for the admin block editor (`public/assets/js/admin-blocks.js`). |
| `BlockRenderer` | Renders a page's `blocks:` list: checks values, heading levels, Markdown, dynamic data (latest items, forms), stylesheet bundle, and FAQ structured data. |
| `PresetLibrary` | Ready-made sections and page layouts from `themes/<theme>/presets/` and `custom/presets/`, localised per language; saves and deletes site sections. |
| `Toc` | Heading ids and "On this page" contents for the sidebar template. |
| `Images` | Responsive `<picture>` markup for uploads and on-demand WebP variants under `/uploads/_v/`. |

## Admin front end

- `admin/templates/base.twig` is the shell (sidebar, header, notifications, toast host).
- `admin/templates/partials/ui.twig` holds shared macros: `flash`, `badge`, `status_badge`, `filter_button`, `empty_state`, `pagination`.
- `public/assets/js/admin.js` provides data-attribute behaviours: dropdowns, tabs, filters, off-canvas, modals, toasts, sortable tables, confirmation modal, copy-to-clipboard, select-all, and the CSRF safety net.
- Styles: `public/assets/css/admin.build.css` is the compiled Tailwind build (see README), `public/assets/css/app.css` holds the few custom rules.

## Where data lives

See `system-database.md` for SQLite and `backups.md` for what backups contain. Content, uploads, and `custom/` (site overrides) are files; settings (including theme settings), users, logs, and indexes are in SQLite.

## Refactoring direction

`App.php` is being split incrementally, keeping behaviour identical: extract a cohesive group of private methods into a class, delegate from `App`, and run the admin regression checks. Candidates, in order of size and independence: menus, taxonomies, CSV import/export, settings (defaults, load/save, form mapping), translations.
