# Changelog

## 2026-09-29 — One title per admin page
- The page title now appears once, in the top bar, and is specific to the page ("Edit Page", "Import Pages", "History of …", "Delete “…” (EL)"). The repeated heading inside the content is gone; the description and the buttons beside it stay. Every admin page has exactly one `<h1>`. A screen sets its title with `{% set page_heading = … %}` after `extends`

## 2026-09-29 — System in the account menu
- **System** (health checks, scheduled tasks, environment) moved out of the sidebar into the account menu at the top right, under Settings. It only shows information, so it no longer takes a place among the things you work with

## 2026-09-29 — Roles of your own
- **Admin > Roles** can now make roles beyond Admin, Editor, and Basic user (a photographer, a translator, an intern): a name, a description, and a starting point (only signing in, or a copy of Basic user, Editor, or Admin). A new role becomes a column in the permission table, where you tick exactly what it may do; it appears in Users for giving to people, with its own badge, and can be renamed. Up to 20 per site
- The same limits as the built-in roles: signing in and the own profile stay on, and managing users, roles, and restoring backups can never be given. A role cannot be deleted while someone has it. A form that names a role that does not exist gives the person Basic user
- Permission changes to your own roles are written to the activity log with what was added and removed
- The permission table now also ignores a role that was created in another window after the page was opened, instead of clearing it

## 2026-09-29 — Messages take over the action bar
- On every screen with the bottom action bar, messages (saved, warnings, errors) no longer appear as separate boxes: the bar turns into the message. The buttons slide up and away, the message comes up from below, and the bar takes its colour (green, amber, red) with a thin countdown line. It goes back by itself after three seconds (longer only for a long message); an error stays until it is dismissed (Dismiss or Escape). Several messages show one after another. Screen readers get the message through a live region, and with reduced motion there is no movement. Screens without a bar keep the small box at the bottom right

## 2026-09-29 — A fixed action bar, and two new blocks
- **Save and its companions stay at the bottom of the window** on every screen with a form to save: content editor (Preview, Delete, Save), Settings (Discard, Save), content types, roles, menus, new menu, taxonomies, users, and translations. One shared bar (`partials/action-bar.twig`) sits to the right of the sidebar on wide screens and across the window on phones, so nothing has to be found at the top of a long page. Notifications moved above it
- New block **Comparison table** (`compare`): up to four columns, one of them highlightable with a badge and a button, up to 30 rows with group headings; `yes`/`no` become a check or a cross with text for screen readers. A real table in a named, focusable, scrolling region. Lines and striped variants
- New block **Before and after** (`before-after`): a slider you drag or move with the arrow keys (a native range input laid over the picture), or the two pictures side by side; without JavaScript they sit side by side. Labels, shape, and caption are editable
- Both blocks have ready-made sections, are on the blocks showcase page, and are covered by tests. Theme version 1.8.0

## 2026-09-29 — Layouts per taxonomy, links after an address change, and deleting with a redirect
- **Each taxonomy has its own page layout** (Admin > Taxonomies, "How its pages look"): the same choices a content type has (layout, columns, order, items per page, what shows on each entry, page title and subtitle, filters from other taxonomies) plus which content types are listed, so categories can be a magazine and tags a plain list. `{term}` in the title or subtitle stands for the term's name. Category and tag pages are now paged, filterable, and list entries of several types newest first. The archive form is shared with Content types
- **Term addresses are automatic**: a new category or tag gets its address from its name, with Greek converted to Latin (`Ελληνική κουζίνα` → `elliniki-kouzina`). Changing an address leaves a permanent redirect in every language and updates menu links; entries keep referring to the term by its id, which never changes. Removing a term that entries still use warns how many. The terms table shows how many entries use each term
- **Links inside content follow an address change**: after an address changes, the editor is told how many links in other content still use the old one, and *Review and update* points them at the new address (text, HTML, and block buttons; full addresses of the site and anchors and query strings are kept). Admin > Redirects has a Links column for every permanent redirect and the same review screen. Only the address in the text changes, earlier versions stay in the history, and menus and theme settings are not touched
- **Deleting a public entry asks where visitors should go**: nowhere, the list of its type, the home page, or any address (on the site or another website), which becomes a permanent redirect; the page also shows how many links elsewhere point to it. Drafts are deleted as before. Bulk delete now keeps the deleted text in the history, which it did not before, and says how many public entries went
- New `Taxonomies` and `LinkScanner` classes; taxonomy storage moved out of `App.php`

