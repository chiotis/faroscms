# PicolinoCMS

PicolinoCMS is a Pico-like flat‑file CMS with Twig templates, Markdown content, translations, and a lightweight admin panel.

## Getting Started

1. Upload the entire project folder to your server.
1. Point your web server’s document root to the `public/` directory.
1. Ensure your server runs PHP 8.1+.

### Apache (recommended)
Make sure `mod_rewrite` is enabled so `public/.htaccess` can route requests to `index.php`.

### Nginx (example)

```nginx
server {
  listen 80;
  server_name example.com;

  root /path/to/project/public;
  index index.php;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
  }
}
```

## Admin Panel

- URL: `/admin`
- Default login:
  - Username: `admin`
  - Password: `password`

Update users in `content/users/users.yaml`. You can store hashed passwords or use `plain:` for local development. (User management is file‑based; there is no admin UI for users.)

## Updates

For production-safe update steps, see `update.md`.

## Content Structure

All content lives in Markdown files with YAML front matter:

```
content/
  pages/
  posts/
  projects/
  settings/
  users/
```

Example file:

```yaml
---
title: Example Page
status: published
visible: true
excerpt: "Short summary for hero sections"
seo:
  title: "Custom SEO Title"
  description: "Custom meta description"
custom_fields:
  hero_cta: "Get started"
---
# Heading

Your Markdown content here.
```

### Custom Content Types

Create any folder under `content/` (e.g. `content/projects/`) and it becomes a new content type automatically.

- Archive URL: `/projects`
- Single URL: `/projects/your-slug`

## Template Hierarchy

### Single templates
1. `single-{type}.twig`
2. `single.twig`

### Archive templates
1. `archive-{type}.twig`
2. `archive-{singular}.twig`
3. `{type}_archive.twig` (legacy)
4. `archive.twig`

## Themes

Themes live in `themes/<theme-name>/`.

```
themes/default/
  parts/
  lang/
  archive.twig
  archive-project.twig
  single.twig
  single-page.twig
  single-post.twig
  single-project.twig
  home.twig
```

### Theme Translations

Translations live in `themes/default/lang/` and are PHP arrays. Use the `t('key')` function in Twig:

```twig
{{ t('nav.home') }}
```

Language files:
- `themes/default/lang/en.php`
- `themes/default/lang/el.php`

## Settings

Global settings are stored in `content/settings/site.yaml`.

### Date Format

Set the date format used on the front end using PHP date format strings:

```yaml
date_format: "d/m/Y"
```

### Navigation (Menus)

Menus are stored in `content/menus/*.yaml` (not inside settings).

Example:

```yaml
title: Main Menu
translation_id: "abc123..."
items:
  - label_key: nav.home
    url: ""
  - label_key: nav.services
    url: ""
    children:
      - label_key: nav.services.strategy
        url: "posts"
      - label_key: nav.services.execution
        url: ""
        children:
          - label_key: nav.services.execution.offices
            url: "projects"
```

Menu location mapping lives in `content/settings/site.yaml`:

```yaml
menu_locations:
  header: main
  footer: footer
  sidebar: sidebar-menu
```

Active highlighting and active trail are automatic for all mapped locations (`header`, `footer`, and any extra location like `sidebar`).

To render an extra location in Twig:

```twig
{% import 'parts/menu-macros.twig' as menu_macros %}
{% set sidebar_menu = theme_menus.sidebar ?? [] %}
{% if sidebar_menu is not empty %}
  {{ menu_macros.location_menu(sidebar_menu, prefix, 1, 'footer-menu-level', 'footer-menu-item', 'footer-link', 3) }}
{% endif %}
```

## Notes

- Admin templates are core, not part of the theme.
- Admin assets live in `public/admin-assets/`.

## SEO Helpers

This project includes dynamic SEO endpoints and canonicals:

- `/sitemap.xml` (auto-generated from content)
- `/robots.txt` (includes Sitemap URL)
- Canonical URLs are injected into the `<head>` tag

Make sure `base_url` is set in `content/settings/site.yaml` so canonical links and sitemaps use the correct domain.

## Media Uploads

Use the admin media manager at `/admin/media` to upload images. Files are stored in `public/uploads/` and can be referenced in front matter:

```yaml
main_image: "/uploads/your-image.jpg"
```
