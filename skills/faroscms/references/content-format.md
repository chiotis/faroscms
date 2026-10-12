# Content format

Everything a visitor reads is a file. The facts below were checked against FarosCMS 0.1.87 (read `VERSION` in the installation; the format is deliberately stable, but check `CHANGELOG.md` if the version is much newer).

## Where things live

```text
content/
  pages/        about.md  about.en.md  index.md …      (the "pages" type: always on)
  posts/        news-item.md  news-item.en.md …
  projects/     …                                      (and books/, points/, routes/, businesses/, or a type the site made)
  forms/        contact.md  contact.en.md              (a form is a content file too)
  menus/        main.yaml  footer.yaml  legal.yaml     (navigation)
  taxonomies/   categories.yaml  tags.yaml  <own>.yaml (the terms content is filed under)
  media/        <id>.yaml                              (a note about each uploaded file: alt text, tags)
public/uploads/media/<id>.<ext>                        (the uploaded files themselves)
custom/                                                (this site's own CSS, JS, templates, blocks, presets, translations: updates never touch it)
storage/db/app.sqlite                                  (settings, users, history, logs: NOT files, see settings.md)
```

Never edit `src/`, `admin/`, `themes/`, `vendor/` or `public/assets/`: an update replaces them. Site-specific changes go in `custom/` (see `custom/README.md` in the installation).

## One entry = one file

`content/<type>/<slug>.md` for the site's **default language**, `content/<type>/<slug>.<lang>.md` for every other language (`about.en.md`). The language code is **exactly two lowercase letters**: the CMS reads `about.eng.md` as a default-language page called `about.eng`. A page that has no file for a language is a 404 under that language's address (checked: `/en/<slug>` of a default-language-only page answers 404), so a bilingual site needs both files for every page.

The file is YAML front matter between `---` lines, then the Markdown body:

```markdown
---
title: Σχετικά
status: published
visible: true
translation_id: 21a4b5c6d7e8f901
excerpt: "One or two sentences, used on cards and in search results."
seo:
  title: "Σχετικά | Site"
  description: "A sentence of about 150 characters."
main_image: /uploads/media/0db003e8ca184774.png
blocks:
  - type: hero
    heading: Hello
---
## A heading

Markdown text.
```

| Key | Meaning |
|---|---|
| `title` | Required in practice. The page's `<h1>` (a Hero block as the first block takes that role instead). |
| `status` | `published` or `draft`. Anything else is not shown. |
| `visible` | `true` or `false` (the editor labels it "Visible in lists and menus", but **`false` makes the page a 404 for visitors**, exactly like a draft; use it only to take a page offline). Write `true`/`false`, never yes/no: the CMS reads `no` as text, and `visible: no` leaves the page visible. |
| `date` | `YYYY-MM-DD`. Posts and projects are listed newest first by it (else by file time). A Unix timestamp is also understood. |
| `author`, `excerpt` | Optional text. Posts show the author; cards show the excerpt. |
| `translation_id` | 16 hex characters. **The files of one page in different languages share the same value**: that is what links them (language switcher, `hreflang`). Make one with `os.urandom(8).hex()` or let `new_entry.py` do it. |
| `seo` | `title`, `description`, `canonical`, `og_title`, `og_description`, `og_image`, `noindex`. Titles ≈ 60 characters, descriptions ≈ 155. |
| `main_image` | The entry's picture (cards, share image, title area): `/uploads/media/<id>.<ext>` or a full URL. |
| `template` | `landing` (no title header: the page is its blocks; start with a Hero), `sidebar` (text beside contents/related/contact card). Omit for the standard layout. A site can add more in `custom/page-templates.yaml`. |
| `hero_layout`, `header_transparent`, `hero_parallax` | How the entry opens (`default`, `centered`, `split`, `cover`, `minimal`; header `on`/`off`; parallax `on`/`off`). Omit to follow Theme > Single Layouts. |
| `blocks` | The list of blocks (see `blocks.md`). |
| `categories`, `tags`, `<taxonomy>` | Lists of **term ids** from `content/taxonomies/<name>.yaml`. Not for pages. |
| `custom_fields` | Values of the fields the content type declares (a project's `client`, a book's `author`…). See "Content types". |

Unknown keys are kept and ignored, so they never break anything, but they do nothing either.

### The body

Markdown (CommonMark with tables, strikethrough and autolinks). Write headings from `##`: the title is already the `<h1>`. Extras: `[text](https://…){target=_blank}` opens a new tab; a line `{align=center}` before a paragraph or heading aligns it. Links to pages of the site are relative paths: `/about`, `/en/about`, `/projects/alpha`. A picture is `![description](/uploads/media/<id>.<ext>)`. A form is placed with `[form slug="contact"]` on its own line. Raw HTML in the body is allowed in files but is stripped when someone without the "raw HTML" permission saves the entry in the admin, so prefer Markdown and blocks.

If the page has blocks, the body appears where a `content` block puts it, or right after an opening Hero, or first. A page built only from blocks needs no body.

## Addresses