## 2026-09-29 — Tests, a smaller App.php, and revision history
- **History** (Admin > History, and a History tab in the editor): every save, import, restore, and delete keeps the whole text, so an earlier version can be compared line by line ("what this version changed" or "if you restore it") and brought back. Deleted items are listed and can be brought back exactly as they were. The latest 50 versions of each item are kept, and deleted items for 180 days
- A file changed outside the editor (by hand or through git) is kept as its own version before the next save overwrites it, and the first save of an item that existed before history began keeps what was there
- History follows an item when its address changes, in every language that moves with it
- Restoring passes the same raw HTML rule as saving: someone who may not add HTML gets any HTML that is not already in the current file shown as text. Forms are visible in history only to people who may manage forms
- Content saving moved out of `App.php` into `ContentEditor`, with `HtmlGuard`, `FormFields`, `ContentPaths`, and `FrontMatter` alongside it; `App.php` is about 500 lines shorter and behaves the same (25 public pages compared before and after)
- **Test suite**: `tests/run.sh` runs 660 checks (PHP unit checks, and HTTP tests of the editor role, roles, import, redirects, saving every field, and history) against a temporary copy of the site with fixtures; `tests/README.md` explains what is covered. `scripts/check-blocks.php` validates a site's blocks

## 2026-09-29 — Web addresses and redirects
- New content gets its address from the title, with Greek converted to Latin by the ELOT rules (`ου` → `ou`, `μπ` → `b`, `ευ` → `ev`/`ef`, …); the editor shows it live and nobody types a slug. A title that is already used gets `-2`, and a page cannot take a word the site uses itself (`admin`, `search`, a content type, a language code). Before, saving a new item with a used address silently overwrote the other one
- Changing an address is a deliberate step ("Change" beside the address): the old address becomes a permanent (301) redirect, the other languages can move with it, menu links follow, and redirects that already pointed at the old address are repointed so nobody goes through a chain. The home page and forms keep their address. Drafts that were never public leave no redirect
- New screen **Admin > Redirects** (permission `redirects.manage`, admin and super admin by default): every redirect with its visits, whether the target still exists, and whether a page already sits at the old address; add, edit, turn off, delete, bulk add from a list, filter by origin or use. A pasted full address of this site becomes a path
- **Not found** tab: addresses visitors asked for that do not exist, most asked first, with the referring site and a suggested page; one click makes a redirect. Files and probes (`.php`, `/uploads/`) and POST requests are not recorded, and the list is capped
- Redirects keep the query string, are case-insensitive, stop after six hops, and treat a loop as Not found. Targets are paths on this site or `http(s)` addresses only
- `scripts/check-slugs.php` checks the conversion and the redirect store

## 2026-09-29 — Permissions per role
- New screen **Admin > Roles** (super admin only): a matrix of every permission against Admin, Editor, and Basic user, with a description and a Sensitive or Critical label on the ones that can expose personal data or change the site itself; switching one of those on asks for confirmation
- Changes apply on the person's next click, are stored in the system database (`role_permissions`, only for roles that differ from the built-in set), are written to the activity log with what was added and removed, and can be undone per role with "Reset"
- Never switchable: the super admin's own permissions, and managing users, roles, and restoring backups; sign-in and editing one's own profile are always on for every role
- Importing content from CSV now follows the raw HTML rule, so granting import does not open a way around it
- `scripts/check-permissions.php` also checks custom permissions

## 2026-09-29 — Editor role
- New role **Editor**: writes, edits, publishes, and deletes pages, posts, and projects, and manages media, categories, and tags. It cannot open forms, menus, settings, content types, translations, users, logs, backups, updates, or import/export, and it gets a content-only dashboard without system figures or notifications
- Raw HTML is an administrator privilege: an editor's HTML is shown as plain text (with a notice), while HTML an administrator placed in a page stays intact when an editor saves it; this covers the text, block Markdown fields, and raw front matter
- Markdown links with script addresses (`javascript:`) lose their address for everyone
- Admin actions with no explicit permission are now administrator-only instead of open to anyone who can edit content
- Users screens list all roles with descriptions; new users default to Editor, and an unknown or missing role can no longer turn into Admin
- Refused requests for forms are written to the activity log

