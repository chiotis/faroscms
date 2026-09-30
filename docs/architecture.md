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
| `App` | Routing, request handlers, settings, menus, taxonomies, forms, CSV import/export, template rendering. Still the largest file (about 8,900 lines); new behaviour should go into a focused class, as content saving did (`ContentEditor`). |
| `ContentRepository` / `ContentItem` | Reads Markdown files with YAML front matter; listing, lookup, and frontend search. |
| `ContentIndex` | SQLite `content_index` kept in sync with the files; admin search and staleness checks. |
| `Auth` | Session sign-in (SQLite users, YAML fallback), session rotation, per-request user refresh, shipped-password detection. |
| `UserRepository` | SQLite users: CRUD, YAML import, superadmin guarantee. |
| `PermissionService` | Role capabilities (`superadmin`, `admin`, `editor`, `user`), the capability catalogue, the super admin's per-role changes (`system_meta.role_permissions`) and roles of the site's own (`system_meta.custom_roles`), and route-to-capability mapping; unmapped routes are administrator-only. |
| `ContentEditor` | Saving a content item from the editor form: address (from the title, made unique, kept or changed), front matter from the submitted fields, raw HTML guard, writing the file, moving translations, redirects. Takes the submitted fields and returns what happened; knows nothing about requests, sessions, or menus. |
| `HtmlGuard` | Neutralises raw HTML for people without `content.raw_html`, leaving HTML already stored in a file alone. |
| `FormFields` | Form editor field types and cleaning of submitted field rows. |
| `ContentPaths` | File names and public paths of content from the language and home page settings. |
| `FrontMatter` | Splits a content file into its YAML and body. |
| `RevisionRepository`, `LineDiff` | The history of content files in `content_revisions` (capture, baseline, rename, prune, deleted items) and the line comparison shown in Admin > History. `ContentEditor` records a version around every save and can restore one. |
| `Slug` | Turns text into a web address: Greek to Latin (ELOT 743 rules), accents dropped, length cap, reserved root words. `admin/templates/edit.twig` has a JavaScript copy for the live preview only; the server decides. |
| `RedirectRepository` | `redirects` (old path to new path or full address, 301/302, origin, hits) and `not_found_log`; path normalising, validation (no loops, no `javascript:`), following redirects that lead to redirects. Consulted only when a request matches nothing. |
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
| `Format` | Shared small helpers: byte sizes, truthy values, lists from front matter. |
| `ArrayPath` | Reading, writing, and removing a value deep inside nested arrays by its path. |
| `Menus` | The menu files in `content/menus`: reading and writing, checking items (three levels, labels per language), nesting rows from the admin form, which menu sits in which theme location, translated labels, the active item and trail for the page shown, and following an address change. |
| `ContentCsv` | The CSV export of a content type and the two-step import (preview, then apply with backups and rollback). |
| `StructuredData` | The JSON-LD graph of a page (organization, website and search, article or page, breadcrumb) and its script tag. |
| `RobotsTxt` | The rules typed in Settings > General > Search engines, checked, and the robots.txt they make. |
| `MediaUsage` | Where each uploaded file is used (content and settings), kept between visits under a fingerprint of what it read. |
| `FormFields` | Form field kinds, and how stored fields are read for the site and the editor and cleaned when the editor sends them. |
| `Theme` | Frontend theme manifest (`theme.yaml`), settings schema and resolution, template lookup across `custom/` and the theme, asset URLs, layered translations and `custom/lang` overrides. |
| `ThemeAssets` | Serves theme and `custom/` assets from outside `public/` with type allowlist, path checks, and caching. |
| `FieldSchema` | Declarative field definitions (text, markdown, link, image, select, toggle, number, repeater, …): defaults, validation of stored values, and form input handling. Used by theme settings and blocks. |
| `ContentTypes` | Content type definitions from `themes/<theme>/content-types/` and `custom/content-types/` (merged per field): declared fields, checked values, display lists, archive settings, and writing the site's file from Admin > Content types. |
| `Taxonomies` | Categories, tags, and other taxonomies in `content/taxonomies/`: terms with a stable id, a changeable address made from the name, labels per language, and the archive settings of each taxonomy. Works out what a submitted terms form changes (new terms, moved addresses, removed terms). |
| `LinkScanner` | Finds links to addresses of the site inside content files (body, HTML, front matter) and rewrites them; used to point links at a redirect's target. |
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

`App.php` is being split incrementally, keeping behaviour identical: extract a cohesive group of private methods into a class that takes what it needs through its constructor (paths, and closures for things that change during a request, such as the settings), delegate from `App`, and give the class unit tests before and after moving it. Done so far: menus (`Menus`), CSV import and export (`ContentCsv`), structured data (`StructuredData`), robots.txt (`RobotsTxt`), form fields (`FormFields`), media usage (`MediaUsage`), the media library, backups, updates, mail, and the content editor. Candidates left, in order of size and independence: settings (defaults, load and save, the form mapping and the secrets), taxonomies handling, translations, the backup schedule and remote upload, the redirects and links screens.
