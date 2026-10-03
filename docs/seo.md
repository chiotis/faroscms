# SEO

Admin > **SEO** (Manage) holds what is the same for the whole site; each page, post and project keeps its own SEO tab for what is special to it. A page's own choice always wins over the site's.

Everything is stored under `seo` in the site settings, and every value has a default, so a site that never opens the screen behaves as it always did. A tab saves only its own settings. The screen needs the `seo.manage` capability (administrators; a custom role can be given it).

## The tabs

| Tab | What it holds |
|---|---|
| **Overview** | The checks of the site (open to search engines, site address and https, tagline, sitemap, default share image, verification, addresses not found) and of every published entry in every language: missing description, a description that is too short or too long, a title that is too long, a title or description that two entries share, no picture for a shared link, and what is kept out of search. Each finding names the entry and links to its editor. |
| **Search appearance** | The title separator and format (`{title}`, `{site}`, `{tagline}`, `{sep}`), the home page's title and description, the default description, a title format, "No index" and "Sitemap" for each content type, and whether the search results and the category and tag pages are kept out of search. A live preview draws the result. |
| **Social** | The default share image (used by a page that has none, then the one of Theme > Branding), the card on X, the X account (`twitter:site`) and the Facebook app ID, with a preview of a shared link. |
| **Crawling & sitemap** | Ask search engines to stay out of the whole site, allow large previews (`max-image-preview`), the paths of robots.txt, blocking the AI crawlers, and the sitemap: on or off, the main picture of each entry, the pages of the terms, and the addresses to leave out. |
| **Identity & schema** | Whether the site is an organization, a local business (with its address and price range) or a person, its name, other name and description; the type of a post (blog posting, article, news article); breadcrumbs and the site search in the structured data. The logo, phone, email and profiles come from the theme. |
| **Verification** | The ownership codes of Google, Bing, Yandex, Pinterest, Baidu and Facebook. A whole meta tag can be pasted: its code is taken. |

## How a page gets its title, description and robots tag

* **Title**: the "title written for search engines" in the page's SEO tab, as it is; the home page's own title; otherwise the page's title in the format of its content type, or the site's (`{title} | {site}` by default). A word that has no value is dropped with the separator beside it.
* **Description**: the page's own, the home page's (for the home page), the summary (excerpt), what the template gives (a list, the search), the default description, the tagline.
* **Robots**: `noindex, nofollow` on every page of a site that asked to stay out; `noindex, follow` for a page with "No index", a content type that is kept out, the search results or the term pages when chosen; `max-image-preview:large, max-snippet:-1, max-video-preview:-1` for the rest (unless large previews are off).
* **Share image**: the page's own, its main image, the first picture of its blocks, the default of this screen, the one of Theme > Branding.

## The sitemap

`/sitemap.xml` lists the published entries and the term pages that have entries, in every language, each with its last change and, when chosen, its main picture. Left out: drafts and hidden entries, entries with "No index", content types that are kept out or taken out of the sitemap, the addresses listed in "Leave out" (a rule matches the start of an address, with or without the language it starts with, and understands `*` and a closing `$`), and everything on a site that asked to stay out of search. A sitemap that is switched off answers 404 and robots.txt does not name it.

## For theme developers

The default theme's `layouts/base.twig` uses these Twig functions; a theme with its own base layout can use them to follow the screen:

| Function | Gives |
|---|---|
| `seo_title(own, page, is_home, type)` | The title of the page. |
| `seo_description(own, excerpt, document, is_home)` | The description. |
| `seo_robots(page_noindex, kind)` | The content of the robots meta tag, or an empty string. `kind` is `search`, `taxonomy`, or a content type. A template sets `seo_kind` for the kinds that are not an entry. |
| `seo_head()` | The meta tags that are the same on every page (ownership codes, `fb:app_id`, `twitter:site`). Safe HTML. |
| `seo_share_image()` | The default share image of the screen. |
| `seo_twitter_card(has_image)` | The value of `twitter:card`. |
