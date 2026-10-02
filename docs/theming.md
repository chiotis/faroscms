# Theming

FarosCMS has one all-purpose theme, `themes/default`. It grows over time: new blocks, header and footer variants, and page templates are added to it, and every site picks what it needs through settings. Sites are not meant to switch themes.

For step-by-step recipes (a new block, a page template, a brand colour, a content type) and the testing checklist, see [theme-developer-guide.md](theme-developer-guide.md). This page is the reference.

Two rules follow from that:

1. **Updates must never break a site.** The theme is replaced on every update, so anything a site stores or overrides has to keep working with newer theme versions.
2. **Site-specific work lives in `custom/`**, which updates never touch.

## Layers

| Layer | Where | Status |
|-------|-------|--------|
| Theme contract | `themes/default/theme.yaml` | Done |
| Design system: tokens, palettes, dark mode, fonts, corner shapes | `assets/css/site.css` | Done |
| Content blocks per page or post | `themes/default/blocks/<block>/` + `blocks:` in front matter | 23 blocks in three families; admin block editor |
| Site-level component variants (header, footer) | `components/`, Theme settings | Header: 4 layouts, transparent (per content type and per entry), sticky modes, top bar, CTA, phone bottom bar with its own list of icon links, 5 phone menu styles. Footer: 4 layouts (columns, one row, bar, centered). Header background (a solid tint) and a search box |
| Page templates (standard, landing, with sidebar) | `templates/`, `page_templates` in the manifest | Done |
| Ready-made sections and page layouts | `presets/`, `custom/presets/` | Done |
| Field definitions and archive settings per content type | `themes/default/content-types/`, `custom/content-types/`, Admin > Content types | Done |

## Folder layout

```text
themes/default/
├── theme.yaml            manifest: label, version, menu locations, settings schema
├── layouts/base.twig     HTML shell; blocks: head, header, content, footer, scripts
├── templates/            one template per page kind (hierarchy below)
├── components/           header, footer, card, form, menu macros, block wrapper, ui macros
├── blocks/<type>/        block.yaml (fields), block.twig (markup), block.css (styles)
├── content-types/<type>.yaml  fields and archive settings of a content type
├── icons/<name>.svg      icon set used by blocks (icon('name') in Twig)
├── assets/css, assets/js served at /_themes/default/<path>
└── lang/<lang>.php       shipped strings

custom/                   site-specific, never touched by updates
├── assets/css/custom.css loaded after the theme CSS when present
├── assets/js/custom.js   loaded after the theme JS when present
├── page-templates.yaml   the site's own page templates (each needs custom/templates/<name>.twig)
├── lang/<lang>.yaml      string overrides (written by Admin > Translations)
├── blocks/<type>/        new block types, or block.css added after a theme block's styles
├── content-types/<type>.yaml  fields and archive settings of a type (written by Admin > Content types)
├── icons/<name>.svg      extra icons (or replacements for theme icons)
└── templates/, components/, layouts/   overrides by relative path
```

## Template lookup

Twig searches `custom/` first, then `themes/default/`, by the same relative path. The theme itself is also available as the `@theme` namespace, so an override can extend the original and change one block:

```twig
{% extends '@theme/templates/single-post.twig' %}
{% block content %}
  {{ parent() }}
  <aside>…</aside>
{% endblock %}
```

Prefer this to copying a whole file: a copied file stops receiving theme fixes.

Template hierarchy (first existing file wins):

| Page | Candidates |
|------|------------|
| Home page | `templates/home.twig` |
| Single item | front matter `template:` → `templates/single-<type singular>.twig` → `templates/single.twig` → `templates/<type>.twig` → `templates/page.twig` / `templates/post.twig` |
| Type archive | `templates/archive-<type>.twig` → `templates/archive-<singular>.twig` → `templates/<type>_archive.twig` → `templates/archive.twig` |
| Taxonomy term | `templates/archive-<taxonomy>-<term>.twig` → `templates/archive-<taxonomy>.twig` → `templates/archive.twig` |
| Search | `templates/search.twig` |
| Not found | `templates/404.twig` |