## 2026-09-29 — Demo content, accessibility pass, and developer guide (phase 7)
- Demo content: Services, Design & Build, Project Management, Careers, and FAQ are built from blocks (pricing, tabs, timeline, FAQ, cards) in Greek and English; Privacy, Terms, and Cookies use the sidebar template with a contents list; posts and projects are full articles and case studies with results, gallery, and a quote; project excerpts describe web work for each client
- Accessibility (checked with axe-core on every page in light and dark, plus the mobile menu, image and video viewers, and open FAQ): blocks without a heading give their items `<h2>`; contact details are a real list; the slider follows the WAI-ARIA carousel pattern (no duplicate landmark); submenus close with Escape; the sidebar layout no longer widens on phones
- Markdown tables are supported and scroll in a keyboard-focusable box on narrow screens
- Sites can add their own page templates in `custom/page-templates.yaml`
- `docs/theme-developer-guide.md` (recipes, quality checklist, troubleshooting) and `scripts/theme-audit.js` (axe-core scan, 320 px reflow, palette contrast)
- Theme version 1.7.0

## 2026-09-29 — Content types with declared fields (phase 6)
- Content types: definitions in `themes/default/content-types/` (projects and posts ship with one) and `custom/content-types/`, merged per field; fields use the same schema as theme settings and blocks, plus a new `date` type
- Admin > Content types: create a type, set its title and archive layout, and add, reuse, or retire fields; the site's file records only differences from the theme
- Editor: a "<Type> details" tab with an input per declared field, values stored under `custom_fields` (existing content keeps working)
- Pages: declared fields appear as a fact sheet on the item's page and, where marked, on its card; the project page no longer hard-codes client, location, and duration
- Archives: any of the eight Latest-content layouts, ordering (including by a declared field), pagination, and filters from taxonomies and select fields; filtered pages are `noindex`, paginated pages have their own canonical URL; category and tag pages use the same layout
- The demo projects gain a sector (a filter) and a year

## 2026-09-28 — Dynamic and interactive blocks (phase 5)
- New blocks: latest content (8 layouts from a minimal text list to a magazine, optional category or tag filter), video (large viewer by default, YouTube, Vimeo, or a file, nothing loaded until played), slider, pricing, tabs, and banner (dismissible)
- Ready-made sections: magazine posts, project tiles, video, plans and pricing, services in tabs
- Icons: play, pause, chevrons, info, megaphone, x
- Dynamic blocks with nothing to show (no matching content, no valid video) render nothing instead of an empty section
- Theme version 1.5.0; the showcase page includes every new block and variant

## 2026-09-28 — Page templates and ready-made sections (phase 4)
- Page templates: Standard, Landing (no title header, blocks only), and With sidebar ("On this page" contents, related pages from the main menu, contact card from Theme settings > Sidebar template); chosen per page in the editor's Publish panel
- Ready-made sections: 8 section presets and 5 page layouts (company home, service, about, contact, campaign landing) in Greek and English; page layouts can replace or follow existing blocks and set their suggested template
- Saved sections: tick blocks in the editor and save them as a reusable section in `custom/presets/` (kept across updates and in backups); delete from the picker
- Hero: the split layout without an image uses one wide column
- Demo: the Workplace Strategy pages use the sidebar template

## 2026-09-28 — Block editor, second block family, header and footer options (phase 3)
- Admin: Blocks tab in the editor with a block picker, move, duplicate, hide, remove with undo, schema-generated fields (including repeaters, Markdown, and image fields with a media library picker); the server re-checks values and stores only non-default ones; existing blocks are kept when the editor cannot load
- Blocks: gallery (grid, masonry, strip, accessible viewer), team, timeline, contact (details with an optional form), and map (OpenStreetMap, loaded on request by default); demo About and Contact pages use them
- Blocks: optional `block.js` per block, bundled and deferred like block CSS; new `decimal` field type
- Header: classic, centered, minimal, and stacked layouts; transparent over an opening hero; sticky modes; CTA button; top bar; bottom action bar on phones
- Footer: columns, one-row, and centered layouts
- Fonts: Inter is self-hosted with the theme (Latin and Greek subsets, preloaded per language, no third-party requests); new "System fonts" option
- Fixes: Greek initials drop the accent (ΑΡ, not ΆΡ); timeline steps wrap instead of scrolling