| Entry | Public address |
|---|---|
| a page in the default language | `/<slug>` (the home page, `index.md` by default, is `/`) |
| a page in another language | `/<lang>/<slug>`; home is `/<lang>` |
| a post | `/<slug>` (pages and posts **share** one namespace: no two may use the same slug) |
| any other type | `/<type>/<slug>` (projects: `/projects/alpha`) |
| a list of a type | `/<type>` (`/projects`, `/en/projects`) |
| a category / tag / own taxonomy term | `/category/<slug>`, `/tag/<slug>`, `/<taxonomy>/<slug>` |

The slug is the file name without `.md` / `.<lang>.md`: lowercase Latin letters, digits, `-` and `_`. The admin makes it from the title (Greek is transliterated by the ELOT 743 rules: `Υπηρεσίες` → `ypiresies`, `Μπάμπης` → `bampis`); `scripts/faros_lib.slugify()` does exactly the same, so use it (or `new_entry.py`) instead of inventing slugs. Reserved at the root: `admin assets uploads custom pages search tag tags category categories sitemap robots favicon api storage themes vendor`, any content type name and any language code. Changing a slug later breaks old links unless a redirect is added (Admin > Redirects, or paste lines `old-path new-path` into Redirects > Import).

## Languages

`languages.default` and `languages.available` are settings (Admin > Settings > General; stored in the database, see `settings.md`). Fresh installs default to `el` with `el` and `en` available. Each language has its own menu labels, theme texts (Admin > Translations) and addresses. When you add a language file, the language must be in `available` or the page is not linked from anywhere.

## Content types

A type is a folder in `content/`. `pages` and `forms` are always there; `posts`, `projects` and the others come from the theme's catalogue (Admin > Content types switches them on) or are made by the site (Admin > Content types > New). A definition (`themes/default/content-types/<type>.yaml`, extended by `custom/content-types/<type>.yaml`) declares the type's `fields` and how its list looks. Values of declared fields go in the entry's front matter under `custom_fields`:

```yaml
custom_fields:
  client: Alpha Corp
  year: 2025
  sector: office        # a select: one of the declared option values
```

Read the definition before writing: `themes/default/content-types/projects.yaml` (or the custom one). A value that is not valid for its field is dropped when read. The ready-made types `points`, `routes` and `businesses` keep a `location: "35.2012, 26.2744"` field and are described in `docs/places-and-routes.md` of the installation.

## Taxonomies

`content/taxonomies/<name>.yaml`:

```yaml
title: Categories
terms:
  - id: news            # what entries refer to; never changes
    slug: news          # the address part (/category/news); may change
    labels: { el: Νέα, en: News }
    # descriptions: { el: …, en: … }   optional
archive: { layout: cards }              # optional, only what differs from the defaults
```

An entry is filed with `categories: [news]` (term **ids**). A term used in an entry but missing here appears in no list. Categories and tags exist by default; a site may add its own taxonomy in Admin > Taxonomies > New taxonomy.

## Menus

`content/menus/<name>.yaml`. The header shows `main`, the footer `footer` (Settings: `menu_locations`; a third place, *Footer bottom links*, shows `legal`). Items nest up to three levels:

```yaml
title: Main Menu
items:
  - labels: { el: Αρχική, en: Home }
    url: ""                       # "" is the home page
  - labels: { el: Υπηρεσίες, en: Services }
    url: services                 # relative to the language, like block links
    children:
      - labels: { el: Στρατηγική, en: Strategy }
        url: strategy
  - labels: { el: Επικοινωνία, en: Contact }
    url: contact
    class: nav-cta                # optional: styles it as a button
    # target: _blank   hidden: true
```

`url` is a page address without the language (`about`, `projects`, `category/news`), a path starting with `/`, or a full URL. Give every language its label. A site that was set up in the admin may also have `label_key` entries (translated by Admin > Translations): leave those as they are.

## Forms

`content/forms/<slug>.md` (+ `.en.md`). Front matter only: `submit_label`, `success_message`, `store_submissions`, `notifications: {enabled, to, subject, reply_to_field, auto_reply…}`, `antispam: {honeypot, rate_limit_seconds}` and `fields:`, each `{type, name, label, required, placeholder, options, rows, width}`. Types: `text email textarea number tel url date time datetime-local select radio checkbox checkboxes hidden color range heading paragraph`. `options` are lines `value|Label`. Field `name`s must be unique. Put a form on a page with the `form` block (`form: <slug>`) or `[form slug="<slug>"]`. Mail needs SMTP or SES set in Admin > Settings > Email (a person does this: it holds passwords); without it submissions are still stored (Admin > Forms).

## Media

An uploaded file is `public/uploads/media/<id>.<ext>` plus `content/media/<id>.yaml` (`alt`, `tags`, size, original name). Content refers to it as `/uploads/media/<id>.<ext>`. Alt text of the note is used wherever the picture is placed without its own description. The theme makes responsive WebP copies on demand (`/uploads/_v/…`), so upload one good original (about 2400 px wide at most; the upload limit is 20 MB unless the site changed it). `scripts/add_media.py` does the whole upload for you.

## What the CMS does with a mistake

It never refuses a hand-written file and never shows an error on the site: a value that is not valid for its field is replaced by the default, an unknown block or field is skipped, a block with nothing to show draws nothing. YAML it cannot parse makes the whole file an unpublished draft (the admin flags it). **So a page that "looks wrong" usually has a value that was silently changed: run `validate_content.py`.**