Front matter `template:` accepts `landing`, `landing.twig`, or `templates/landing.twig`.

## Settings schema

`theme.yaml` declares the settings sections and fields. The admin Theme tab is generated from it, and stored values are checked against it on every request:

- a missing value takes the field default, so new fields need no migration;
- an invalid stored value (unknown option, out-of-range number, unsafe colour or URL) falls back to the default;
- an invalid submitted value keeps the current value;
- keys the schema does not declare are kept, so hand-added options still reach templates.

Field types: `text`, `textarea`, `email`, `url`, `image`, `color`, `number` (`min`, `max`), `select` (`options`), `toggle`. Common keys: `label`, `help`, `placeholder`, `default`, `span: full`, `hidden: true`.

Colours accept `#hex`, `rgb()/hsl()`, `var(--token)`, or a colour name. URLs and images reject quotes, brackets, whitespace, and non-http schemes. Both end up in inline styles, which is why they are strict.

Templates read settings as `theme_settings.<section>.<field>`.

## Compatibility rules for theme changes

These rules keep existing sites working after an update:

- Add fields, options, variants, and blocks freely, always with a default that reproduces the current look.
- Never rename or remove a field, option value, block type, or block field that sites may have stored. Retire a field with `hidden: true`; keep rendering old option values.
- Keep template, component, and block names stable. `custom/` overrides refer to them by path.
- Keep translation keys stable. Sites override them in `custom/lang/`.
- When a real break is unavoidable, ship a migration that rewrites stored data, and note it in `CHANGELOG.md`.

## Blocks

A page, post, or any content item can list blocks in its front matter. Without `blocks:` the item renders as before.

```yaml
blocks:
  - type: hero
    variant: split          # layout, from the block's variants
    tone: default           # default | muted | contrast | accent (background)
    spacing: default        # default | compact | spacious | none
    anchor: intro           # optional id for in-page links (#intro)
    heading: Websites that work as hard as you do.
    actions:
      - { label: Start a project, url: contact }
  - type: content           # where the item's Markdown body appears
  - type: faq
    hidden: true            # kept, not shown
```

- The first block may be a `hero`; it then renders the page's `<h1>` and the template skips its own title header. All other block headings start at `<h2>`, and their items at `<h3>`.
- If the body has text and no `content` block places it, it appears right after an opening hero (or first).
- Unknown block types and invalid values are ignored; fields take their defaults.
- Links (`type: link`): a bare path (`contact`) is relative to the current language (`/en/contact` on English pages); `/path` is from the site root; full URLs, `#anchor`, `mailto:`, and `tel:` are used as given.

First block family:

| Block | Variants | Notes |
|-------|----------|-------|
| `hero` | split, centered, cover, steps, minimal | Up to 2 buttons and 3 highlights. Opening hero images load first (`fetchpriority=high`). The `steps` layout is the cover layout with up to four numbered steps (title and text) under the buttons (each can link somewhere), like Features > Numbered steps. |
| `content` | narrow, wide | The item's Markdown body. |
| `text` | default, split, lead | Markdown text with optional buttons. |
| `text-image` | image-right, image-left | Bullet lists show check marks. Image shape: landscape, portrait, square. |
| `features` | cards, plain, numbered | Icon, title, text, and link per item; 2–4 columns. Services use the same block. |
| `stats` | row, cards | Numbers in a `<dl>`. |
| `testimonials` | grid, featured | `<figure>`/`<blockquote>`; initials when there is no photo. |
| `logos` | row, grid | Image logos or text wordmarks. |
| `columns` | image-side, image-top | Two or three columns side by side, each with a label, a heading (a link when it has an address), a picture, text and a link; stacks on a phone. |
| `faq` | stacked, split | Native `<details>`; adds `FAQPage` structured data. |
| `cta` | band, card, split | Default tone: accent. |
| `cards` | grid, list | Latest items of a content type, or hand-written cards. |
| `form` | split, stacked | Any form from Admin > Forms. |
| `gallery` | grid, masonry, strip | Opens images in an accessible viewer (`<dialog>`, arrow keys, Escape); without JavaScript the images are plain links. |
| `team` | grid, compact | Photo or initials, role, bio, email and LinkedIn links. |
| `timeline` | vertical, alternating, steps | Ordered list of dated entries. |
| `contact` | split, cards | Contact details with icons and links, optionally beside a form. |
| `map` | contained, full, split | OpenStreetMap embed with marker, no API key. By default it loads only after the visitor clicks "Show map" (no third-party request on page load); links to OpenStreetMap and directions always work. |

