# Roadmap

## Theme
Direction: one all-purpose theme (`themes/default`) that grows with blocks and variants; site changes live in `custom/`. Plan and contract: `docs/theming.md`.
- Phase 1 (done): theme manifest + schema-driven settings, folder structure, asset serving, `custom/` overrides, update-safe translations
- Phase 2 (done): design system (tokens, palettes, dark mode, fonts, shapes), block engine, first block family (12 blocks), responsive images, accessibility and SEO foundations
- Next: admin block editor (add, reorder, schema-driven fields, media picker); then more block families (gallery, team, timeline, contact details, map) and header/footer variants
- Style references from the owner can refine the design system at any point (tokens and block CSS)
- Phase 5: content types with declared fields and per-type templates/archive settings
- Phase 6: demo content rebuilt with blocks, accessibility pass, theme developer guide
- Earlier baseline completed:
  - starter bilingual content + contact form seeded
  - responsive header navigation (desktop + mobile off-canvas + nested levels)
  - sticky header reveal and interaction polish
  - full-width hero architecture across singles/archives/search/404
  - per-content-type hero layout switch (`default` / `centered`) via `theme_settings.hero_layouts`
  - full-width footer shell with constrained inner layout and footer icon set
- Build robust production-ready visual system for default theme (typography scale, spacing rhythm, component polish)
- Add polished page compositions for home/services/about/contact/news/projects/404 using current content model
- Add reusable UI sections/components for cards, CTAs, testimonials, trust signals, and service highlights
- Finalize accessibility pass (contrast, focus states, heading hierarchy, keyboard flow)
- Add lightweight theme documentation for layout options and settings keys

## Taxonomies
- Dedicated taxonomy UX for future custom taxonomies (create/remove taxonomy files from admin)
- Optional taxonomy ordering, term descriptions, and term image/meta support
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
- Add media picker search/filter inside edit-screen picker (name/tag filter)
- Add paginated media picker modal/drawer for large libraries
- Add usage/reference tracking (show where each media item is used before delete)
- Add optional image transforms (`thumb`, `webp`) with safe fallback URLs

## Admin UI
- Current baseline: fixed left admin sidebar + pinned bottom actions, with compact navigation styling
- Current baseline: fixed right utility rail for `Translations`, `Settings`, and `Logout`
- Add responsive admin behavior for narrow widths (collapse/slide left nav, keep utilities accessible)
- Add optional quick-action rail configuration (enable/disable items per installation)
- Add permission-aware visibility for utility and sidebar actions (future roles support)

## Forms
- Translation helper: clone fields/options from source form when creating a new language version
- Submissions UX: filters by date/status and pagination for large datasets
- Submissions maintenance: bulk delete/archive actions
- Optional: frontend form theme variants (compact/stacked) without changing form schema

## SEO
- JSON-LD: `Organization` + `WebSite` (+ `SearchAction` on homepage)
- JSON-LD: `Article` for posts/projects (title, date, author, image)
- robots.txt extra disallow rules (settings)

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
- Next split candidates: menus, taxonomies, CSV import/export, settings, translations (see `docs/architecture.md`)
- Keep behavior identical during refactor (incremental extraction + regression checks)

## Later Development
- Draft preview links (no publish required)
- Search improvements (weighted + highlights)
- Slug changes with redirect map
- Roles (admin/editor) via YAML
- Runtime page/data cache implementation (filesystem backend + targeted invalidation)
- Theme switcher in settings + preview mode
- Theme settings for OpenAI/API credentials (for future excerpt/content generation)
- Import UX improvements (dry-run preview, row-level undo, resumable imports)
