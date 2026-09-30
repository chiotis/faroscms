# Accessibility of the admin

The public theme is checked against WCAG 2.2 AA (see [theming.md](theming.md)). The admin follows the same target. This
page says what was checked, the rules the templates keep, and how to check again.

## What was checked

Every admin screen (dashboard, content lists and editors with each tab, media, theme with each tab, settings with each
tab, menus, forms, taxonomies, users, roles, redirects, translations, logs, backups, updates, system, content types,
import, history) was scanned with axe-core (WCAG 2.0, 2.1 and 2.2 level A and AA, plus its best-practice rules) in light
mode, in dark mode, and at phone width (375 px). The states that only exist after a click were scanned too: the icon
picker, the image picker, the confirmation dialog, the message in the bottom bar, the phone sidebar, and the block
editor with every block open. The result is zero violations.

Not covered: screen readers and real assistive technology (run through it by hand before a release), and the text over
photos in the media grid, which a tool cannot judge.

## Rules the templates keep

- **One `h1` per screen** (the screen's name in the top bar), **cards are `h2`**, and nothing skips a level.
- **Every control has a name**: a visible `<label>`, or `aria-label` where the design has no room for a label (search
  boxes, per-row fields, the checkbox in a table header). A placeholder is not a name.
- **Every button, link, and table header has text** (`sr-only` text for icon-only ones).
- **Muted text reaches 4.5:1.** Tailwind's stock `slate-400`, `slate-500`, `emerald-600`, and `orange-600` are too pale
  for text, so `tailwind.config.js` nudges those four (the class names, and so the markup, stay the same) and
  `public/assets/css/app.css` has the dark-mode counterparts (`.dark .text-slate-400`, and so on). Do not use
  `text-slate-300` for anything that carries meaning.
- **Tab bars** are ARIA tabs: `admin.js` (`enhanceTabs`) gives each `[data-tab]` button in a `role="tablist"` the roles,
  `aria-controls`, `aria-selected`, a roving tab stop, and the arrow, Home, and End keys. A screen only has to mark
  the panels with `data-panel` (or `data-tab-panel`) and toggle the `active` class.
- **Wide tables** that scroll sideways become keyboard-focusable when nothing inside them is (`makeScrollersFocusable`).
- **Messages in the bottom bar** are announced by a live region; the visible layer is shown to assistive technology
  only while it is up, because its Dismiss button takes focus for an error.
- **New classes in JavaScript files** are only in the compiled stylesheet if the file is listed in `content` in
  `tailwind.config.js`. Run `npm run build:css` after adding one.

## Checking again

`tests/http/admin_a11y_test.py` checks what does not need a browser: headings, names, tab panels, and the colour tokens.
For the rest, the method that was used:

1. Start a copy of the site on a local port with test content (the test fixtures work).
2. Sign in with the test client (`tests/http/client.py`) and save each screen's HTML into `public/_audit/` of that copy.
3. Open `/_audit/<screen>.html` in a browser, load axe-core from a CDN in the page (or in an iframe of it), and run it
   with the tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`, `best-practice`. Add `class="dark"` to `<html>`
   for dark mode and use a 375 px wide frame for the phone. For a state that needs a click, click first.
4. Fix what it reports in the template, not in the report.

Snapshots run the screen's own scripts but carry no session, so what a script fetches (the image picker's list) has to
be stubbed in the page.