Third block family (dynamic and interactive):

| Block | Variants | Notes |
|-------|----------|-------|
| `latest` | cards, list, compact, overlay, strip, featured, magazine, editorial | The newest posts, projects, or any content type, from a quiet text list to a magazine layout. Options: content type, an optional category or tag (`term`), number of items, columns, and toggles for images, excerpt, and date/category. It updates by itself, and renders nothing when there is nothing to show. `cards` (above) stays for hand-written cards. |
| `video` | featured, grid, split | YouTube, Vimeo, or a direct `.mp4`/`.webm` file. By default a video opens in a large viewer (`<dialog>`, Escape or backdrop to close, focus returns to the play button); `play: inline` plays in place. Nothing is requested from YouTube or Vimeo until the visitor plays (YouTube through `youtube-nocookie.com`, Vimeo with do-not-track), and no thumbnail is fetched, so give videos a preview image. Without JavaScript the play button is a link to the video's own page. Only YouTube, Vimeo, and video files are accepted. |
| `slider` | full, banner, multi | Scroll-snap slides that swipe and scroll without JavaScript; `banner` is a wide picture with a text panel over it and up to three buttons per slide; the script adds previous/next buttons, position dots, and arrow keys. The slider can run the full width of the screen (the text inside keeps to the page width) and can carry its arrows and dots over the slides instead of below them. Optional automatic movement (off by default) has a pause button, stops on hover and focus, and never runs for visitors who prefer reduced motion. |
| `compare` | lines, striped | Comparison table: up to four columns (one can be highlighted, with a badge and a button) and up to 30 rows, some of them group headings. A cell that says `yes` or `no` (`ναι`, `όχι`) shows a check or a cross with text for screen readers; anything else is text. A real `<table>` in a focusable, named, scrolling region. |
| `before-after` | slider, side | Two pictures of one subject. `slider` lays them over each other with a handle (a native range input, so mouse, touch, and arrow keys work; without JavaScript they sit side by side). `side` shows them side by side. Labels, shape (4:3, 16:9, 4:5, square), and a caption are editable. |
| `pricing` | cards, list | Plans with badge, price, period, an "Included" list (one per line), and a button; one plan can be highlighted. `list` suits price lists. |
| `tabs` | horizontal, vertical | WAI-ARIA tabs (arrow keys, Home, End). Without JavaScript every panel is shown one after another. |
| `banner` | strip, callout | An announcement with an icon and a link. It can be dismissible; the choice is remembered in the visitor's browser for 30 days and resets when the text changes. |

The blocks showcase page (`/blocks`, hidden, admins only) shows every block and variant.

### Writing a block

Create `blocks/<type>/` with three files:

- `block.yaml`: `label`, `description`, `variants`, optional default `tone` and `spacing`, and `fields` (FieldSchema types plus `markdown`, `link`, `repeater` with `fields`/`max`, `icon`, and `options_from: content_types | forms`).
- `preview.svg` (optional): the one-colour wireframe the editor's block picker shows beside the block's name. Draw it on a 64 by 48 grid with `fill="none" stroke="currentColor"` shapes (`rect`, `path`, `circle`, `line`, `polygon`; `fill="currentColor" fill-opacity=".14"` for tinted areas). Only those shapes and a few presentation attributes are kept; a block without one shows a placeholder.
- `block.twig`: receives `block` (checked values; Markdown fields also as `<key>_html`), `heading_tag`, `item_heading_tag`, `block_uid` (use `{{ block_uid }}-title` as the heading id), `block_first`, and the page context. Import `components/ui.twig` for `section_header`, `actions`, and `initials`.
- `block.css`: styles scoped to `.block-<type>`, using the tokens from `site.css`. It is loaded only on pages that use the block, bundled with the other blocks of the page into one request.
- `block.js` (optional): progressive enhancement only; the block must work without it. Loaded deferred and bundled the same way (`/_themes/default/_blocks.js?b=…`).

### Editing blocks

Admin > Edit > **Blocks** lists the page's blocks: add (from a picker with each block's description), move, duplicate, hide, remove (with undo), and edit the fields generated from `block.yaml`, including repeaters and image fields with the media library. On save the server checks every value again (`BlockRegistry::sanitizeForStorage`) and stores only values that differ from the defaults. Unknown block types are kept unchanged. Without JavaScript the page's existing blocks are kept when it is saved.

## Content types

A content type is a folder in `content/` (`posts`, `projects`, or one you create). A **definition** adds two things: the fields an editor fills in, and how the type's archive page looks. Definitions are optional; a type without one behaves as before.

**The catalogue.** Every definition the theme ships is a ready-made type a site can have (today: posts, projects, books). **Admin → Content types → Ready-made content types** switches each on or off; pages (and forms, which belong to the CMS) are always on. A type that is off is hidden from the admin, the site, the sitemap and search, and its files are kept; switching it on again brings it back. A type counts as on when it is listed in the site settings (`content_types`) or already has files in its folder, unless it was switched off (`content_types_off`); an empty folder does not switch a prebuilt type on. To add a type to the catalogue, add `themes/<theme>/content-types/<type>.yaml` (and `templates/single-<singular>.twig` if its pages need their own layout). Types a site creates itself are not in the catalogue and are always on.

The **Books** type has the fields author, publisher, year, ISBN, language and a buy link, an archive of cards (A to Z, filtered by category), and its own page layout (`templates/single-book.twig`): the cover beside the title, author, summary (the excerpt), facts and buy button, then the text and blocks (Tabs suit reviews and editions).