## 2026-09-28 — Design system and first block family (phase 2)
- Blocks: pages and posts can list `blocks:` in front matter; 12 blocks (hero, content, text, text-image, features, stats, testimonials, logos, faq, cta, cards, form) with variants, background tones, and spacing; values are checked against each block's `block.yaml`
- Blocks: an opening hero becomes the page title; block CSS loads only where used, bundled into one request per page; a hidden `/blocks` showcase page shows every block and variant
- Design system: new `site.css` with tokens for palette (six palettes, light and dark), fonts, and corner shape (new setting); the palette and font settings now change the site; dark mode follows the system without a flash
- Images: `image()` renders width/height, WebP srcset, lazy or high-priority loading; variants are generated on first request under `/uploads/_v/`, excluded from backups, and removed with the media item
- Media: default alt text per image (Admin > Media), used when a page does not give its own
- Accessibility: skip link, visible focus, reduced motion, accessible mobile menu (dialog, focus handling, Escape), one link per card, labelled form controls with linked errors, correct heading levels in templates and demo content; checked for WCAG AA contrast in all palettes
- SEO: page titles with the site name, Open Graph type/site/locale/image, Twitter cards, share-image fallbacks, and a JSON-LD graph (Organization, WebSite, BlogPosting, BreadcrumbList, FAQPage)
- Theme settings: Brand (logo, default share image) and Social profiles; footer social icons show only when set
- Admin: saving a page keeps its `blocks:` intact (it was previously turned into text custom fields)
- Local development: run `php -S 127.0.0.1:8087 -t public public/index.php` so generated files work as on Apache/nginx; the nginx example in `docs/security.md` now falls back to `index.php`
- Demo content: home pages rebuilt with blocks; English projects pointed to an existing image; post headings start at h2

## 2026-09-28 — Theme foundation (phase 1)
- Theme: `themes/default` is reorganised into `layouts/`, `templates/`, `components/`, and `assets/`, with a `theme.yaml` manifest; rendered pages are unchanged
- Theme: the admin Theme tab is generated from the manifest, and stored theme settings are checked against it on every request (new fields get defaults, invalid values fall back)
- Theme: theme CSS/JS are served from outside `public/` at `/_themes/default/…` with versioned, long-lived caching; the inline theme script moved to `assets/js/site.js`
- Update safety: new `custom/` folder for site-specific CSS/JS, template overrides (by path or by extending `@theme/…`), and string overrides; updates never touch it
- Update safety: Admin > Translations stores only changed strings in `custom/lang/<lang>.yaml` instead of rewriting the theme's language files, with a per-string reset
- Backups: `custom/` is a restore area ("Site customizations") replacing "Theme translations"; restoring an area that did not exist before can now be rolled back cleanly
- Docs: `docs/theming.md` describes the theme contract and the compatibility rules for theme changes

## 2026-09-28
- Remote backups: S3-compatible upload (streamed, not loaded into memory), connection test, and retention pruning limited to FarosCMS backup archives
- A failed remote upload no longer marks the local snapshot as failed; scheduled backups advance and the run is recorded as a warning
- Theme settings are edited through a form (palette, font, hero layouts, home sections, footer) that matches the default theme options
- Site/theme settings live in SQLite; legacy `content/settings/*.yaml` files are still imported once on upgrade
- Development-only folders (`_reference/`, `node_modules/`) are excluded from full snapshots
- Security: CSRF protection on all admin forms, POST-only sign-out, hardened session cookies with id rotation, and immediate effect of user deactivation/role changes
- Security: failed sign-in throttling, masked settings secrets, upload type allowlist with SVG script checks, and security response headers
- Security: admin banner and notification while the shipped default password is still in use
- Backups: every archive carries a SHA-256 manifest and a consistent `VACUUM INTO` database copy; new Verify action
- Backups: superadmin restore of content, uploads, translations, and the system database with verification, mandatory safety snapshot, staged swap, and automatic rollback
- Updates: verified pre-update backup action and preflight gate; safe update design documented in `docs/update-workflow.md`
- Forms: standalone `/admin/forms` module with submission counts, shortcode copy, language versions, filters, and sorting
- Forms: submissions browser with search, language/date filters, pagination, detail panel, reply-by-email, and single/bulk delete
- Admin UI: shared macros (`partials/ui.twig`), one toast placement with auto-hide, Filters buttons with an active dot, sortable tables, confirmation modal instead of `confirm()`, and consistent status badges
- Admin CSS: Tailwind is compiled locally (`npm run build:css`) instead of loading the runtime CDN
- Removed dead code: unused legacy file-upload, menu-translation, and YAML helper methods, the unreachable `files.twig`, and two unreferenced stylesheets
- System: `/admin/system` page with health checks, scheduled tasks, PHP extensions, environment, and content index status/rebuild
- Admin search across all content via the SQLite content index (header search box)
- Email: SMTP/SES sending moved to `Mailer`; SMTP now checks the server's answer after DATA, MIME-encodes non-ASCII subjects and sender names, strips CR/LF from headers, and uses socket timeouts; SES without cURL no longer reports HTTP errors as success
- Content list: search and status filters, language pills, and bulk publish/draft/delete (the home page stays protected)
- Fix: CSV export/import no longer emits PHP 8.4+ `fputcsv`/`fgetcsv` deprecation output into the file
- Updates: remote status is cached in SQLite and refreshed every 12 hours; a notification appears once per new release, and the sidebar version badge and dashboard show when an update is available

