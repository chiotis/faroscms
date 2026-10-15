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

**A picture beside the text.** Click a picture: the bar has *In the text* (the usual), *Left* and *Right*. A picture on the left or right floats to that side and the text goes around it; on a phone it takes the width of the page. In the Markdown it is an attribute after the picture, `![A cat](/uploads/media/cat.jpg){align=left}` (or `right`), and the site draws it with the `align` attribute of the image (the theme styles it). A picture pasted from WordPress or Word with a float or an `alignleft` class is written the same way. A picture that stands alone in a paragraph has its text in the paragraphs that follow, so for text to go around it put the picture at the start of the paragraph that should wrap.

**Text alignment.** The four alignment buttons (left, center, right, justify) align the paragraphs and headings the selection touches; left is the usual and takes the choice away. In the Markdown it is a line before the block, `{align=center}` then the paragraph or the `## heading`, and the site draws it with the `align` attribute (the theme styles it). It is for paragraphs and headings, not for list items, table cells or code. In Markdown mode you can type the line yourself; `align` is the only attribute of a block that is accepted besides `target` on a link.

**Pictures.** The Image button asks for an address and a description, or *Library…* to choose from the media library; the choice fills the address (and the description, when the picture has one) and *Insert* puts it in the text. **Removing or changing a picture:** click it. It is outlined and a small bar appears with *Edit* (the address and the description, with *Library…* and *Remove picture* in the same form) and *Remove picture*. Delete or Backspace removes a picked picture too, Escape or a click elsewhere lets it go. A paragraph that held only the picture goes with it, and so does a link that held only the picture.

~~Strikethrough~~ is part of the site's Markdown (the Strike button writes `~~text~~`).

## The live preview

The **Preview** button in the header of the editor shows the page beside the form, drawn by the theme from what is in the form now: the blocks, the front matter, the title, the template and the Markdown text. Nothing is saved and no version is written. The panel updates about 600 ms after the last change (typing, and every change of the Blocks tab: add, remove, move, a field, a design setting) and keeps its scroll position.

- **Show and hide**: the button (it says `aria-expanded`) shows and hides the panel; the choice is remembered in the browser. Hidden, the form has the whole width.
- **Computer, Tablet and Phone** widths and **Auto, Light and Dark** are the ones of the Theme preview. The panel is resized by dragging the handle on its left edge, or with the left and right arrow keys while the handle has focus (Shift for bigger steps); the width is remembered. Below 1200 px the panel is a drawer over the form (Close or Escape hides it) and never opens by itself.
- **Block to block**: a click on a block in the picture opens that block in the Blocks tab; a click on a block's header in the builder scrolls the picture to it and outlines it for a moment. Links and buttons in the picture are not followed. Blocks are told apart by their place in the editor's list (`data-bk-block` in the picture, the order of the cards in the builder), which is only added in the preview; the public page has no such marks.
- **Who and how**: `POST /admin/edit?preview=entry` with the whole form (the CSRF token included), for anyone who may edit that type (forms are not previewed). The server reads the form exactly as Save does (`ContentEditor::save(…, $dryRun = true)`), so an editor without the raw HTML capability sees raw HTML as plain text, as Save would keep it; it then draws the page through the public route (`ContentRepository::showInstead()` puts the unsaved entry in place of the stored one for that request). It answers `{html, path}`, or `{error}` when the front matter cannot be read; the panel then keeps the last picture. Files chosen in the form (a main image) are not in the preview until they are saved.
- The builder tells the page about every change with a `bk:change` event on `document`; the preview needs nothing else from how the builder draws blocks.

## Files

`admin/templates/edit.twig` (the screen), `public/assets/js/admin-editor.js` (the editor), `public/assets/js/admin-entry.js` (tabs, pills, custom fields, main image, the search preview), `public/assets/js/admin-preview.js` (mode `entry`) and `public/assets/js/admin-entry-preview.js` (the live preview), `src/VisualMarkdown.php`, and the `.en-*` and `.ed-*` styles in `public/assets/css/app.css`. The tests are `tests/unit/visual-markdown.php`, `tests/http/editor_visual_test.py`, and for the preview `tests/http/entry_preview_test.py` and `tests/js/entry-preview.test.js`; the part of the editor that turns the page back into Markdown runs in the browser and has no automatic test yet.