Definitions live in `themes/default/content-types/<type>.yaml` (shipped with the theme) and `custom/content-types/<type>.yaml` (the site's own, kept across updates). When both exist they are merged: fields are added or changed one by one, and archive settings replace the theme's one by one. **Admin > Content types** edits the site file and writes only what differs from the theme, so theme improvements keep arriving.

```yaml
label: { el: Έργα, en: Projects }        # text, or a map per language
singular: { el: Έργο, en: Project }
fields:
  client:  { type: text, label: { el: Πελάτης, en: Client }, card: true }
  sector:
    type: select
    label: { el: Κλάδος, en: Sector }
    filterable: true                       # offered as a filter in the archive
    options: { '': '—', office: { el: Γραφεία, en: Office }, retail: Retail }
  year:    { type: number, label: Year, min: 1990, max: 2100 }
archive:
  layout: cards            # cards, list, compact, overlay, featured, magazine, editorial
  columns: '3'
  per_page: 12             # 0 shows everything on one page
  order: date_desc         # date_desc, date_asc, title_asc, title_desc, or field:<key>:asc|desc
  taxonomies: [categories] # taxonomies offered as filters
  show_image: true
  show_excerpt: true
  show_date: true
  show_meta: true          # category and card fields
```

- **Field types:** `text`, `textarea`, `markdown`, `email`, `url`, `link`, `image`, `color`, `number`, `decimal`, `date`, `select`, `toggle`. Per field: `card: true` shows it on the item's card, `show: false` hides it on the item's own page, `filterable: true` (select fields) adds an archive filter, `hidden: true` retires it. Repeaters are not available.
- **Storage:** values are saved in the item's front matter under `custom_fields`, checked against the definition on every read and write, so a stored value can never push markup into a template. Fields you declare get their own inputs in the editor (a "<Type> details" tab) and no longer appear under free-form Custom Fields.
- **Compatibility:** follow the same rules as theme settings. Never rename a field key, retire it with `hidden: true` instead (stored values are kept), and give a `select` a stable set of option values.
- **On the page:** every single page shows its declared fields as a fact sheet (`components/type-fields.twig`, included by `components/page-body.twig`). In templates, `content_fields(item)` returns the fields to print and `content_fields(item, 'card')` those marked for cards; `content_type('projects')` returns the definition.
- **Archive:** `templates/archive.twig` draws the list with the same eight layouts as the Latest content block (`components/entry-list.twig`). Filters are plain links and a `<form method="get">` (no JavaScript): `?filter[sector]=retail&page=2`. Filter values that no item has are never offered. Filtered pages are `noindex`; each page of a paginated listing has its own canonical URL. Category and tag pages use the same layout, with settings of their own per taxonomy (see below).
- **Taxonomy archives:** each taxonomy (categories, tags) has its own archive settings in `content/taxonomies/<name>.yaml` under `archive:`, edited in Admin > Taxonomies. The keys are those of a content type's archive (`layout`, `columns`, `per_page`, `order`, `show_*`, `title`, `subtitle`, `taxonomies` for filters) plus `types: [posts, projects]` for the content types listed (none means all). `{term}` in `title` and `subtitle` is replaced by the term's name. A term can also have a description per language (Admin > Taxonomies): it is shown as the subtitle unless the taxonomy sets one, and templates receive it as `term_description`. Only what differs from the defaults is stored. The templates lookup is unchanged, so `archive-category.twig` or `archive-tag-<term>.twig` can still take over.
- **Per-type templates** still work: `custom/templates/archive-<type>.twig` and `single-<type>.twig` win over the defaults.
- **Latest content block:** the block lists any type, so a new type is available in it straight away.

## Page templates

Pages and posts choose a template in Admin > Edit > Publish (stored as `template:`). The manifest's `page_templates` lists them; a template appears only if its file exists.

- **Standard** (`default`): the normal hierarchy: title header, text, blocks.
- **Landing** (`landing`): no title header; the page is its blocks. Without an opening hero the title is still the (visually hidden) `<h1>`.
- **With sidebar** (`sidebar`): title header, then the text beside a sticky sidebar with "On this page" contents (from the text's `##` headings, which get ids), the related pages from the main-menu branch the page belongs to, and a card from Theme settings > Sidebar template (text, button, phone and email). Blocks follow below at full width; a Page content block does not repeat the text.

Add a template by creating `templates/<name>.twig` and listing it under `page_templates` in the theme manifest. A site adds its own the same way without touching the theme: put `custom/templates/<name>.twig` next to a `custom/page-templates.yaml` (`name: {label: …, description: …}`); both are kept across updates.

## Ready-made sections

In the block editor, **Add block** has three tabs: single blocks, **Ready-made sections** (a few blocks that belong together, e.g. FAQ + call to action), and **Page layouts** (a whole page, optionally with a suggested template). A page layout can replace the page's blocks (with undo) or be added after them.

Presets are YAML files in `themes/default/presets/` (theme) and `custom/presets/` (site):

```yaml
kind: section            # or page
template: landing        # page layouts only, optional
label: { el: 'Ομάδα', en: 'The team' }
description: { el: '…', en: '…' }
blocks:
  el: [ { type: team, heading: 'Η ομάδα', members: [ … ] } ]
  en: [ { type: team, heading: 'The team', members: [ … ] } ]
```

Labels, descriptions, and blocks may be a single value or a map per language; the editor uses the page's language, then the default language. Theme presets hold placeholder text and no images, so they work on any site.

Editors can tick blocks and **Save as section**: the blocks are checked and written to `custom/presets/<name>.yaml` in the page's language, appear under Ready-made sections with a delete button, and are included in backups (Site customizations).

## Header and footer

Theme settings > Header:

- **Layout**: classic (logo left, menu right), centered (logo in the middle, menu below), minimal (logo and a menu button on all screens), stacked (menu in a full-width bar underneath, with a background of its own). **Header background** (page background, muted, or a soft tint of the palette colour) colours the row with the logo; **Search** can be an icon or a search box in the header; in the stacked bar the page you are on is highlighted (a palette can set `--bar-highlight`, Garnet uses gold). **Footer layout Bar**: the copyright on the left and the links on the right, one thin line between them. All of these are solid colours.
- **Transparent over an opening hero**: on pages that start with a Hero block the header overlays it (light text over a cover image or dark hero) and turns solid when the page scrolls.
- **Sticky**: slides in after scrolling, always at the top, or scrolls away.
- **Button**: a CTA in the header and the mobile menu.
- **Top bar**: a short message and/or phone and email (from the Footer section).
- **Bottom bar on phones**: menu, call, email, and the CTA fixed at the bottom of small screens.

Theme settings > Footer > Layout: columns, one row, or centered.

A site-specific block goes in `custom/blocks/<type>/` with the same files.

## Design tokens

`site.css` defines semantic tokens (`--color-bg`, `--color-surface`, `--color-text`, `--color-muted`, `--color-border`, `--accent`, `--accent-soft`, `--accent-contrast`, spacing `--space-*`, type scale `--step-*`, radii `--radius-*`). Theme settings switch them through attributes on `<html>`:

- `data-glow`: `solid` removes the decorative glows (Appearance > Decoration);
- `data-theme`: palette (slate, indigo, emerald, teal, rose, amber, garnet), with light and dark variants of each accent;
- `data-mode` and the `.dark` class: colour mode, following the system until the visitor chooses;
- `data-font`: sans (Inter), display (serif headings with Inter text), serif, or system (no font download);
- `data-shape`: soft, rounded, or sharp corners.

Block tones re-scope the same tokens, so every component works on every background. Text and accent pairs meet WCAG AA contrast in all palettes, in light and dark mode.

## Fonts

Inter ships with the theme (`assets/fonts/inter/`, SIL Open Font License) as a variable font (weights 300–800) split into Latin, Latin Extended, Greek, and Greek Extended files. `unicode-range` makes a page download only the files its text needs, and the layout preloads Latin (and Greek on Greek pages). Nothing is loaded from Google or another third party. A site that wants no web font chooses "System fonts" in Theme settings.

## Images

`image(src, options)` renders responsive markup for files in `/uploads`:

- intrinsic `width`/`height` (no layout shift);
- a WebP `srcset` (360–2400 px) with the original as fallback;
- `loading="lazy"` by default, `priority: true` for above-the-fold images (eager, `fetchpriority=high`);
- `alt`: the given text, `''` for decorative images, or the media library's alt text when omitted.

Variants are created on first request at `/uploads/_v/<file>/<width>-<version>.webp` and then served as static files. The version comes from the source file's modification time, so replacing a file changes its URLs. Variants are excluded from backups and removed when the media item is deleted. Alt text is edited in Admin > Media.

## SEO

The layout outputs the title (`Page | Site`, or the SEO title), meta description, canonical URL, `hreflang` alternates, Open Graph (`og:type`, `og:site_name`, `og:locale` and alternates, image), and Twitter card tags. The share image falls back from the SEO image to the main image, the first block image, and finally Theme settings > Brand.

Structured data is one JSON-LD graph per page:

- `Organization` everywhere: name, logo, social profiles (`sameAs`) and, when the Footer has a phone or an email, a `ContactPoint`.
- `WebSite` on the home page of each language, with a `SearchAction` that points at that language's search page.
- `BlogPosting` for a post and `Article` for a project: headline (cut at 110 characters), address, language, publisher, the author (the person named in the editor, otherwise the organization), `datePublished` and `dateModified`, the summary, and a picture (the entry's main image, then its SEO share image, then the first picture in its blocks, then the site's default share image, as a full address).
- `WebPage` for every other page, pointing at the site.
- `BreadcrumbList` on inner pages, and `FAQPage` from FAQ blocks.

Text is escaped so nothing typed in the admin can end the script tag. Templates can add nodes through the `structured_data` variable or override `{% block structured_data %}`.

## Accessibility

- Skip link to `<main id="main">`, visible focus styles, and `prefers-reduced-motion` support.
- One `<h1>` per page and no skipped heading levels (blocks and cards pick their level from the page).
- Mobile menu: a dialog with `aria-expanded`, focus moved in, kept in, and returned on close; Escape closes it.
- Cards and feature items have one link each (the title), stretched over the item.
- Forms: labels for every control, `aria-describedby` for help and errors, `aria-invalid`, announced status messages, and `autocomplete` hints.
- Social links render only when set in Theme settings (no `#` placeholders).
- Submenus open on hover and focus and close with Escape (focus returns to the parent link); overlays (viewers, the mobile menu) return focus to what opened them.
- Markdown tables are supported and sit in a keyboard-focusable box that scrolls sideways on narrow screens, so no page needs horizontal scrolling at 320 px.
- Blocks without their own heading give their items `<h2>`, so levels never skip.
- `scripts/theme-audit.js` checks pages with axe-core (WCAG 2.2 AA), reflow at 320 px, and the contrast of the theme's colour pairs in all palettes; see the developer guide. It can scan at a phone width and with the phone menu open (`themeAudit.pages(['/'], {width: 375, menu: true})`).
- The phone menu (Theme settings > Header > Phone menu: side drawer from the left or right, full screen, sheet from the top or bottom) is one panel outside the header, so a sticky, blurred, or transparent header never changes how it looks. While it is open the rest of the page is inert, focus stays inside, Escape closes it, and focus returns to the button that opened it. The showcase page `/blocks`, every content page, and the interactive states (mobile menu, viewers, open FAQ) were checked in light and dark.

## Server configuration

Theme assets and image variants go through `public/index.php` when no file exists. Apache needs nothing beyond the shipped `.htaccess`. On nginx every location that serves static files must end in `try_files $uri /index.php?$query_string;` (see `security.md`). For local development pass the router: `php -S 127.0.0.1:8087 -t public public/index.php`.

## Assets

Theme and custom assets live outside `public/` and are served by `ThemeAssets` from `public/index.php` before the application boots. Only static types are served: css, js, map, json, images, and fonts. URLs carry a `?v=` version from the file's mtime and size, and versioned responses are cached for a year. Block stylesheets of a page are served as one bundle (`/_themes/default/_blocks.css?b=hero,faq`).

In templates:

```twig
<link rel="stylesheet" href="{{ theme_asset('css/site.css') }}">
{% set extra = custom_asset('css/custom.css') %}{# '' when the file does not exist #}
```

## Translations

Strings resolve in this order, later wins:

1. the theme's default-language file
2. the site's overrides for the default language
3. the theme's file for the requested language
4. the site's overrides for the requested language

Admin > Translations saves only the strings that differ from what the theme provides. Resetting a string removes the override, so the theme text (and its future fixes) applies again.

## Backups

Full backups include `custom/`. The restore screen offers it as **Site customizations**.

## Icons

Icons are SVG files in `themes/<theme>/icons/` (and `custom/icons/`, which wins). A field of `type: icon` in `block.yaml` or in the `settings` of `theme.yaml` offers exactly that set, and the admin shows it as a popup of small pictures (`public/assets/js/admin-icons.js`). Use `{{ icon('name') }}` in templates; a name that does not exist prints nothing.