## 2026-03-01
- Media/files consolidation completed:
  - `Files` is now unified into `Media Library` (single source of truth)
  - `/admin/files` now redirects to `/admin/media?type=document&view=list`
  - sidebar `Files` entry removed to avoid duplicate management flows
- Media indexing expanded to include legacy `/uploads/files` assets in the unified media registry
- Media admin actions aligned with UOP icon style (save tags, open/view, copy URL, delete, file placeholder icon)
- Media list/thumb controls aligned with UOP behavior:
  - explicit `Filters` toggle button
  - `List` / `Thumbnails` state buttons
  - bulk actions panel appears only when at least one image is selected
- Media thumbs card overflow/layout fixes applied (`min-w-0`, constrained preview/tag row)
- Content editor `Main image` tab upgraded to UOP-like workflow:
  - open in-tab media picker with existing library images
  - choose image to set `main_image` URL directly
  - upload new image from edit screen (`main_image_upload`) and auto-assign
  - clear image action + live preview sync
  - edit form switched to multipart submit to support direct media upload
- Admin shell navigation refreshed:
  - left sidebar switched to fixed-position UOP-like dark navigation style
  - left sidebar bottom actions remain pinned and no longer move with page content scroll
  - sidebar typography compacted and site tagline removed from header block
- Added fixed right utility rail (white background) with icon-only actions:
  - `Translations`, `Settings`, `Logout`
  - replaced temporary off-canvas quick-actions drawer with persistent rail

## 2026-02-14
- Default theme structure simplified to a clean CSS/Twig baseline for faster iteration
- Desktop navigation rebuilt and stabilized:
  - multi-level dropdown behavior with delayed hide
  - active link + active trail state support
  - right-aligned nav with search icon, language switcher, and mode toggle
- Mobile navigation implemented for widths below `780px`:
  - hamburger trigger in header
  - left off-canvas drawer (`~300px`) with smooth slide animation
  - close button, overlay close, ESC close, and auto-close on route change
  - accordion support for nested menu levels
  - mobile social icon section
- Header sticky behavior added:
  - appears after scroll threshold (`200px`)
  - smooth slide-down reveal
  - layout spacer handling to avoid content jump
- Footer refined:
  - footer social icons fixed and decoupled from off-canvas icon classes
  - full-width outer footer shell + constrained inner footer container
  - optional footer background color/image support via theme settings
- Hero/content layout standardized across theme:
  - full-width hero + constrained inner container for all `single-*`
  - same hero structure applied to archives, search, and 404
  - search input moved into hero area; results-only body section
- Added selectable hero variants for single templates via theme settings:
  - `default` (existing hero behavior)
  - `centered` (editorial-style centered heading/meta with media block)
  - per-content-type assignment (`pages`, `posts`, `projects`, `forms`) through `theme_settings.hero_layouts`
- Centered hero refinements:
  - removed divider line and top body gap under centered hero
  - matched image centering and spacing behavior
- Typography/spacing tuning:
  - increased `.single-body` reading size/line-height
  - removed custom `.prose-block` grid gap to rely on natural element flow
  - adjusted hero/body paddings based on visual feedback

