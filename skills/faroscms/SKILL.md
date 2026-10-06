---
name: faroscms
description: How to work on a website that runs FarosCMS, a flat-file PHP CMS with Markdown/YAML content, Twig themes and a block editor. Use this skill whenever the job involves a FarosCMS site (a `content/` folder with pages and posts as Markdown, an admin at /admin, blocks like hero, features, faq, cta, pricing), or whenever someone asks to create or edit pages with blocks, import or migrate content (WordPress, spreadsheets, Word or Google Docs, another website, CSV), change site or theme settings (palette, header, footer, logo, languages, menus, forms, SEO, redirects), translate a page into another language, or build a whole website with this CMS, even if they only say "my site", "the CMS" or "faros". It tells you which access you have (the site's files, a browser in the admin, or nothing but the chat), the exact content format, every block and theme setting, scripts that write and check content the way the CMS reads it, and what must be left to a person.
---

# FarosCMS

FarosCMS keeps a site's pages, posts and projects as **Markdown files with YAML front matter** in `content/`, draws them with a Twig theme made of ~35 ready-made **blocks** (hero, features, text and image, FAQ, pricing, gallery, forms…), and gives editors an admin at `/admin`. It is multilingual (Greek and English by default). **Settings, users, redirects and history live in one SQLite file, not in files.** Nothing needs a build step.

The hard part of working with it is not the syntax. It is that **the CMS never complains**: a wrong block option is silently replaced by the default, an unknown field is ignored, a YAML typo turns the whole page into an unpublished draft. So you must write values the CMS accepts, and then *check* them. The bundled scripts do both.

## 1. Find out what you can reach (do this first)

| You have | You can | Work like this |
|---|---|---|
| **The installation's files** (a shell on the server, a clone, an uploaded zip of the site) and Python | everything except secrets | run the scripts in `scripts/` from the installation folder (or `--root PATH`); write content files; settings through `site_settings.py` |
| **A browser signed in to `/admin`** | everything the person's role allows | read `references/admin-ui.md`; prefer the block editor, page layouts and CSV import over hand-typing |
| **Only the chat** (maybe a code sandbox, no site) | produce files and instructions | write the finished files with `new_entry.py --out DIR` (it uses definitions bundled in this skill), give each file's path, say where it goes (`content/pages/…`); for settings give the exact screen and values |

If you have files, start with `python3 scripts/site_info.py`: it prints the version, languages, home page, content per type and language, menus, forms, taxonomies, the current look, the blocks (including the site's own), and whether the database exists. Read `custom/` and `VERSION`/`CHANGELOG.md` in the installation if something differs from this skill. Ask the person only for what you cannot find: which pages, in which languages, what the content is, whether to publish.

The scripts are in this skill's `scripts/` folder. Run them with the installation as the current folder, for example `cd /var/www/site && python3 /path/to/faroscms-skill/scripts/site_info.py` (`scripts/…` in the commands below means that folder), or pass `--root /var/www/site`. They need Python 3.8+ and PyYAML. Without an installation they fall back to the definitions of one FarosCMS version bundled in `snapshot/` and say so.

## 2. Rules that keep a client's site safe

- **Back up before bulk changes.** Ask for *Admin > Backups > Create full backup*, or copy `content/`, `public/uploads/`, `custom/` and `storage/db/app.sqlite`. One page does not need it; an import or a restructure does.
- **Create pages as `status: draft` unless told to publish.** A signed-in person sees drafts at their normal address; they review, then publish.
- **Never invent the client's facts** (prices, testimonials, numbers, names, addresses, legal text). Use what you were given, or an obvious `TODO:` placeholder in a draft page, and list what is missing.
- **Never overwrite or delete something the person did not name.** `new_entry.py` refuses to replace a file without `--force`; look at the existing file before using it.
- **Secrets and people are theirs:** do not set passwords or API keys (SMTP, SES, Google sign-in, S3 backups, YouTube), create users, change roles or limits, install updates, or restore backups. Tell them the screen.
- **Never edit `src/`, `admin/`, `themes/`, `vendor/`, `public/assets/`.** An update replaces them. Site-specific code goes in `custom/` (CSS, JS, templates, blocks, translations) and is rarely needed: the blocks and Branding settings cover almost everything.
- **Write in the person's language** in your messages (Greek if they write Greek) and in the content language they ask for; keep file names, keys and values (`variant`, `tone`, `url`) in English exactly as the CMS defines them.
- Written files must be UTF-8 with LF line endings, owned by the user the web server runs as, or the admin may not be able to save them later.

## 3. Create or change pages with blocks

Read `references/page-recipes.md` (composition rules, worked examples, what to do when facts are missing) and the entries of the blocks you will use in `references/blocks.md` (or `python3 scripts/block_reference.py hero faq cta`, which reads the installed version).

```bash
# a page from a ready-made layout (python3 scripts/site_info.py --presets lists them), as a draft
python3 scripts/new_entry.py --type pages --title "Υπηρεσίες" --preset page-services-overview --status draft
# a page from blocks you wrote (a YAML list in a file)
python3 scripts/new_entry.py --type pages --title "Υπηρεσίες" --blocks services.yaml --body-file services.md --seo-description "…"
# the same page in English: same translation_id, same address
python3 scripts/new_entry.py --type pages --lang en --title "Services" --slug ypiresies --translation-of ypiresies --blocks services-en.yaml
# a post
python3 scripts/new_entry.py --type posts --title "Νέα του μήνα" --date 2026-10-06 --categories news --body-file post.md
# check anything you wrote or changed
python3 scripts/validate_content.py content/pages/ypiresies.md content/pages/ypiresies.en.md
```

Essentials (details in `references/content-format.md`):
- One file per page per language: `content/pages/about.md` (default language), `about.en.md` (English). Two-letter language codes only. Both files share `translation_id`. A language without a file is a 404 for that page.
- First block `hero` = the page's `<h1>`; body Markdown starts at `##`; the `content` block places the body between blocks; `template: landing` for campaign pages.
- Pictures: put the file in the library first (`python3 scripts/add_media.py photo.jpg --alt "what it shows"`), then use the printed `/uploads/media/<id>.<ext>`; always give `image_alt`.
- Links in blocks and menus: `contact` (same language), `/path`, full URL, `#anchor`, `mailto:`, `tel:`.
- `visible: false` and `status: draft` are both a 404 for visitors. Write `true`/`false`, never yes/no.
- Quote YAML texts that contain `:`, `#`, `?`, quotes or start with `*`; use `|` for several lines.
- Pages are not added to menus automatically: edit `content/menus/main.yaml` (format in `content-format.md`) or Admin > Menus.
- After writing, run the validator, and if the site is reachable open the pages in each language.

In the browser instead: `references/admin-ui.md`, section "Make a page with blocks".

## 4. Import content

Read `references/importing.md`. In short: inventory the source, plan addresses (slugs by `slugify`, redirects `old-path new-path`), back up, add pictures first, write the entries (`new_entry.py` per entry, or `entries_to_csv.py` to make the CSV that *Admin > Content > type > Import* previews and applies), convert text to clean Markdown, validate, link into menus, add redirects, open a sample, and report what was created, skipped and not carried over. A WordPress site that is online has its own importer (`scripts/import-wordpress.php` in the repository, with `docs/wordpress-import.md`).

## 5. Change settings and the look

Read `references/settings.md`. The two things to remember: **settings are in the database, not files**, and **an invalid value is silently replaced by the default**. With files: `python3 scripts/site_settings.py show theme header`, `schema theme header`, `set theme header.layout=split footer.layout=bar` (validated, database copied first, several at once all-or-none). Every theme setting with its choices is in `references/theme-settings.md`. For `languages`, mail, backups, SEO, users, limits, updates, use the admin screens and let the person type secrets.

Menus (`content/menus/*.yaml`), taxonomies (`content/taxonomies/*.yaml`), forms (`content/forms/*.md`), the theme's words (`custom/lang/<lang>.yaml`) and custom CSS (`custom/assets/css/custom.css`) are files: formats in `content-format.md` and `settings.md`.

## 6. Building a whole site

Order that works: (1) `site_info.py`; confirm languages, name, tagline, base address, palette, logo, what pages and sections the business needs; (2) Theme settings (palette, font, header and footer layouts, logo, social); (3) upload pictures and logo; (4) create the pages from ready-made layouts, filling in real content, both languages; (5) menus; (6) the contact form (`content/forms/contact.md`, mail settings by the person); (7) SEO titles and descriptions per page, Admin > SEO for the site-wide choices; (8) validate everything; (9) hand over a list of drafts, TODOs and the screens only the owner can finish (mail, users, backups). The demo site in the repository's `starter/` shows a complete bilingual example; `php scripts/use-starter.php` copies it into an empty site.

## 7. Scripts (run from the installation folder; `--help` on each)

| Script | Does |
|---|---|
| `site_info.py` | what the site has; `--presets`, `--icons`, `--entries <type>` |
| `block_reference.py` | fields of the installed blocks; `--list`, `<type>…` |
| `new_entry.py` | writes a page/post/entry file correctly, validates it, refuses to overwrite |
| `validate_content.py` | what the CMS would silently change or ignore, in any files |
| `add_media.py` | adds pictures/files to the library (files or URLs), prints the address |
| `entries_to_csv.py` | the CSV for Admin > Import, checked |
| `site_settings.py` | show/schema/set theme and a safe subset of site settings |
| `faros_lib.py` | shared code (slugify, YAML, field rules); `refresh_snapshot.py` is for maintainers |

## 8. References (read only what the job needs)

- `references/content-format.md`: files, front matter, addresses, languages, types, taxonomies, menus, forms, media, what the CMS does with mistakes. Read before writing any file.
- `references/blocks.md`: every block, layout and field. Read the entries you use.
- `references/page-recipes.md`: how to compose pages, examples, drafts and invented facts.
- `references/settings.md` and `references/theme-settings.md`: where each setting lives and how to change it.
- `references/importing.md`: playbook for moving content in.
- `references/admin-ui.md`: browser-driven work and the screen map.
- `references/troubleshooting.md`: symptom → cause → fix; the final checklist.

The shipped references describe FarosCMS 0.1.87 (`snapshot/VERSION`). If the installation is newer, the installed files win: `block_reference.py`, `site_settings.py schema theme --markdown` and its `CHANGELOG.md` are always current.

## 9. Finish

Tell the person, briefly and in their language: what you created or changed (file paths or screens, drafts vs published, the address of each page), what you could not do or invented nothing for (the `TODO`s), what only they can do (secrets, publishing, mail, users), and how to undo it (History, the settings database copy, the backup).
