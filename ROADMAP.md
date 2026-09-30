# Roadmap

## Theme
Direction: one all-purpose theme (`themes/default`) that grows with blocks and variants; site changes live in `custom/`. Plan and contract: `docs/theming.md`.
- Phase 1 (done): theme manifest + schema-driven settings, folder structure, asset serving, `custom/` overrides, update-safe translations
- Phase 2 (done): design system (tokens, palettes, dark mode, fonts, shapes), block engine, first block family (12 blocks), responsive images, accessibility and SEO foundations
- Phase 3 (done): admin block editor; second block family (gallery, team, timeline, contact, map); header layouts and options; footer layouts
- Phase 4 (done): page templates (standard, landing, with sidebar); ready-made sections and page layouts; saving sections from the editor
- Phase 5 (done): third block family (latest content in 8 layouts, video with large viewer, slider, pricing, tabs, banner); five more ready-made sections
- Editor role (done): see `docs/security.md`
- Permissions per role (done): Admin > Roles, super admin only; see `docs/security.md`
- Addresses and redirects (done): addresses made from the title, address changes leave 301 redirects, Admin > Redirects with a not-found list
- Revision history (done): Admin > History, compare and restore, deleted items; see `docs/security.md`
- Taxonomy layouts, links after address changes, and redirects on delete (done): each category/tag taxonomy has its own archive layout, links inside content can follow a changed address, and deleting a public entry can send its visitors somewhere
- Comparison table and before/after blocks (done), admin-wide fixed action bar (done)
- Custom roles (done): Admin > Roles, super admin only
- Hero layouts (done): five title layouts (default, centered, split, cover, minimal) per content type, an override in every entry's editor, and an optional transparent header over an opening hero
- Phone navigation (done): five styles for the phone menu (drawer left or right, full screen, top sheet, bottom sheet), and a bottom bar with links and icons you choose
- Icons (done): one shared library and one picker popup wherever an icon is chosen (blocks, theme settings, the bottom bar)
- Admin structure (done): Content, Manage and System sidebar sections; the Theme has its own screen with a tab per section; Logs and Users & Roles are tabs; one tab style; every message in the bottom bar
- Limits (done): storage limit (measured every 12 hours, adjusted on uploads and deletes), largest upload, and the kinds of file allowed
- Resilience (done): a content file with unreadable front matter is treated as a draft and reported, and the rest of the site carries on
- Tests on GitHub Actions (done): the whole suite on PHP 8.1, 8.3 and 8.5 for every push and pull request
- Next candidates: draft preview links (not now), admin-level user management with limits, better search, import improvements, further App.php splitting
- Style references from the owner can refine the design system at any point (tokens and block CSS)
- Phase 6 (done): content types with declared fields, an admin screen for them, archive layouts, filters, and pagination
- Phase 7 (done): demo content rebuilt with blocks, accessibility pass with axe-core, theme developer guide and audit script
- Earlier baseline completed:
  - starter bilingual content + contact form seeded
  - responsive header navigation (desktop + mobile off-canvas + nested levels)
  - sticky header reveal and interaction polish
  - full-width hero architecture across singles/archives/search/404
  - full-width footer shell with constrained inner layout and footer icon set

## Taxonomies
- Current baseline: the Taxonomies screen is a term list (search, add and edit in a dialog, remove with undo, reorder, sort A–Z, entry counts linking to the filtered content list) with descriptions per language and a settings tab for the layout of the term pages
- Dedicated taxonomy UX for future custom taxonomies (create/remove taxonomy files from admin; the public routes know only `category` and `tag` today)
- Optional term image/meta support, and parent terms for categories
- Optional per-content-type taxonomy assignment rules

