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
| `App` | Routing, request handlers, and the wiring of the classes below. Still the largest file (about 5,000 lines); new behaviour should go into a focused class, as content saving did (`ContentEditor`). |
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
| `SiteSettings` | The site settings document: defaults, load (with the old YAML read once), the values the settings form shows, saving a submitted form, and the secrets rule. |
| `BackupManager` | Taking backups on top of `BackupService`: the schedule and its lock, full and database snapshots with retention and remote upload, the run history, the notification, and the words for a result. |
| `TaxonomyEditor` | What the Taxonomies screen does with a submitted form: reads the rows, saves terms and layout, leaves redirects and updates menu links for a moved address, and counts the entries filed under each term. |
| `RedirectAdmin` | What the Redirects screen does: checks and saves a redirect, switches, deletes, imports a pasted list, lists the addresses visitors missed with suggestions, describes each redirect for the list, and works out which links in content can be pointed at a redirect's target. |
| `PublicPaths` | Which public addresses lead to a page, the list of published addresses, the closest address to a missed one, and a pasted address of the site turned into a path. |
| `ThemeStrings` | The theme's words as the Translations screen edits them: what a language shows, and saving overrides to `custom/lang/<lang>.yaml` (kept across updates). |
| `EntryTranslations` | The translations of an entry as the admin lists them: the languages each entry exists in, and a row per language with a link to edit or start it. |
| `LanguageAlternates` | The other languages of a public page: the language switcher's links and the `hreflang` addresses of an entry, a content type's list, and a category or tag page. |
| `MediaAdmin` | What the Media screen does on top of `MediaLibrary`: the list asked for and kept across actions, uploads within the storage and size limits, tags, deleting (files in use are protected), bulk actions, the list with where each file is used, and the page of pictures for the image picker. |
| `SiteLimits` | What the site uses against the storage limit (measured, kept for twelve hours, adjusted by uploads and deletions), whether a file fits, the size of one upload, and the readers of the limits on the settings form. |
| `SystemStatus` | The health checks of the dashboard and the System screen, their one-line verdict, the PHP extensions, and the environment table. |
| `DashboardData` | What the dashboard shows each role. |
| `FormProcessor` | What happens to a form someone fills in on the site: the starting values, checking what was sent against the fields, the record kept, and the emails it causes (notification and automatic reply). Sending and storing are the caller's. |
| `FormsAdmin` | The Forms screens: the list with submission counts, one form's submissions filtered and paged, deleting submissions, and the CSV export. |
| `SignIn` | Password sign-in: a block after repeated failures, the log, and a flag when the password is the one shipped with the CMS. |
| `GoogleSignIn` | Signing in with Google: its settings, the address to send the person to, the two calls for a profile, and whether a profile may sign in. The calls can be replaced in tests. |
| `UserAdmin` | Adding and changing a user: reading the form within what the person may change, the password checks, saving. |
| `RoleAdmin` | The Roles screen: the permission table, custom roles (make, rename, delete), and the log of permission changes. |
| `ContentTypeAdmin` | The Content types screen: making a type, saving a definition (only differences from the theme are written), and the rows it shows. |
| `ArchiveBuilder` | The archive of any list of entries: the filters it offers (only real values), the order, and the page. |
| `BackupAdmin` | The Backups screens: verify, create, delete, restore (a safety snapshot first, only the areas chosen), the backup before an update, and the data of the screens. |
| `StructuredData` | The JSON-LD graph of a page (organization, website and search, article or page, breadcrumb) and its script tag. |
| `RobotsTxt` | The rules typed in Settings > General > Search engines, checked, and the robots.txt they make. |
| `MediaUsage` | Where each uploaded file is used (content and settings), kept between visits under a fingerprint of what it read. |
| `FormFields` | Form field kinds, and how stored fields are read for the site and the editor and cleaned when the editor sends them. |
| `Theme` | Frontend theme manifest (`theme.yaml`), settings schema and resolution, template lookup across `custom/` and the theme, asset URLs, layered translations and `custom/lang` overrides. |
| `ThemeAssets` | Serves theme and `custom/` assets from outside `public/` with type allowlist, path checks, and caching. |
| `FieldSchema` | Declarative field definitions (text, markdown, link, image, select, toggle, number, repeater, …): defaults, validation of stored values, and form input handling. Used by theme settings and blocks. |
| `ContentTypes` | Content type definitions from `themes/<theme>/content-types/` and `custom/content-types/` (merged per field): declared fields, checked values, display lists, archive settings, and writing the site's file from Admin > Content types. |
| `Taxonomies` | Categories, tags, and other taxonomies in `content/taxonomies/`: terms with a stable id, a changeable address made from the name, labels and descriptions per language, and the archive settings of each taxonomy. Works out what a submitted terms form changes (new terms, moved addresses, removed terms). |
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

`App.php` is being split incrementally, keeping behaviour identical: extract a cohesive group of private methods into a class that takes what it needs through its constructor (paths, and closures for things that change during a request, such as the settings), delegate from `App`, and give the class unit tests before and after moving it. Done so far: menus (`Menus`), CSV import and export (`ContentCsv`), structured data (`StructuredData`), robots.txt (`RobotsTxt`), form fields (`FormFields`), the site settings (`SiteSettings`), taking backups (`BackupManager`), what the taxonomies screen does (`TaxonomyEditor`), redirects (`RedirectAdmin`, `PublicPaths`), translations (`ThemeStrings`, `EntryTranslations`, `LanguageAlternates`), the media screen (`MediaAdmin`), storage limits, system checks and the dashboard (`SiteLimits`, `SystemStatus`, `DashboardData`), forms (`FormProcessor`, `FormsAdmin`), sign-in, users and roles (`SignIn`, `GoogleSignIn`, `UserAdmin`, `RoleAdmin`), content types (`ContentTypeAdmin`), archives (`ArchiveBuilder`), backups (`BackupAdmin`), media usage (`MediaUsage`), the media library, backups, updates, mail, and the content editor. Candidates left, in order of size and independence: the screens that edit content (edit, save, delete, the list, bulk actions, CSV import, history), the menus and taxonomies handlers, the logs, the Twig set-up and template rendering, and the public side (routing, the front page of each kind, the taxonomy pages).