## 2026-02-09
- Taxonomies moved to dedicated admin section (`/admin/taxonomies`) with centralized term management
- Content editor now has a separate `Taxonomies` tab with checkbox term selection (no free-text tags/categories)
- Taxonomy model now uses stable term IDs + localized labels/slugs for translation-safe archives
- Taxonomy rendering updated in theme (`taxonomy_url`, `taxonomy_label`) for translated term output
- Added full bilingual starter content set (EL/EN): core pages, service pages, legal pages, posts, and projects
- Main and footer menus updated to production-style starter structure
- Navigation changed from `Insights` to `News` category archive (`/category/news`)
- Added bilingual `contact` form content type with localized fields, submit labels, success messages, and notifications
- Embedded contact form in both contact pages using shortcode (`[form slug=\"contact\"]`)
- Menus refactored to single-file-per-menu definitions (removed language-split menu files)
- Menu editor refactored to one unified screen with inline multilingual labels per item (`label_key` + `Label EL/EN`)
- Menu namespace convention applied: `nav.main.*` for main navigation and `nav.footer.*` for footer navigation
- Admin Translations screen now hides all `nav.*` keys and preserves them on save to avoid accidental overwrite
- Theme header fallback keys updated to `nav.main.*`
- Menu labels now managed directly in Menu edit (per-language columns) instead of through Admin Translations
- Contact form shortcode rendering hardened: HTML-entity-safe shortcode parsing + language fallback when resolving forms
- Added `Settings -> Backup` tab with local snapshot management (create now, retention, download list)
- Added automatic local backup scheduling (`daily` / `weekly` / `monthly`) with last-run tracking
- Backup snapshots now stored in `storage/backups` as full-site zip archives with exclusions for runtime/system paths
- Removed Google Drive/rclone backup integration for now (including UI/actions and snapshot upload button)
- Backup UX cleanup: checkbox is the single enable/disable control; schedule dropdown no longer includes `Disabled`

## 2026-02-08
- Forms system: form builder UI, form rendering, and submissions storage
- Admin submissions tab with CSV export (filename includes site + form title)
- Notifications + auto-reply (optional submission copy)
- SMTP/AWS SES email drivers + SMTP settings tab with test email
- Settings UI reorganized into tabs (Basics/Menus/APIs/SMTP/Advanced)
- Forms: translatable validation/rate-limit error messages via theme language files
- Forms: per-form submit button label (`submit_label`) with fallback to translated `form.submit`
- Forms: per-form success message kept as unique form-level setting
- Content CSV export for all content types except forms (all languages, site/type/lang metadata)
- Content CSV import flow added (with import backups + conflict handling)
- Menus moved to dedicated admin section + settings now keep menu location mapping only
- Menus translation model aligned with content (`translation_id` linking + create/edit translation flow)
- Menus now support nested navigation up to 3 levels (admin + frontend rendering)
- Default main menu includes sample nested items (2nd/3rd level) for review
- Menus list now shows translation language availability in `Lang` column (muted indicators)
- Menu edit actions aligned with content editor layout (save/delete at top-right)
- Theme menus: active link highlighting + active parent trail for nested navigation
- Theme menus: state classes (`is-active`, `is-trail`) and top-level trail styling
- Theme menus: generic recursive macro for rendering any mapped menu location
- Admin content type detection hardened to ignore hidden/system content folders

## 2026-02-07
- Files manager (admin) with uploads to `/public/uploads/files`
- Images now stored in `/public/uploads/images` with migration + content URL rewrite
- File/media URL copy-to-clipboard UX (inline toast + focus styling)
- Media UI: delete link in metadata and smaller preview
- Markdown toolbar for editor (headings, formatting, lists, code, image, snippets)
- Added default 404 template + styling

## 2026-02-06
- Navigation builder (menus in settings + dynamic header)
- SEO helpers (dynamic `sitemap.xml`, `robots.txt`, canonical URLs)
- SEO: Open Graph fields + output, `seo.noindex` with admin toggle, and `hreflang` alternates
- Admin UX: clean defaults for new content (no SEO placeholders)
- Admin UX: translations editor for theme strings
- Admin UX: delete content (with home page protection)
- Admin UX: sidebar content types + active highlighting
- Admin UX: settings form inputs + Advanced YAML toggle + language defaults
- Admin UX: edit screen tabs (Basics/Media/SEO/Custom/Translations/Advanced)
- Admin UX: non-index pill + main-image indicator in lists (with preview tooltip)
- Admin UX: translations tab + translation_id linking (slug fallback)
- Admin UX: table-based list layout + language indicators
- Media uploads + asset manager (admin)
- Main image field for all content types
- Tag/category archives + date format setting
- Taxonomy archive template hierarchy (archive-tag/category)
- Custom fields UI (namespaced `custom_fields`)
- Excerpt field (replaces summary)
- Language defaults switched to Greek; content files realigned
