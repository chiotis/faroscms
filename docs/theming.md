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
| Site-level component variants (header, footer) | `components/`, Theme settings | Header: 7 layouts, floating or full width, height, edge, background, menu link style, transparent (per content type and per entry), sticky modes (including smart), top bar, CTA, search, phone bottom bar with its own list of icon links, 5 phone menu styles. Footer: 5 layouts, 4 backgrounds, a call to action band, bottom links, language switcher. Both are cards with a picture of the result in Theme |
| Page templates (standard, landing, with sidebar) | `templates/`, `page_templates` in the manifest | Done |
| Points, routes, businesses and maps | `content-types/`, `blocks/map/`, `components/place-single.twig`, `assets/vendor/leaflet/` | Done (docs/places-and-routes.md) |
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

Field types: `text`, `textarea`, `email`, `url`, `image`, `video` (a video address: the media library's videos, a YouTube or Vimeo link, or a file elsewhere), `file` (a file to download: the media library's files of any kind, or a file elsewhere), `color` (`hex: true` accepts only `#rgb` or `#rrggbb`, stored as `#rrggbb`), `number` (`min`, `max`; `blank: true` lets it be empty, meaning "not set"), `decimal`, `select` (`options`), `toggle`. Common keys: `label`, `help`, `placeholder`, `default`, `span: full`, `hidden: true`, and `when: {other_field: value}` (or a list of values), which makes the block editor show the field only while the other has one of those values.

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
| `hero` | split, centered, cover, steps, minimal | Up to 2 buttons and 3 highlights. Opening hero images load first (`fetchpriority=high`). The `steps` layout is the cover layout with up to four numbered steps (title and text) under the buttons (each can link somewhere), like Features > Numbered steps. The picture can be a **video** instead of an image (Picture > Video): a file from the media library (`.mp4`, `.webm`), a file on another site, or a YouTube or Vimeo link. It plays silently, in a loop, behind the text of the cover and steps layouts and in the frame of the split and centered ones, with a pause button, a still image under it (and when a visitor's device asks for less motion or less data, where it starts only on request), and it rests while off screen. YouTube and Vimeo are put in a frame by the page's script after it has loaded (YouTube through youtube-nocookie.com, Vimeo in its background mode), so a page asks nothing of them until then; a video file is a plain `<video>`. |
| `content` | narrow, wide | The item's Markdown body. |
| `text` | default, split, lead, contents | Markdown text with optional buttons. `contents` puts a list of links made from the text's `##` headings beside it (headings get addresses), for terms, privacy pages and long guides; with fewer than two headings there is no list. |
| `text-image` | image-right, image-left | Bullet lists show check marks. Image shape: landscape, portrait, square. |
| `features` | cards, plain, numbered | Icon, title, text, and link per item; 2–4 columns. Services use the same block. |
| `stats` | row, cards | Numbers in a `<dl>`. |
| `testimonials` | grid, featured | `<figure>`/`<blockquote>`; initials when there is no photo. |
| `logos` | row, grid | Image logos or text wordmarks, spread over the whole width of the content however many there are (one row up to the most that fit; more in rows of the same length). `size`: the height of the logos, small (2.5 rem), medium (4 rem, the default), large or extra large; the taller they are the fewer fit in a row (7, 5, 4 or 3), and on a phone the heights are smaller. `colors`: black and white with colour on hover (the default), always black and white, or their own colours. |
| `columns` | image-side, image-top | Two or three columns side by side, each with a label, a heading (a link when it has an address), a picture, text and a link; stacks on a phone. |
| `faq` | stacked, split | Native `<details>`; adds `FAQPage` structured data. |
| `cta` | band, card, split | Default tone: accent. |
| `cards` | grid, list | Latest items of a content type, or hand-written cards. |
| `form` | split, stacked | Any form from Admin > Forms. |
| `gallery` | grid, masonry, strip | Opens images in an accessible viewer (`<dialog>`, arrow keys, Escape); without JavaScript the images are plain links. |
| `team` | grid, compact | Photo or initials, role, bio, email and LinkedIn links. |
| `timeline` | vertical, alternating, steps | Ordered list of dated entries. |
| `contact` | split, cards | Contact details with icons and links, optionally beside a form. |
| `map` | contained, full, split | OpenStreetMap embed with marker, no API key. By default it loads only after the visitor clicks "Show map" (no third-party request on page load); links to OpenStreetMap and directions always work. With **Show** set to a content type (or everything with a place) it is a map of those entries with filters, search, a list and optional grouping of close markers (see `docs/places-and-routes.md`). |

Third block family (dynamic and interactive):

| Block | Variants | Notes |
|-------|----------|-------|
| `latest` | cards, list, compact, overlay, strip, featured, magazine, editorial | The newest posts, projects, or any content type, from a quiet text list to a magazine layout. Options: content type, an optional category or tag (`term`), number of items, columns, and toggles for images, excerpt, and date/category. It updates by itself, and renders nothing when there is nothing to show. `cards` (above) stays for hand-written cards. |
| `video` | featured, grid, split | YouTube, Vimeo, or a direct `.mp4`/`.webm` file. By default a video opens in a large viewer (`<dialog>`, Escape or backdrop to close, focus returns to the play button); `play: inline` plays in place. Nothing is requested from YouTube or Vimeo until the visitor plays (YouTube through `youtube-nocookie.com`, Vimeo with do-not-track), and no thumbnail is fetched, so give videos a preview image. Without JavaScript the play button is a link to the video's own page. Only YouTube, Vimeo, and video files are accepted. |
| `playlist` | grid, list, featured, strip | The videos of a YouTube playlist, drawn by the site from the playlist's public feed (15 newest videos) or, with a key in Settings > APIs, the YouTube Data API (up to 50, with lengths). The editor chooses the layout (grid with 2 to 4 columns, list with descriptions, a player with the list beside it, a scrolling strip), the order, how many, what to show (description, length, views, date, numbers), the picture shape, how a video plays (viewer, in place, or on YouTube), and a link to the playlist. Pictures are kept on this site by default. It shares the picture, play button and viewer of `video`. See `docs/youtube-playlist.md`. |
| `slider` | full, banner, multi | Scroll-snap slides that swipe and scroll without JavaScript; `banner` is a wide picture with a text panel over it and up to three buttons per slide; the script adds previous/next buttons, position dots, and arrow keys. The slider can run the full width of the screen (the text inside keeps to the page width) and can carry its arrows and dots over the slides instead of below them. Optional automatic movement (off by default) has a pause button, stops on hover and focus, and never runs for visitors who prefer reduced motion. |
| `compare` | lines, striped | Comparison table: up to four columns (one can be highlighted, with a badge and a button) and up to 30 rows, some of them group headings. A cell that says `yes` or `no` (`ναι`, `όχι`) shows a check or a cross with text for screen readers; anything else is text. A real `<table>` in a focusable, named, scrolling region. |
| `before-after` | slider, side | Two pictures of one subject. `slider` lays them over each other with a handle (a native range input, so mouse, touch, and arrow keys work; without JavaScript they sit side by side). `side` shows them side by side. Labels, shape (4:3, 16:9, 4:5, square), and a caption are editable. |
| `pricing` | cards, list | Plans with badge, price, period, an "Included" list (one per line), and a button; one plan can be highlighted. `list` suits price lists. **Monthly / yearly switch** (`billing_switch`): each plan can have a `yearly_price` and `yearly_period` ("per year" when empty); the switch, with an optional note such as "Save 20%" on the yearly button, is shown by the page's script, and without JavaScript a plan shows both prices. A plan with no yearly price is the same either way. |
| `tabs` | horizontal, vertical | WAI-ARIA tabs (arrow keys, Home, End). Without JavaScript every panel is shown one after another. |
| `banner` | strip, callout | An announcement with an icon and a link. It can be dismissible; the choice is remembered in the visitor's browser for 30 days and resets when the text changes. |
| `image` | wide, narrow, full | One picture with a caption and an optional link (which can open in a new tab). The shape is Original, 16:9, 4:3, square, 4:5 or 21:9, with rounded corners or not; `full` runs edge to edge with the caption inside the page's margins. A block with no picture shows nothing. |
| `divider` | space, line, label | Space between sections (small to extra large), a rule (solid, dashed or dotted; page, text or short width) or a rule with a label. A space is hidden from screen readers. |
| `quote` | centered, bar, photo | One large quotation with a name, a role and (in `photo`) a picture. `<figure>` with a `<blockquote>`; the quotation mark is decoration. |
| `downloads` | list, cards | Documents to download. A row is a file picked from the media library (the **file** field), a title (the file name when empty), a short description, and a size (read from the file when it is one of the site's uploads, or typed). The kind of file is its extension. A file on another site opens in a new tab. A row with no file is hidden from visitors; someone who is signed in sees it dashed, saying why (so a ready-made page can be filled in). |
| `checklist` | plain, cards, split | Points marked with a tick or any icon of the set, each with an optional detail line; a point can be **not included** (a cross, muted, struck through, and announced as such). 1 to 3 columns. |
| `portfolio` | grid, overlay | A grid of work with **filter buttons** for the categories. Type the pieces in (title, categories separated by commas, picture, text, link) or show the entries of a content type, filtered by the categories they are filed under. The buttons are made of the categories in the order they first appear, are shown by the page's script, and do nothing to a page without it (everything shows). One category, or the switch off, means no buttons. |
| `table` | lines, striped, boxed | A data table. Column titles in one line, rows one to a line, cells divided by `\|` or by a tab (a range copied from a spreadsheet can be pasted as it is). `**bold**` and `[links](/address)` work in a cell, other markup is shown as text. A column that holds only figures (money, percentages, signs) is aligned to the right. Up to 100 rows and 12 columns; wide tables scroll inside a frame that the keyboard can reach. |
| `marquee` | text, logos | A band that scrolls by itself: big words with a dot between them, or logos (their names when there is no image). Slow, normal or fast, to the left or the right. It runs on CSS alone, stops while the mouse or the keyboard is on it, has a pause button, and for visitors who ask for less motion it is still and wraps. |

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

**The catalogue.** Every definition the theme ships is a ready-made type a site can have (today: posts, projects, books, points, routes, businesses). **Admin → Content types** shows every type as a card (its fields, entries and list layout, who defines it) with a switch for each ready-made type; pages (and forms, which belong to the CMS) are always on. A type that is off is hidden from the admin, the site, the sitemap and search, and its files are kept; switching it on again brings it back. A type counts as on when it is listed in the site settings (`content_types`) or already has files in its folder, unless it was switched off (`content_types_off`); an empty folder does not switch a prebuilt type on. To add a type to the catalogue, add `themes/<theme>/content-types/<type>.yaml` (and `templates/single-<singular>.twig` if its pages need their own layout). Types a site creates itself are not in the catalogue and are always on.

**Points of interest, Routes and Businesses** are ready-made too, for sites about places and tourist routes: a position on every entry, route files (GPX, KML, GeoJSON) with their length, climb and profile of the height, an archive layout that is a **map**, a **Map block** that shows them, and a page for each with a map and what is near. See `docs/places-and-routes.md`.

The **Books** type has the fields author, publisher, year, ISBN, language and a buy link, an archive of cards (A to Z, filtered by category), and its own page layout (`templates/single-book.twig`): the cover beside the title, author, summary (the excerpt), facts and buy button, then the text and blocks (Tabs suit reviews and editions).

Definitions live in `themes/default/content-types/<type>.yaml` (shipped with the theme) and `custom/content-types/<type>.yaml` (the site's own, kept across updates). When both exist they are merged: fields are added or changed one by one, and archive settings replace the theme's one by one. **Admin > Content types** edits the site file and writes only what differs from the theme, so theme improvements keep arriving. The editor of a type has a row for each field: a key that never changes, a label, a kind and, for a field you added, a help text and (for a select) its options, with the ways it is used (*Filter*, *Card*, *Page*, *Retired*) as chips. A field you added can be removed (the row is dimmed until you save, and *Undo* brings it back); the values already saved in content are kept.

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
- **Taxonomies of the site's own:** **Admin > Taxonomies > New taxonomy** makes one (a name, an address, optionally the content types its pages list): `content/taxonomies/<name>.yaml`, the same file as categories and tags. Its terms have pages at `/<name>/<term>` (and `/en/<name>/<term>`), drawn by `templates/archive-<name>-<term>.twig` → `archive-<name>.twig` → `archive.twig`, titled "Name: Term", with the same layout settings (Theme > Archive Layouts) and hreflang links, and a term that changes address leaves a redirect behind. `/<name>` alone is an ordinary page address, so a page can be made there. The name cannot be a reserved word, a language, a content type, another taxonomy, the home page or a page; a content type cannot take the name of a taxonomy. Entries keep their terms in the field named like the taxonomy (`project-types: [web]`), the menu editor offers the terms, and the default single templates of posts and projects list them under the title; a template draws them with `item_terms(item, prefix)` (`[{name, title, terms: [{label, url}]}]`, for the taxonomies a site added) or `taxonomy_url('<name>', term, prefix)` and `taxonomy_label(...)`.
- **Taxonomy archives:** each taxonomy (categories, tags) has its own archive settings in `content/taxonomies/<name>.yaml` under `archive:`, edited in Admin > Theme > Archive Layouts. The keys are those of a content type's archive (`layout`, `columns`, `per_page`, `order`, `show_*`, `title`, `subtitle`, `taxonomies` for filters) plus `types: [posts, projects]` for the content types listed (none means all). `{term}` in `title` and `subtitle` is replaced by the term's name. A term can also have a description per language (Admin > Taxonomies): it is shown as the subtitle unless the taxonomy sets one, and templates receive it as `term_description`. Only what differs from the defaults is stored. The templates lookup is unchanged, so `archive-category.twig` or `archive-tag-<term>.twig` can still take over.
- **Per-type templates** still work: `custom/templates/archive-<type>.twig` and `single-<type>.twig` win over the defaults.
- **Latest content block:** the block lists any type, so a new type is available in it straight away.

## Single layouts and archive layouts

**Admin > Theme > Single Layouts** has one card for each content type the site has (pages, posts, projects, forms, books, and any type added later gets its card by itself). A card sets, for the page of one entry of that type:

- the **page layout**: one of the page templates below (Standard, Landing, or one a site adds). A type whose own template is not the standard one (the book page, `single-book.twig`) has no page layout and no title area to style; its card says so and links to the fields of the type;
- the **title area**: Default, Centered, Split, Cover or Minimal (the same five as the Hero block), and whether the header sits over it (Site default, Over the title, Solid);
- what shows: the image, the excerpt, and for the templates that print a line above the title (posts and projects) the date and terms;
- the **sidebar**: None, Right or Left, whatever the page layout (a landing page has none). With one, the page uses the With sidebar template and the card says which parts it holds: contents, related pages, the contact card. The text of the card is the same for every type, in a panel below the cards.

The choices are stored in the theme settings under `single_layouts.<type>`, so a type with no choice yet has the defaults. A site that saved the older Hero Layouts, Transparent Header and Sidebar settings starts from them. A single entry can still choose its own title area and header in its editor (`hero_layout`, `header_transparent`), and its own page layout (`template:`; `standard` asks for the plain one when its type has another).

A theme declares what a card offers in its manifest:

```yaml
single_layouts:
  fields:
    title: {type: select, label: Title area, default: default, options: {default: Default, split: Split}}
    header: {type: select, label: Header, default: site, options: {site: Site default, 'on': Over the title, 'off': Solid}}
    # sidebar (none, right, left), image, excerpt, byline, toc, related, card
```

Templates read the choices of a type with `single_layout(type)` (a map of `template`, `title`, `header`, `sidebar`, `image`, `excerpt`, `byline`, `toc`, `related`, `card`). The page layout and the title area follow from the template files: `usesTitleArea` is true for a template that includes `components/page-header.twig`, and the line above the title is offered for one that passes `hero_meta`.

**Admin > Theme > Archive Layouts** has one card for each list of entries: every content type that has one, then each taxonomy (the pages of a category or tag). A card sets the layout (seven pictures to choose from), columns, order, items per page, the parts shown, the taxonomies offered as filters, the title and the subtitle; a taxonomy also chooses which content types it lists. Only what differs from the theme's defaults is written, to `custom/content-types/<type>.yaml` and `content/taxonomies/<name>.yaml` as before. The Content types and Taxonomies screens link here instead of holding these settings.

### Options of a content type

A content type with a page or a list of its own can declare **options** in its definition (`themes/<theme>/content-types/<type>.yaml`, or `custom/content-types/<type>.yaml` for a type of the site's own, merged one by one with the theme's). They appear on the type's card in Theme > Single Layouts and Archive Layouts, so every special type brings its own choices to the one place where looks are set:

```yaml
single:
  sidebar: true        # its template draws a sidebar when the card asks (None, Right, Left): the card offers the choice
  header: true         # its template opens with a section the header can sit over: the card offers the choice
  options:             # options of its page: key => definition (select, toggle, number, decimal, text, color)
    cover: {type: select, label: Cover, default: left, options: {left: Left, right: Right, top: Above}}
    show_buy: {type: toggle, label: Buy button, default: true}
archive_options:       # options of its list
  cover_shape: {type: select, label: Covers, default: portrait, options: {portrait: Portrait, square: Square}}
```

Values are checked against the declaration: a page's are stored with the theme settings (`single_layouts.<type>.options`) and read in the template with `single_layout(item.type).options`; a list's are stored in the site's file of the type (`archive.options`, only what differs from the theme's) and read as `archive.settings.options`. The default archive template also adds a class for each (`opt-cover-shape-portrait` for a choice, `opt-some-toggle` for a toggle that is on) to the list, for the style sheet. A template that declares `sidebar: true` draws the sidebar itself (as `templates/sidebar.twig` does), and one that declares `header: true` sets `{% set opens_under_header = true %}` so the layout lets the header go transparent over its first section. The Books type (`single-book.twig`) is the example: where the cover goes, what shows (author, summary, details, buy button), a sidebar, a header over the book, and the shape of the covers in the list.

## Page templates

Pages and posts choose a template in Admin > Edit > Publish (stored as `template:`), and a content type can have one as its layout (Theme > Single Layouts). The manifest's `page_templates` lists them; a template appears only if its file exists.

- **Standard** (`default`): the normal hierarchy: title header, text, blocks.
- **Landing** (`landing`): no title header; the page is its blocks. Without an opening hero the title is still the (visually hidden) `<h1>`.
- **With sidebar** (`sidebar`): title header, then the text beside a sticky sidebar with "On this page" contents (from the text's `##` headings, which get ids), the related pages from the main-menu branch the page belongs to, and a card from Theme settings > Single Layouts > Sidebar card (text, button, phone and email). Blocks follow below at full width; a Page content block does not repeat the text.

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

The theme ships these page layouts (all in Greek and English, with placeholder text and no pictures): **Home**, **About**, **Contact**, **Service page**, **Campaign landing page**, and, for the second family of blocks, **Lead generation landing** (Landing template), **Pricing page**, **Work index (portfolio)**, **Case study**, **Careers**, **Blog home (magazine)**, **FAQ and help center**, **Resources and downloads**, **Event or webinar** (Landing template), **Services overview**, **Legal page** (text with a contents list), **Coming soon** (Landing template) and **Link in bio** (Landing template).

Editors can tick blocks and **Save as section**: the blocks are checked and written to `custom/presets/<name>.yaml` in the page's language, appear under Ready-made sections with a delete button, and are included in backups (Site customizations).

## Header and footer

**Admin > Theme > Header** and **Footer** have the real site beside them (it follows what is chosen, before it is saved), the layouts to choose from, and a few small cards. Everything is a solid colour. The frame shows the page at a computer, tablet or phone width, in light or dark; because a header or footer choice changes what the page is made of, the server draws the page with the unsaved settings (`POST /admin/theme?preview=page`; nothing is stored and no form is processed) and the frame shows that, keeping its scroll and following links inside it.

Header:

- **Layout**: *classic* (logo left, menu right), *menu left* (logo and menu together on the left, tools on the right), *split* (menu left, logo in the middle, tools right), *centered* (logo in the middle, menu below), *stacked* (menu in a full-width bar underneath, with a background of its own; the page you are on is highlighted, a palette can set `--bar-highlight`), *minimal* (logo and a menu button on all screens) and *minimal centered* (the button on the left, the logo in the middle).
- **Style**: *shape* (full width bar or floating, detached from the edges with rounded corners), *content width* (contained or edge to edge), *height* (regular, compact, tall), *edge* (a line, nothing, a shadow), *background* of the row with the logo (page, muted, soft tint, dark, palette colour), *menu links* (soft background on hover, underline, plain).
- **Behaviour**: *sticky* (slides in after scrolling, always at the top, **smart** (leaves while the page is read downwards and returns when it is scrolled back up), or scrolls away), *shrinks* once the page is scrolled, and **transparent over an opening hero** (on pages that start with a Hero block, or a Slider block of full width, the header overlays it, with light text over a cover image or dark hero, and turns solid when the page scrolls; a content type or an entry can decide otherwise, see Single layouts).
- **Elements**: *search* (an icon, a box, or none), the *language switcher* and the *dark mode switch* (each can be turned off), and a **button** (text, link, solid or outline) in the header and the phone menu.
- **Top bar**: a message (optionally a link), the phone and email (from the Footer), the social icons, on a muted, dark or palette background; it shows when it has any of them.
- **Phone**: how the menu opens (side drawer from the left or right, full screen, sheet from the top or bottom), and the bottom bar (menu, call, email, the button, or links of your own with icons).

Footer:

- **Layout**: *columns* (brand and summary, the footer menu, contact), *mega* (a column for each top-level link of the footer menu that has links under it; the links with none share the first column), *one row*, *bar* (the copyright on the left, the links on the right, thin lines between them) and *centered*.
- **Background**: dark (the base), the page's, muted, or the palette colour; a colour or an image of your own replaces it. **Brand**: the site name, the logo, or nothing.
- **Content**: the summary, the copyright line (`{year}`, `{site}` and `{copyright}`, the © sign, are filled in), a credits line under it (for example *Designed by [Unicorg](https://…)*; links are written `[text](address)`, and the same three words work), email, phone, address and opening hours, a **call to action band** above the footer (heading, text, button), the **links at the bottom** (a menu given the place *Footer bottom links*), the social icons, a language switcher and a back to top link.

A site-specific block goes in `custom/blocks/<type>/` with the same files.

## Branding

**Admin > Theme > Branding** gathers everything that makes up the look of the site, for a designer: the theme's `appearance`, `brand` and `design` sections in one tab (a theme that declares `design` gets this tab; otherwise each section is its own tab). Cards on the left, the real site on the right: the frame shows the home page with the unsaved choices as they are made, at computer, tablet or phone width and in light or dark (the browser asks `POST /admin/theme?preview=branding`, which answers with the CSS and attributes and stores nothing; the same frame serves the Header and Footer tabs).

- **Identity** (`brand`): the logo, a **logo for dark backgrounds** (shown in dark mode, in a dark or palette footer, in a dark or palette header row, and over a dark opening hero; without it the logo is used everywhere), the site name beside the logo, heights of the logo (computer, phone, footer) and the size of the name, the **favicon**, the **app icon** (`apple-touch-icon`), the **browser colour** (`theme-color`) and the default share image.
- **Colour**: the palette and the mode (`appearance`), and **your own colours** for light and for dark: accent, background, surface, text, muted text and border, and the near-black of dark panels and the footer. The hover and soft shades of the accent, the second shade of the surface and the border, and a readable text colour on the accent (white or the theme's near-black) are worked out from the ones given.
- **Typography**: the font pairing (`appearance`), a family for headings and one for text (thirteen system stacks, so nothing is downloaded, or **your own WOFF2 file** from `custom/assets/fonts/`), text size, line height, a **heading scale** (a ratio from 1.125 to 1.618, gentler on a phone), heading weight, letter spacing, line height and capitals.
- **Layout and spacing**: content width, reading width, side margin, space between sections, **header height** (compact and tall follow it), and a spacing scale.
- **Shape and depth**: corners (`appearance`), a **radius in pixels**, button corners, shadows, decoration.
- **Buttons**: height, side padding, text size, weight, capitals.

Every `design` field is optional: empty, or "Theme", leaves the theme's own value, so a site that sets nothing looks as it did. `Branding::css()` (`src/Branding.php`) turns the choices into one rule on `:root[data-theme][data-mode]` (which beats the palette, font, shape and mode rules) plus a few element rules; the layout prints it in `<style id="faros-branding">` after the theme's style sheet and before `custom.css`, with `{{ branding_css()|raw }}` and `{{ branding_head() }}` (the icons, the browser colour and a hint to fetch a font file). It is made only of numbers, hex colours and words from fixed lists, so nothing a person types can reach the style sheet as code. The tokens it sets: `--accent-l*`, `--accent-d*`, `--accent-contrast-l/d`, `--n-*`, `--d-*`, `--ink*`, `--font-heading`, `--font-body`, `--type-scale`, `--step-1` to `--step-5`, `--heading-weight`, `--heading-tracking`, `--container`, `--container-narrow`, `--gutter`, `--section-scale`, `--space-scale`, `--header-min`, `--radius-*`, `--radius-button`, `--shadow-*`, `--btn-height`, `--btn-pad-x`, `--btn-size`, `--btn-weight`, `--btn-case`, `--btn-tracking`, `--logo-height`, `--logo-height-mobile`, `--footer-logo-height`, `--brand-name-size`; a theme's own CSS should read them with a fallback (`var(--btn-height, 2.875rem)`).

## Design tokens

`site.css` defines semantic tokens (`--color-bg`, `--color-surface`, `--color-text`, `--color-muted`, `--color-border`, `--accent`, `--accent-soft`, `--accent-contrast`, spacing `--space-*`, type scale `--step-*`, radii `--radius-*`). Theme settings switch them through attributes on `<html>`:

- `data-glow`: `solid` removes the decorative glows (Branding > Shape and depth > Decoration);
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

The layout outputs the title (`Page | Site`, or the SEO title), meta description, canonical URL, `hreflang` alternates, Open Graph (`og:type`, `og:site_name`, `og:locale` and alternates, image), and Twitter card tags. The share image falls back from the SEO image to the main image, the first block image, and finally Theme > Branding > Identity.

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
- Forms: a field can take a half, a third or two thirds of the row (`field.width`: `form-w-half`, `form-w-third`, `form-w-two-thirds` on a screen from 40rem), and a form can hold a heading (`.form-heading`) and a text (`.form-text`) between its fields; `components/form.twig` draws them. Labels for every control, `aria-describedby` for help and errors, `aria-invalid`, announced status messages, and `autocomplete` hints.
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

The screen is one language at a time (each language shows how far it is translated; the default language is the source). The strings are grouped by the word before the first dot of their key (`form.error.required` is in *Form*; a key with no dot is in *General*), each with its source text beside the box to type in. A search finds a key or a text, the filters show the strings that are missing, still say what the source language says, or are customized, and a string you add (*Add a string of your own*) is marked as yours and can be deleted. Name keys with an area first (`shop.buy_now`) and the screen keeps them together.

## Backups

Full backups include `custom/`. The restore screen offers it as **Site customizations**.

## Icons

Icons are SVG files in `themes/<theme>/icons/` (and `custom/icons/`, which wins). A field of `type: icon` in `block.yaml` or in the `settings` of `theme.yaml` offers exactly that set, and the admin shows it as a popup of small pictures (`public/assets/js/admin-icons.js`). Use `{{ icon('name') }}` in templates; a name that does not exist prints nothing.
