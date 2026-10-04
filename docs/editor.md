# The content editor

The screen of one entry (`Admin > a content type > an entry`) has a **Content** tab with the title, the web address, the main content and an excerpt, a side card to publish (status, language, template, date, author, visibility) and a card for the title area; and the other tabs: the type's details, Blocks, Media, Taxonomies, Custom Fields, SEO (with a preview of the search result), Translations, History and Advanced (the front matter).

## The main content: visual over Markdown

The text of an entry is **Markdown, always**. The file on disk, the History, the export and the `body` field the form posts are Markdown, whichever way the person writes it.

- **Visual** (the default) is an editor where text looks as it will on the site: a style menu (paragraph, headings 2 to 4, code block), bold, italic, strikethrough, code, links, bulleted and numbered lists, quotes, images (from the library or an address, with a description), tables (with *+ Row*, *+ Column* and their opposites), a divider, undo and redo. Typing `## `, `- `, `1. ` or `> ` at the start of a line makes a heading, a list or a quote; `---` and a fence of three backticks followed by Enter make a divider and a code block. Pasted text is reduced to what Markdown can say (Word and Google Docs formatting becomes bold, italic, headings and lists), and pasted Markdown is drawn as formatted text.
- **Markdown** is the plain text with a few buttons. The choice is remembered in the browser. If the visual editor cannot start, the Markdown is shown and nothing is lost.
- A person with the raw HTML capability also gets *underline* (written as `<u>`) and embeds and buttons (written as HTML). Raw HTML already in the text is shown as a grey **HTML** box that is not run in the admin and is kept character for character; edit it in Markdown mode.

### Why the file does not change under your hands

`VisualMarkdown` (`POST /admin/markdown-visual`, capability `content.manage`) draws the Markdown with the site's own Markdown settings, one top-level block at a time, together with the lines each block came from. `public/assets/js/admin-editor.js` keeps those lines:

- Nothing is written to the textarea until the person types or formats something. Opening an entry and leaving it never changes the file.
- A top-level block that is exactly as it was drawn is written back as the lines it came from. Only the blocks that were edited are written again (with `-` for bullets, `**` for bold, `---` for dividers, and so on), so the History shows the change and not a rewrite of the text.
- The definitions links refer to (`[1]: https://…`) are kept and written at the end. When the lines of the blocks do not account for all of the text, the editor writes everything again instead of trusting them.
- Raw HTML is kept verbatim, so the check that stops someone without the raw HTML capability from adding HTML (`HtmlGuard`) still recognises what was already in the file.
- A new line in a paragraph is a line break, as everywhere on the site.

**Links that open in a new tab.** The link box has *Open in a new tab*. In the Markdown it is written as an attribute after the link, `[text](https://example.org){target=_blank}`, and the site draws `target="_blank"` with `rel="noopener noreferrer"`. It is the only attribute the site accepts that way (no classes, ids or event handlers), and braces that are not an attribute, such as `{name}`, stay as text. A line that is only `{.class}` or `{key=value}` is read as an attribute and not shown.

~~Strikethrough~~ is part of the site's Markdown (the Strike button writes `~~text~~`).

## Files

`admin/templates/edit.twig` (the screen), `public/assets/js/admin-editor.js` (the editor), `public/assets/js/admin-entry.js` (tabs, pills, custom fields, main image, the search preview), `src/VisualMarkdown.php`, and the `.en-*` and `.ed-*` styles in `public/assets/css/app.css`. The tests are `tests/unit/visual-markdown.php` and `tests/http/editor_visual_test.py`; the part of the editor that turns the page back into Markdown runs in the browser and has no automatic test yet.
