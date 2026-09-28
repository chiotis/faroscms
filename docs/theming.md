# Theming

FarosCMS has one all-purpose theme, `themes/default`. It grows over time: new blocks, header and footer variants, and page templates are added to it, and every site picks what it needs through settings. Sites are not meant to switch themes.

Two rules follow from that:

1. **Updates must never break a site.** The theme is replaced on every update, so anything a site stores or overrides has to keep working with newer theme versions.
2. **Site-specific work lives in `custom/`**, which updates never touch.

## Layers

| Layer | Where | Status |
|-------|-------|--------|
| Theme contract | `themes/default/theme.yaml` | Phase 1 (done) |
| Site-level components: header, footer, page/post/CPT single, archive, search, 404 | `themes/default/layouts`, `templates`, `components` | Structure in place; design system and variants in Phase 2 |
| Content blocks per page or post | `themes/default/blocks/<block>/` + `blocks:` in front matter | Phase 3 (engine), Phase 4 (admin editor) |
| Field definitions per content type | content type config | Phase 5 |

## Folder layout

```text
themes/default/
├── theme.yaml            manifest: label, version, menu locations, settings schema
├── layouts/base.twig     HTML shell; blocks: head, header, content, footer, scripts
├── templates/            one template per page kind (hierarchy below)
├── components/           header, footer, card, form, menu macros
├── blocks/               (Phase 3) one folder per content block
├── assets/css, assets/js served at /_themes/default/<path>
└── lang/<lang>.php       shipped strings

custom/                   site-specific, never touched by updates
├── assets/css/custom.css loaded after the theme CSS when present
├── assets/js/custom.js   loaded after the theme JS when present
├── lang/<lang>.yaml      string overrides (written by Admin > Translations)
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

## Assets

Theme and custom assets live outside `public/` and are served by `ThemeAssets` from `public/index.php` before the application boots. Only static types are served: css, js, map, json, images, and fonts. URLs carry a `?v=` version from the file's mtime and size, and versioned responses are cached for a year.

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
