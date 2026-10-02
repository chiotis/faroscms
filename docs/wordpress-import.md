# Moving a WordPress site over

`scripts/import-wordpress.php` reads a WordPress site through its public REST API (`/wp-json/wp/v2`, no password) and brings its pages, posts, categories, tags and pictures into a FarosCMS site. It says what it would do first and changes nothing until you add `--apply`.

```bash
php scripts/import-wordpress.php https://example.com            # what would happen
php scripts/import-wordpress.php https://example.com --apply    # do it
```

Options: `--lang=en` (language of the content), `--default-lang=en` (the site's default language; when it differs, the content lives under `/en/`), `--home=index` (file name of the home page), `--kinds=pages,posts`, `--only-used-media`, `--overwrite`, `--content=PATH`, `--uploads=PATH`, `--out=PATH`. Run it against a copy first and look through the result in the admin.

## What comes over

| On the old site | Here |
|---|---|
| Pages | Pages. The page WordPress shows as the home page becomes the home page file. |
| Posts | Posts, with date, category and tag terms, the featured picture as the main image, and an excerpt only when the author wrote one (the cut WordPress makes by itself is left out). |
| Categories and tags | Terms. The category tree is flattened: a name used under several parents ("Logicomix" under News and under Reviews) becomes "Logicomix (News)" with the address `news-logicomix`, and a post in a child category is also in the parents, as WordPress shows it on the parent's page. |
| The media library | Files in the library with their alternative text, and every picture or file the text uses. A size WordPress made (`photo-300x200.jpg`) is the picture itself. |
| The text (HTML) | Markdown: paragraphs, headings, lists, quotes, links, pictures, simple tables and code. Styles, Word's classes, float classes and empty paragraphs are dropped; a table used to place a picture beside text is taken apart; video embeds (YouTube, Vimeo, SoundCloud and a few more, over https) stay as a small piece of HTML. |
| Links between pages | Rewritten to the new addresses. |

## Old addresses

The run writes `redirects.txt` (default `storage/import/`): one line per old address that is not the same on the new site (a post at `/slug/` is now at `/posts/slug`, a category at `/category/news/child/` is at `/category/news-child`, a picture at `/wp-content/uploads/...` is in the library). Paste it into **Admin → Redirects → Import** on the site that will serve them. An old address that is a page of the new site is never redirected.

## Running it again

Every id is made from the address it came from, and every file it writes has `imported_from` in its front matter. Running it again updates those files and fetches only the pictures that are not in the library yet. A file of the site that an import did not write (you made it, or edited and removed the line) is left alone unless `--overwrite` is given.

## What it does not do (yet)

- **Custom post types** that the site does not show in its REST API (a theme's "books", "events", "slides" are often hidden from it). Their pages are not read.
- Menus, widgets, forms, comments and theme settings: the API does not give them without a sign-in.
- Page-builder content (Elementor, WPBakery, Divi): shortcodes and builder markup come over as text or are dropped; look at the report.
- Embedded frames from sites other than the few known video and audio hosts are left out and listed.
- Pictures hosted on other sites stay where they are.
- Hierarchical pages (a page under a page): every page is a page of its own at the top.