## Menus
- Current baseline: unified menu editor with inline multilingual labels and `nav.main.*` / `nav.footer.*` key convention (no translation-screen dependency for menu labels)
- Current baseline: `nav.*` keys are hidden from Admin Translations to prevent accidental menu-label drift
- Current baseline: frontend supports nested menus with active item + active parent trail states (desktop + mobile)
- Drag-and-drop ordering in menu editor (instead of row-order/manual level select)
- Optional per-item visibility rules (by language/role in future)
- Optional menu item metadata: icon, badge, and rel attributes (`nofollow`, `noopener`, etc.)
- Menu UX enhancement: optional key helper/autocomplete for `nav.<menu>.<item>` naming consistency

## Media
- Current baseline: unified media library for images + documents/files (single admin flow)
- Current baseline: content edit screens can pick existing images from library or upload/assign a new main image in place
- Media picker (done): one dialog with search, tag filter, and pages, everywhere an image is chosen
- Usage tracking (done): where each file is used, a warning before deleting a used file, and an Unused filter

## Admin UI
- Current baseline: fixed left sidebar (Overview, Content, Manage, System) that slides in on narrow screens, with one fixed Save bar that also carries messages
- Current baseline: sidebar and screens follow each role's permissions (Admin > Roles)
- Admin accessibility (done): every screen and state scanned with axe-core in light, dark, and phone width with no violations; see `docs/admin-accessibility.md`. Still to do by hand: screen readers

## Forms
- Translation helper: clone fields/options from source form when creating a new language version
- Submissions UX: filters by date/status and pagination for large datasets
- Submissions maintenance: bulk delete/archive actions
- Optional: frontend form theme variants (compact/stacked) without changing form schema

## SEO
- JSON-LD (done): `Organization` (with contact point), `WebSite` with `SearchAction` on the home page, `BlogPosting` for posts, `Article` for projects, `WebPage`, breadcrumbs; see `docs/theming.md`
- robots.txt rules (done): Settings > General > Search engines

## Caching
- Runtime cache mode only (no static export pipeline)
- Filesystem cache backend as default (portable, zero extra services)
- Layer 1: parsed-content cache (front matter + rendered markdown)
- Layer 2: data/index cache (archives, taxonomies, map marker datasets)
- Layer 3: full-page HTML cache by route + language (+ query variants where needed)
- Deterministic cache keys including route, language, content type, and theme
- Targeted invalidation on save/delete/import:
  - invalidate item page, related archives/taxonomies, homepage/widgets, and sitemap/feed
  - invalidate linked translations when content is connected via `translation_id`
- TTL fallback as safety net (invalidation remains the primary freshness mechanism)

## Backups
- Current baseline: local full-site snapshots in `storage/backups` with manual create/download from `Settings -> Backup`
- Current baseline: automatic local schedule via checkbox + frequency (`daily` / `weekly` / `monthly`) and local retention count
- Current baseline: SHA-256 manifest per archive, Verify action, and staged/reversible restore of data areas (superadmin)
- Current baseline: S3-compatible remote upload with retention
- Add one-click restore-to-staging drill workflow and periodic restore report
- Optional: Google Drive remote target

## Post-Theme Architecture
- Refactor `src/App.php` into focused modules/services (in progress: BackupService, UpdateService, Mailer, MediaLibrary, ContentIndex, FormSubmissionRepository, LoginThrottle, SystemMetaRepository are extracted)
- Split so far: menus, CSV import/export, structured data, robots.txt, form fields, site settings, taking backups, the taxonomies screen, redirects, translations (`App.php` went from 9,800 to about 6,940 lines, with unit tests for each). Next candidates: the public taxonomy pages, the media, forms, users and content types screens (see `docs/architecture.md`)
- Keep behavior identical during refactor (incremental extraction + regression checks)

## Later Development
- Search improvements (weighted + highlights)
- Runtime page/data cache implementation (filesystem backend + targeted invalidation)
- Theme switcher in settings + preview mode
- Theme settings for OpenAI/API credentials (for future excerpt/content generation)
- Import UX improvements (dry-run preview, row-level undo, resumable imports)
