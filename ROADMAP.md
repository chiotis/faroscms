# Roadmap

How to read this file: **Now** is what to do before adding more, **Next** is the order of the work worth doing, **Later** is wanted but not urgent, **Not planned** says what was decided against. What has been delivered is in `CHANGELOG.md` (by version) and in the documents under `docs/`; the list under "Where we are" is only the shape of it. Items marked *(proposal)* are a suggested order, not a decision.

## Where we are (0.1.63)

- **Theme**: one all-purpose theme (`themes/default`) that grows with blocks and variants; site changes live in `custom/`. Design system (tokens, palettes, dark mode, Inter, shapes), header (7 layouts), footer (5), phone menu and bottom bar, page templates (standard, landing, with sidebar), hero layouts, single and archive layouts per content type, branding screen. Contract: `docs/theming.md`.
- **Blocks**: 35 block types: openers, text, media (image, gallery, slider, video, YouTube playlist), showcase (cards, portfolio with filters, logos, marquee, team, timeline, features, stats, compare, before/after), conversion (pricing with a monthly/yearly switch, forms, contact, map, banner, CTA), downloads, checklist, table, quote, divider, tabs, FAQ. 18 page layouts and 15 ready-made sections, in Greek and English.
- **Content**: Markdown files plus front matter; a visual editor over Markdown (saved as Markdown, always); content types with declared fields (posts, projects, books, points of interest, routes, businesses); taxonomies including the site's own; revision history; redirects; slugs.
- **Places and routes**: route files (GPX, KML, GeoJSON) read for length, climb and a height profile; interactive maps (Leaflet, no third party but the tiles); a Map archive layout and a Map block that show content with filters and a list, also with the list over a full-width map; a page for each point, route and business with what is near it. `docs/places-and-routes.md`.
- **Admin**: dashboard with what needs attention and the last 90 days of the site's own analytics; block editor (compact, Content and Design apart); media library with picker, usage tracking and route files; users, custom roles and permissions; forms builder and submissions (filters, paging, bulk delete); SEO, analytics, redirects, translations, backups, updates, logs, storage and upload limits. Accessibility scanned with axe-core in light, dark and phone width (`docs/admin-accessibility.md`).
- **Operations**: backups with a checksum manifest, verify, staged restore and S3-compatible remote copies; updates installed from the admin with a verified backup first, a check of the new version and an automatic undo; the whole suite on PHP 8.1, 8.3 and 8.5 on every push (GitHub Actions).
- **Architecture**: `src/App.php` went from 9,800 lines to about 3,900 (it grew again with features); each extracted service has unit tests. `docs/architecture.md`.

## Now: before adding more

Several releases were merged with only the tests of the change run, and CI was red on ten of them without anyone reading it. The suite and CI were read and mended in 0.1.62 (see `CHANGELOG.md`), and the visual editor's serializer has its own test. What is left of the debt is below; and from now on a merge is not finished until CI on `main` is green.

1. **Look at what was only built, not seen**: dark mode and phone width of the visual editor, the block editor and the maps; Safari, Firefox and touch; the map with the list over it in an archive; the YouTube Data API with a real key; real GPX files from a device (a long recording, one with no heights, one in KML from Google Earth). Screen readers by hand are still to do.
2. **Known rough edges of the new maps** (small): the automatic text in a popup starts with the entry's headings when there is no excerpt; "near" lists share one limit for points and businesses, so a crowded route can push the points out (the limit should be per kind); the Map block's `list: below` means nothing over a map (read as right).

## Next: in this order *(proposal)*

1. **Form translation (to rethink).** A translation should not be a new form with its own builder, but a translation of the same form. Found while testing: a change made to the Greek form did not reach the English one, so the two drift apart. Wanted: the form is built once, in the first language only; every other language is a set of translation settings on each field (label, placeholder, help text, option labels, button text, messages), edited from the field's inspector with a language switch. Adding or removing a field or option happens once and shows up in every language, with missing translations falling back to the first language and flagged. This replaces the older "clone fields/options into a new language version" idea. Submissions and emails use the language the form was shown in. *Why first:* it fixes a design that loses data, and every multilingual site with a form has it.
2. **Places and routes, second phase.** What the first version left out, in the order it matters for the sites it was made for:
   - a gallery for an entry (a list of pictures, with a Library that adds several at once) and a strip of it under the title, as on the reference business page;
   - a way to say that a point belongs to a route (a list of related entries that wins over distance), and a "nearby" limit for each kind;
   - filters of the type (activity, difficulty, price range) inside the map's own bar, instantly, with counts;
   - the profile of the height linked to the map (a mark on the line under the pointer), and the places a route file marks as entries of their own on request;
   - custom single templates for a site (the three templates are three lines each; document how to start from them).
3. **Runtime cache** (see Caching below). The map pages compute what is near what on every visit and read the route cache of every route; with hundreds of places that becomes the slowest page of the site. The marker datasets are named in layer 2 of the plan, so build the layers with that in mind.
4. **Search improvements** (weighted fields, highlights; places by area and category). Bigger content sites need it before they need anything else here.
5. **Menus: drag and drop** ordering in the menu editor (instead of the row order and the manual level select).

## Later

- **Forms**: showing a field only when another has an answer (conditional logic), file upload fields (the storage limit, the file types and where the files are kept need deciding), forms in several steps, and a rating field. The builder, the site's form template and `FormProcessor` would all need to know about a condition, so it is a feature of its own. Submissions: archive actions. Optional: frontend form theme variants (compact/stacked) without changing the form schema.
- **Nested repeaters** in blocks and content type fields: a repeater inside a repeater item (for example several buttons for each slide of a slider). Today `FieldSchema` keeps repeater items flat and drops a repeater field inside one, so the slider's three buttons are flat fields (`link_label`, `link_label_2`, `link_label_3`). Needs: the schema and its checks, the editor (add, remove and reorder inner rows), storage, the CSV and revision handling, and the block templates. Found while moving a WordPress site over (see `docs/wordpress-import.md`).
- **Import UX**: dry-run preview, row-level undo, resumable imports.
- **Theme**: a switcher in settings with a preview mode; draft preview links; admin-level user management with limits; updates for private repositories. Style references from the owner can refine the design system at any point (tokens and block CSS).
- **Taxonomies**: term images and meta, parent terms for categories, per-content-type assignment rules.
- **Menus**: per-item visibility (by language, and by role in future); item metadata (icon, badge, `rel`); a key helper for the `nav.<menu>.<item>` naming.
- **Backups**: a one-click restore-to-staging drill with a periodic restore report; Google Drive as a remote target.
- **Settings > APIs**: credentials for services the site may use for generated excerpts and content (nothing uses them yet); other map providers' presets.
- **Architecture**: keep splitting `src/App.php` (wiring, permission checks, rendering and the Twig functions are what is left), behaviour identical, with a regression check for each step.

## Caching

Runtime cache only (no static export pipeline), filesystem backend by default (portable, no extra services).

- Layer 1: parsed-content cache (front matter and rendered Markdown)
- Layer 2: data and index cache (archives, taxonomies, map marker datasets, what is near what)
- Layer 3: full-page HTML cache by route and language (and query variants where needed)
- Deterministic keys including route, language, content type and theme
- Targeted invalidation on save, delete and import: the item page, related archives and taxonomies, the home page and widgets, the sitemap and feed, and linked translations (`translation_id`); a route file replaced in Media invalidates the routes that use it and the maps that show them
- A TTL as a safety net; invalidation stays the main way to stay fresh

## Not planned

- A static export pipeline.
- Editing route files in the admin: a file is uploaded and chosen (`docs/places-and-routes.md`).
