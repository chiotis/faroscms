# Importing content

"Import" covers four different jobs. Decide which one before touching anything.

| The source | The way |
|---|---|
| A **WordPress** site that is online | `php scripts/import-wordpress.php https://old-site.example` from a clone of the repository (the script is not in the release package); reads the public REST API, no password. It prints a plan first, `--apply` writes. Pages, posts, categories, tags, media, links rewritten, and `redirects.txt` for the old addresses. Read `docs/wordpress-import.md` of the repository before running it. Needs PHP and network access; run it on a copy and look at the result. |
| **Text and pictures you were given** (Word, Google Docs, a spreadsheet, PDFs, a list of URLs, copy pasted in chat) | Turn each item into an entry, then write files (`new_entry.py`) or make a CSV for the admin (`entries_to_csv.py`). This is the common case; the steps are below. |
| **Another website** that is not WordPress | Read its pages, keep the text structure (headings, lists, links, images), drop navigation, footers, cookie banners, scripts and styles, then proceed as above. Respect copyright: the person must own or be licensed to use the material. |
| A **CSV from FarosCMS itself** (Admin > Content > type > Export) | Edit and import it back (`importing` below). Columns: `content_type, language, slug, title, status, visible, date, author, tags, categories, translation_id, main_image, excerpt, body, updated_at` plus `meta.*` for the rest of the front matter (`meta.blocks` is a JSON list). |

## The steps for given material

1. **Inventory.** List the items: what each is (page, post, project…), its language(s), title, its old address if any, its pictures, its place in the menu. Decide the content type for each (`pages` for standing pages, `posts` for dated articles, another type only if the site has it: `python3 scripts/site_info.py`).
2. **Plan the addresses.** Slugs come from titles (`slugify`), Latin lowercase. Keep the old path if the site is moving and the path is clean; otherwise map old → new and prepare redirect lines (`old-path new-path`). Pages and posts share one address space; check for duplicates.
3. **Back up.** Ask the person to make *Admin > Backups > Create full backup*, or copy `content/`, `public/uploads/` and `storage/db/app.sqlite`. A bulk import is the one moment a backup is non-negotiable.
4. **Pictures first.** `python3 scripts/add_media.py photo.jpg https://old/logo.png --alt "…"` (a description of what each shows; the file name is only a fallback). It prints `/uploads/media/<id>.<ext>`; use that everywhere, never the old site's address. The same file twice is stored once.
5. **Write the entries.**
   - With files: one `new_entry.py` per entry per language (`--translation-of <slug>` for the second language, `--meta imported_from=<old url>` so a rerun can find them, `--status draft` while reviewing).
   - Without files: put all entries in one YAML list and run `entries_to_csv.py entries.yaml --type pages -o pages.csv`; the person opens *Admin > Content > Pages > Import*, uploads it, reads the **preview** (create / update / skip / errors per row) and presses **Apply**. One CSV is one content type; forms cannot be imported.
6. **Convert the text to Markdown** the way an editor would: headings from `##`, lists as lists, links as links (internal ones as `/path`), pictures as `![alt](/uploads/…)` or in an `image` block field, tables as Markdown tables. No inline styles, no `<font>`, no empty paragraphs, no layout tables, no duplicate title as a heading. Keep the author's words; fix only obvious typos if asked.
7. **Add blocks only where they help**: an opening `hero` from the page's lead, a closing `cta`, FAQ from a question list, `team`/`pricing`/`timeline` where the source is clearly that. A long article is just a body.
8. **Validate.** `python3 scripts/validate_content.py` (all) or the files you wrote. Fix every ERROR; read every WARN (missing pictures, unknown terms, invalid values).
9. **Link it up.** Add the pages to `content/menus/*.yaml` or Admin > Menus. Put the redirects in (*Admin > Redirects > Import*, 500 lines per paste). File posts under categories/tags that exist (`content/taxonomies`), or add the terms first.
10. **Look.** Open a sample of the pages in each language (and the list pages). The content index and the sitemap follow by themselves. Report: how many entries were created/updated/skipped, what you could not carry over (embedded frames, forms, scripts, comments), what needs a person (mail settings, real photos, missing translations), and the addresses to review.

## Rerunning

An import should be safe to repeat. Files are matched by slug + language (and by `translation_id` in the admin importer); `new_entry.py` refuses to overwrite without `--force`; the CSV importer updates and says `skip` for unchanged rows. Keep the old-address → slug map in a file so a second pass reuses the same slugs.

## What the CSV import does and does not do

- Matches existing entries by `translation_id` + language, else by slug + language, and updates them; keeps front matter keys you did not give a column for.
- Makes the slug from `slug`, else from the title **letter by letter** (not the nicer ELOT rules the admin editor uses), so always give `slug` (the script does).
- Backs up the files it touches and puts them back if a write fails.
- Does not check blocks. A wrong block value is silently replaced by its default later, so validate before you hand the CSV over.
- A body cell keeps its line breaks; a quoted cell may contain commas and newlines.
