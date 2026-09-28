# Theming

FarosCMS has one all-purpose theme, `themes/default`. It grows over time: new blocks, header and footer variants, and page templates are added to it, and every site picks what it needs through settings. Sites are not meant to switch themes.

Two rules follow from that:

1. **Updates must never break a site.** The theme is replaced on every update, so anything a site stores or overrides has to keep working with newer theme versions.
2. **Site-specific work lives in `custom/`**, which updates never touch.

## Layers

| Layer | Where | Status |
|-------|-------|--------|
| Theme contract | `themes/default/theme.yaml` | Done |
| Design system: tokens, palettes, dark mode, fonts, corner shapes | `assets/css/site.css` | Done |
| Content blocks per page or post | `themes/default/blocks/<block>/` + `blocks:` in front matter | 17 blocks in two families; admin block editor |
| Site-level component variants (header, footer) | `components/`, Theme settings | Header: 4 layouts, transparent, sticky modes, top bar, CTA, phone bottom bar. Footer: 3 layouts |
| Page templates beyond the hierarchy (landing, sidebar) | `templates/` | Later |
| Field definitions per content type | content type config | Later |

## Folder layout

```text
themes/default/
├── theme.yaml            manifest: label, version, menu locations, settings schema
├── layouts/base.twig     HTML shell; blocks: head, header, content, footer, scripts
├── templates/            one template per page kind (hierarchy below)
├── components/           header, footer, card, form, menu macros, block wrapper, ui macros
├── blocks/<type>/        block.yaml (fields), block.twig (markup), block.css (styles)
├── icons/<name>.svg      icon set used by blocks (icon('name') in Twig)
├── assets/css, assets/js served at /_themes/default/<path>
└── lang/<lang>.php       shipped strings

custom/                   site-specific, never touched by updates
├── assets/css/custom.css loaded after the theme CSS when present
├── assets/js/custom.js   loaded after the theme JS when present
├── lang/<lang>.yaml      string overrides (written by Admin > Translations)
├── blocks/<type>/        new block types, or block.css added after a theme block's styles
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
| `hero` | split, centered, cover, minimal | Up to 2 buttons and 3 highlights. Opening hero images load first (`fetchpriority=high`). |
| `content` | narrow, wide | The item's Markdown body. |
| `text` | default, split, lead | Markdown text with optional buttons. |
| `text-image` | image-right, image-left | Bullet lists show check marks. Image shape: landscape, portrait, square. |
| `features` | cards, plain, numbered | Icon, title, text, and link per item; 2–4 columns. Services use the same block. |
| `stats` | row, cards | Numbers in a `<dl>`. |
| `testimonials` | grid, featured | `<figure>`/`<blockquote>`; initials when there is no photo. |
| `logos` | row, grid | Image logos or text wordmarks. |
| `faq` | stacked, split | Native `<details>`; adds `FAQPage` structured data. |
| `cta` | band, card, split | Default tone: accent. |
| `cards` | grid, list | Latest items of a content type, or hand-written cards. |
| `form` | split, stacked | Any form from Admin > Forms. |
| `gallery` | grid, masonry, strip | Opens images in an accessible viewer (`<dialog>`, arrow keys, Escape); without JavaScript the images are plain links. |
| `team` | grid, compact | Photo or initials, role, bio, email and LinkedIn links. |
| `timeline` | vertical, alternating, steps | Ordered list of dated entries. |
| `contact` | split, cards | Contact details with icons and links, optionally beside a form. |
| `map` | contained, full, split | OpenStreetMap embed with marker, no API key. By default it loads only after the visitor clicks "Show map" (no third-party request on page load); links to OpenStreetMap and directions always work. |

The blocks showcase page (`/blocks`, hidden, admins only) shows every block and variant.

### Writing a block

Create `blocks/<type>/` with three files:

- `block.yaml`: `label`, `description`, `variants`, optional default `tone` and `spacing`, and `fields` (FieldSchema types plus `markdown`, `link`, `repeater` with `fields`/`max`, `icon`, and `options_from: content_types | forms`).
- `block.twig`: receives `block` (checked values; Markdown fields also as `<key>_html`), `heading_tag`, `item_heading_tag`, `block_uid` (use `{{ block_uid }}-title` as the heading id), `block_first`, and the page context. Import `components/ui.twig` for `section_header`, `actions`, and `initials`.
- `block.css`: styles scoped to `.block-<type>`, using the tokens from `site.css`. It is loaded only on pages that use the block, bundled with the other blocks of the page into one request.
- `block.js` (optional): progressive enhancement only; the block must work without it. Loaded deferred and bundled the same way (`/_themes/default/_blocks.js?b=…`).

### Editing blocks

Admin > Edit > **Blocks** lists the page's blocks: add (from a picker with each block's description), move, duplicate, hide, remove (with undo), and edit the fields generated from `block.yaml`, including repeaters and image fields with the media library. On save the server checks every value again (`BlockRegistry::sanitizeForStorage`) and stores only values that differ from the defaults. Unknown block types are kept unchanged. Without JavaScript the page's existing blocks are kept when it is saved.

## Header and footer

Theme settings > Header:

- **Layout**: classic (logo left, menu right), centered (logo in the middle, menu below), minimal (logo and a menu button on all screens), stacked (menu in a full-width bar underneath, with a background of its own).
- **Transparent over an opening hero**: on pages that start with a Hero block the header overlays it (light text over a cover image or dark hero) and turns solid when the page scrolls.
- **Sticky**: slides in after scrolling, always at the top, or scrolls away.
- **Button**: a CTA in the header and the mobile menu.
- **Top bar**: a short message and/or phone and email (from the Footer section).
- **Bottom bar on phones**: menu, call, email, and the CTA fixed at the bottom of small screens.

Theme settings > Footer > Layout: columns, one row, or centered.

A site-specific block goes in `custom/blocks/<type>/` with the same files.

## Design tokens

`site.css` defines semantic tokens (`--color-bg`, `--color-surface`, `--color-text`, `--color-muted`, `--color-border`, `--accent`, `--accent-soft`, `--accent-contrast`, spacing `--space-*`, type scale `--step-*`, radii `--radius-*`). Theme settings switch them through attributes on `<html>`:

- `data-theme`: palette (slate, indigo, emerald, teal, rose, amber), with light and dark variants of each accent;
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

Structured data is one JSON-LD graph per page: `Organization` (with logo and social profiles from Theme settings) everywhere, `WebSite` with a search action on the home page, `BlogPosting` for posts, `BreadcrumbList` on inner pages, and `FAQPage` from FAQ blocks. Templates can add nodes through the `structured_data` variable or override `{% block structured_data %}`.

## Accessibility

- Skip link to `<main id="main">`, visible focus styles, and `prefers-reduced-motion` support.
- One `<h1>` per page and no skipped heading levels (blocks and cards pick their level from the page).
- Mobile menu: a dialog with `aria-expanded`, focus moved in, kept in, and returned on close; Escape closes it.
- Cards and feature items have one link each (the title), stretched over the item.
- Forms: labels for every control, `aria-describedby` for help and errors, `aria-invalid`, announced status messages, and `autocomplete` hints.
- Social links render only when set in Theme settings (no `#` placeholders).

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
