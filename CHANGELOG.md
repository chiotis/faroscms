# Changelog

## 0.1.22 — 2026-10-02 — Installing and the admin work on hosts that switch functions off
- **Fixed: Install did nothing on a host that disables `set_time_limit` or `ignore_user_abort`** (the request ended with an empty 500 page and left no trace in the activity log). A function a host disables is not there to call: calling it is a fatal error that `@` does not catch. Every optional function the install uses (`set_time_limit`, `ignore_user_abort`, `disk_free_space`, `opcache_reset`, `curl_exec`) is now asked about first, and the same was done for the storage figures that every admin screen reads (`SiteLimits`), the restore check (`BackupService`) and the cURL calls of mail and Google sign-in. Without cURL the download and the check of the site use PHP's own streams
- **OPcache**: when a host does not allow `opcache_reset`, the install waits for OPcache to look at the files again before it asks the new version whether it starts (otherwise it would be asking the old code), and says so when PHP is set never to look again (then the new version starts when PHP is restarted)
- The test server now runs with those functions disabled, and `update-installer-restricted.php` runs the installer's whole test the same way, so a call that is not guarded fails the suite
- **A site on 0.1.20 or 0.1.21 cannot use the Install button until it has this version**: update it once by hand (unzip the new package over the site folder, see `update.md`), after which the button works

## 0.1.21 — 2026-10-02 — Safer installs, and a fix for PHP 8.5
- **A vendored library call made safer.** `vendor/nette/schema` (used by the Markdown library) called one of its own private methods as `self::isInRange()` from an instance method. PHP has always accepted that, but on PHP 8.5 the call failed intermittently with "Non-static method … cannot be called statically" on some servers and in CI. It is now `$this->isInRange()` (a two-line change in `vendor/nette/schema/src/Schema/Elements/Base.php`; reapply it if the library is ever updated)
- **The copy of the database kept before an install** no longer depends on `VACUUM INTO` (SQLite 3.27 or newer): when that is not possible the file is copied after its log is folded in, and the message says what was missing if neither works. The installer also forgets what the request remembers about files before it starts
- The update tests no longer depend on the release notes the Updates screen shows (they mention the buttons), and the installer test no longer deletes and re-creates a database at the same path inside one process

## 0.1.20 — 2026-10-02 — Install updates from the admin; the repository holds code only
- **Install updates from Admin → Updates.** A release is now a ZIP of the code only (`faroscms-<version>.zip`) with a `release.json` (version, lowest PHP, SHA-256, size), built by `scripts/build-release.php` and attached to each GitHub Release by the workflow. The **Install** button downloads it, checks its size and checksum, unpacks it beside the live code (refusing a package that holds any path an update may not write, such as `content/`, `custom/`, `storage/` or `public/uploads/`, a path that climbs out, or a link), closes the public site with a "back in a moment" page, swaps the code folder by folder (undone in reverse if one fails), asks the new version whether it starts, and puts the old code (and the database, if its structure had changed) back if it does not. It needs a verified backup made today. After an install, **Put version … back** restores the old code. Pages, posts, media, `custom/`, settings and accounts are never touched; tests check them byte for byte through an install, a roll back, an undone update and a refused package. See `update.md` and `docs/update-workflow.md`
- **The repository holds code only.** `content/` and `public/uploads/` are no longer tracked (your files stay where they are); the demo site is in `starter/`, and `php scripts/use-starter.php` copies it into a new site (it never overwrites one). **A git clone made before this version still has the demo tracked: copy out your `content/` and `public/uploads/` before pulling, because git removes the files that are no longer tracked.**
- **First administrator.** A site with no accounts (a new install ships none) asks for its first administrator on the sign-in page, once. The demo's `admin` / `1234` account is gone from `starter/`, so a new site has no default password
- **Maintenance mode**: `storage/maintenance.flag` answers public requests with 503 and `Retry-After` while an update runs; the admin stays open, and a flag older than 15 minutes is ignored so a failed update cannot close a site for ever
- **Settings → Updates** has a **Release manifest URL** (blank uses the latest release of the repository)
- Tests: `release-package.php` (the package holds code only, is reproducible, and every file in it is one an update may write), `update-installer.php` (90 checks), `first-admin.php`, and the browser-level `update_install_test.py`, `setup_test.py` and `fresh_install_test.py` (a site made from the package alone opens every page). The HTTP test server now runs with four workers so an install can ask the site about itself

## 0.1.19 — 2026-10-02 — The Settings screen, the admin extras and the public address routing have their own classes
- Three more groups left `src/App.php` (from about 3,300 to about 3,100 lines; it was 9,800 at the start of the split). Behaviour is unchanged: every check that passed before passes after, and on a copy of the site the settings were saved, a test email and a backup made from the screen, and the public pages (home, languages, lists, entries, categories, the old `/pages/` addresses, search, sitemap, robots, a form) opened
- **`SettingsAdmin`**: saving the settings form (with the storage and upload limit changes noted in the activity log, the theme settings the form carries, and the three buttons: send a test email, test the remote backup, make a backup now), measuring the storage again, and the data of the screen. **`AdminChrome`**: what every admin screen gets besides its own data (notifications, the version and whether an update is available, the default-password warning, the storage level and, when it is almost full, the email to write to the super admin)
- **`FrontRoute`**: which kind of public page an address asks for (sitemap, robots, an old `/pages/` address, a category or tag, the search, the list of a type, one entry, a page) and the language it is in
- Test `settings-and-front.php` (46 checks)
- `docs/architecture.md` lists the classes and what is left to split

## 0.1.18 — 2026-10-02 — The content screens, menus, logs, updates, term pages, public forms and sitemap have their own classes
- Fifteen more groups of code left `src/App.php` (from about 5,000 to about 3,300 lines; it was 9,800 at the start of the split), each with unit tests for code that had none of its own. Behaviour is unchanged: every check that passed before passes after, including the browser-level ones, and the admin screens and public pages were opened and the forms, menus, logs, updates check and CSV export used on a copy of the site
- **Content screens**: **`ContentAdmin`** (the content list with its search, status and term filters, the bulk actions, and deleting one entry, which for a public one asks where visitors should go instead), **`EntryForm`** (everything the editor screen shows for one entry: fields, blocks, how it opens, translations, address and old addresses, the links that still use an address that just changed, the latest versions, and a form's fields, notifications and submissions), **`RevisionAdmin`** (the History screens and bringing back a version or a deleted entry), **`ContentTransfer`** (the CSV file and the two-step import, with the preview kept in the session for an hour) and `MediaAdmin::uploadMainImage` (the main image uploaded with the form). Test `content-screens.php` (96 checks)
- **Menus, logs, updates, taxonomies**: **`MenuAdmin`** (list, create, copy, edit, delete), **`LogAdmin`** (the activity and email logs with their filters and paging, clearing the email log), **`UpdateAdmin`** (the Updates screen, the check, the backup before updating, the checks to pass before updating) with **`AdminNotices`** (the notices the site raises by itself: a new version, a system check that needs attention), and `TaxonomyEditor` now also builds the Taxonomies screen and saves its form. Test `admin-screens.php` (49 checks)
- **The public side**: **`Sitemap`**, **`TaxonomyPage`** (a category or tag page), **`PublicForms`** (what happens when a form is sent, what a form needs to be shown, the `[form slug="..."]` shortcode) and **`TwigFunctions`** (the template functions that depend on nothing about the request). Test `public-site.php` (50 checks), including a bot that fills the hidden field, the wait between submissions, the ways back a form may use (never another site), and a sitemap with no drafts or hidden entries
- **The admin router** is a table of addresses and handlers (`ADMIN_ROUTES`) instead of forty `if` blocks
- **One latent bug removed on the way**: after a form was sent and no way back was given, the success message was read from a variable that the loop sending the emails had reused, so it could show the last email instead of the message. The forms the theme draws always give a way back, so it was not seen
- `docs/architecture.md` lists the classes and what is left to split

## 0.1.17 — 2026-09-30 — Forms, sign-in, users, roles, content types, archives and backups have their own classes
- Nine more groups of code left `src/App.php` (from about 6,250 to about 5,000 lines; it was 9,800 at the start of the split), each with unit tests for code that had none of its own. Behaviour is unchanged: every check that passed before passes after, including the browser-level ones
- **Forms**: **`FormProcessor`** is what happens to a form someone fills in on the site (the starting values, checking what was sent against each field, the record kept, the notification and automatic-reply emails and who they reply to); **`FormsAdmin`** is the Forms screens (the list with counts, one form's submissions filtered and paged, deleting, the CSV export). Test `forms.php` (67 checks)
- **Sign-in, users, roles**: **`SignIn`** (password sign-in with the block after repeated failures), **`GoogleSignIn`** (its settings, the address, the two calls, and whether a profile may sign in; the calls can be replaced in tests), **`UserAdmin`** (the user form within what the person may change, the password checks) and **`RoleAdmin`** (the permission table, custom roles, the log of permission changes). Test `accounts.php` (58 checks), including a sign-in blocked after too many failures, every password rule, and that nobody changes their own role or status
- **Content types**: **`ContentTypeAdmin`** (making a type, saving a definition with only what differs from the theme, the rows it shows). Test `content-type-admin.php` (39). **Archives**: **`ArchiveBuilder`** (the filters an archive offers, the order, the page). Test `archive-builder.php` (30)
- **Backups**: **`BackupAdmin`** (verify, create, delete, restore with a mandatory safety snapshot and only the areas chosen, the backup before an update, the screens' data). Test `backup-admin.php` (36), which restores real archives: content put back, a page added since is gone, other areas untouched, an archive without checksums needs an acknowledgement, a damaged one restores nothing
- **Settings**: the form the settings screen sends is read in `SiteSettings::formFromPost` (tested: a ticked box is 1, an unticked one 0, a form without the robots box does not clear the rules)
- **One bug fixed on the way**: restoring a backup passed a variable that did not exist (`$theme`) to the archive check. PHP ignored the extra argument but wrote a warning into the error log on every restore
- `docs/architecture.md` lists the classes and what is left to split

## 0.1.16 — 2026-09-30 — Storage limits, system checks, and the dashboard have their own classes
- Three groups left `src/App.php` (now about 6,250 lines). **`SiteLimits`**: what the site uses against the storage limit (measured once, kept for twelve hours, adjusted by each upload and deletion), whether a file still fits, the message when it does not, the size of one upload (the site's limit, never more than the server takes), and the readers of the limits on the settings form. **`SystemStatus`**: the health checks (PHP, database, folders that must be writable, mail, disk space, the content index, scheduled backups), the one-line verdict, the PHP extensions, and the environment table of the System screen. **`DashboardData`**: what the dashboard shows each role (an editor sees only content; people, backups, logs, mail and system health need their own permission). The permission checks stay in `App`: the classes are handed what the person may do. Behaviour is unchanged: every check that passed before passes after
- New unit tests for code that had none of its own: `site-limits.php` (46 checks: measuring, the twelve-hour copy and its adjustments, an expired copy, a clock that went backwards, a damaged copy, every level at its threshold, room for one more byte, upload limits, every form reader) and `system-status.php` (33: every check in each state, the verdict, extensions, the environment, a database that cannot open, and the dashboard for a super admin, an editor, and a mixed set of capabilities)
- `docs/architecture.md` lists the classes and what is left to split

## 0.1.15 — 2026-09-30 — The Media screen has its own class
- What the Media screen does moved out of `src/App.php` (now about 6,600 lines) into **`MediaAdmin`**: which list is asked for and how the person lands back on it after an action, uploading several files at once within the storage limit and the size limit (a refused file is reported and the others are kept), saving tags and text, deleting one file (a file that content uses is only deleted after the screen asked) or many (files in use are kept and counted), adding tags in bulk (also to "all that match" the filters, not just the ticked rows), the list with where each file is used, and the page of pictures the image picker asks for. The upload size and storage checks are handed to it, so it does not know how they are measured. A small file-name cleaner only the old delete form used went with it. Behaviour is unchanged: every check that passed before passes after
- New unit test `media-admin.php` (46 checks) that uploads real files into a temporary library: the list asked for and kept, single and multiple uploads, a refused type, no room, the size limit, tags and text, deleting with and without the confirmation, bulk actions with files in use, the filters, paging, and the picker
- `docs/architecture.md` lists the class and what is left to split

## 0.1.14 — 2026-09-30 — Translations have their own classes
- Three groups of code left `src/App.php` (now about 6,940 lines). **`ThemeStrings`**: the theme's words as the Translations screen edits them (what is shown for a language, what a submitted form keeps or drops: a value equal to the theme's, a ticked reset, and menu labels are never overrides; an emptied override file is removed). **`EntryTranslations`**: the translations of an entry as the admin lists them (which languages each entry of a list also exists in, and one entry's row per language with a link to edit or to start that translation, matched by `translation_id` or, for older entries, by address). **`LanguageAlternates`**: the other languages of a public page (the language switcher's links, and the `hreflang` addresses of an entry, a content type's list, and a category or tag page). Behaviour is unchanged: every check that passed before passes after
- Three one-line wrappers in `App` that only called the content editor were removed; the callers use it directly
- New unit test `translations.php` (40 checks): overrides written, reset, and left alone; entries matched by id, by address, and at different addresses; the switcher and `hreflang` for pages, the home page, posts, a type's list, and tags, including drafts and languages with no translation (which show the default language's entry, so they are listed)
- `docs/architecture.md` lists the classes and what is left to split

## 0.1.13 — 2026-09-30 — Redirects have their own classes
- What the Redirects screen does moved out of `src/App.php` (now about 7,200 lines) into two classes. **`RedirectAdmin`**: checking and saving a redirect typed in the form (a duplicate address, an address that already has a page, the repository's checks), switching one on or off, deleting one or several, importing a pasted list (comma, tab, arrow, or space; 301 or 302; at most 500 lines; the lines that cannot be used are reported with their line number and reason), the list of addresses visitors asked for that do not exist with the closest existing address for each, describing each redirect for the list (its target exists, is a chain, a loop, or missing; a page hides it), and which permanent redirects are ready for the links in content to be pointed at their targets. **`PublicPaths`**: which paths lead to a real page, the list of every published address, the closest address to one that was missed, and how a pasted address of the site itself becomes a path. The delete screen uses `PublicPaths` too. Behaviour is unchanged: every check that passed before passes after
- New unit test `redirect-admin.php` (61 checks) for what had none of its own: which addresses exist (home, pages in each language, drafts, posts, tags, the sitemap), suggestions for a typo, pasted addresses, each action with its result and log entry, an import with good and bad lines, the 500-line limit, and the states of the list
- `docs/architecture.md` lists the classes and what is left to split

## 0.1.12 — 2026-09-30 — Taxonomies, rebuilt
- **A new Taxonomies screen**, laid out like the term managers of modern CMSs. A switcher at the top moves between Categories and Tags (each shows how many terms it has). Inside, two tabs: **Terms** and **Settings and pages**
- **Terms** is a list, not a grid of inputs: the name (the other languages under it, with a "not translated" note where one is missing), the public page address (a link to the page), and how many entries are filed under it, split by content type, each a link to the content list already narrowed to that term. You can **search**, **add** and **edit** a term in a dialog (a name and an optional description for every language, and the page address, with a note that the old address keeps working when it changes), **remove** one or several at once, **reorder** (drag the handle, or the arrow buttons for keyboard and touch), and **sort A–Z**. Removals wait for Save and can be undone (a term removed by mistake is one click away, and the row says how many entries still use it); rows that are new or changed are marked; the bar at the bottom says "Unsaved changes", and leaving the page with unsaved changes asks first
- **Descriptions**: a term can have a short text in each language. The term's page shows it under the title, unless the taxonomy's own subtitle is set (`term_description` is also given to the theme's template)
- **The content list can be narrowed to a term** (`/admin/content?type=posts&taxonomy=tags&term=design`, which is where the entry counts lead): a chip says what it is filtered by, with a way to remove it and a link back to Taxonomies. An unknown term or taxonomy is ignored
- The name and the layout of the pages moved to the second tab; their fields, and the fields of the terms, are the ones the server has always read, so nothing about saving or about addresses, redirects and menu links changed
- **Code**: what the screen does with a submitted form (reading the rows, saving terms and layout, the redirects and menu updates for a moved address, counting the entries under each term in one pass) moved out of `src/App.php` into **`TaxonomyEditor`** (`App.php` is now about 7,400 lines). New unit test `taxonomy-editor.php` (17 checks), the terms unit test grew from the descriptions (49 in all), and the HTTP test grew to 72 (the new screen, the content-list filter, descriptions on the public page, and that a form without description boxes leaves them alone)
- **Accessibility**: the screen was scanned with axe-core in light and dark mode, in every state (list, some rows selected, one removed, the dialog open, the settings tab) and has no violations. The small dark-mode gaps it needed (blue text and borders of the selection bar) are in `app.css`

## 0.1.11 — 2026-09-30 — Taking backups has its own class
- The scheduling and the making of backups moved out of `src/App.php` into **`BackupManager`** (about 330 lines; `App.php` is now about 7,400): when a scheduled backup is due (and the lock that lets only one request run it), a full or database-only snapshot with local retention and the optional upload to remote storage, the run history, the notification, the words and levels for a result, the remote connection test, and the status line of the system check. The screens that ask for backups, the download, and the restore stay in `App`. Behaviour is unchanged: every check that passed before passes after, including the browser-level ones
- New unit test `backup-manager.php` (40 checks) for code that had none: every schedule against its interval, the result wording, a real snapshot on a small test site (name, reason, version, recorded size), local retention (a safety snapshot before a restore never deletes and is never uploaded), remote upload with a stand-in storage (success with object and pruned count, failure as a warning that keeps the archive), one notification per outcome and no repeats, the scheduled run (off, unknown schedule, due, just ran, another request holding the lock), and the overdue status
- `docs/architecture.md` lists the class and what is left to split

## 0.1.10 — 2026-09-30 — The site settings have their own class
- Reading, defaults, the values the settings form shows, and saving a submitted form moved out of `src/App.php` into **`SiteSettings`** (about 500 lines; `App.php` is now about 7,700). Behaviour is unchanged: every check that passed before passes after, including the browser-level ones. It covers the settings stored in the system database (and the old `content/settings/site.yaml`, read once), the checks on languages, menu locations, backup schedule and remote storage, updates, the robots box, and the limits, and the secrets rule (they never go back to the browser; a blank input keeps the stored one; only "remove" clears it)
- New unit test `site-settings.php` (39 checks) for what had none: the old file read once, defaults, blank and invalid values falling back, list and slug clean-up, the port as a number, secrets kept, replaced or cleared, a form without the robots box leaving the rules alone, and no database meaning nothing is saved
- `docs/architecture.md` lists the class and what is left to split

## 0.1.9 — 2026-09-30 — App.php is smaller: five groups of code have their own classes
- `src/App.php` went from about 9,800 to 8,200 lines. Behaviour is unchanged (every check that passed before passes after, including the browser-level tests); what moved: **`Menus`** (the menu files, checking, nesting from the admin form, theme locations, labels, the active item; 605 lines), **`ContentCsv`** (the CSV export and the two-step import with backups and rollback; 750 lines), **`StructuredData`** (the JSON-LD graph and its script tag), **`FormFields`** (reading form fields for the site and the editor, next to the code that already cleaned them on the way in), and two small shared helpers, **`ArrayPath`** and `Format::list` / `Format::commaList`, which replace private copies
- Each new class takes what it needs through its constructor, with closures for what can change during a request (the settings, the language's strings), so it can be used and tested on its own. Unit tests were written for them, which the code did not have: `menus.php` (40 checks), `content-csv.php` (70: reading files, every kind of preview row, applying, a stale file, a failed write, raw HTML), and `form-fields.php` (20), 130 in all
- `docs/architecture.md` lists the new classes and what is left to split

## 0.1.8 — 2026-09-30 — The "where is it used" scan is kept between visits
- Finding where each media file is used means reading every content file. That result is now kept in the system database with a **fingerprint** of what it was made from: the name, size, and time of change of every content file, and the settings texts. Opening Media only asks the file system for those facts (nothing is read), and while the fingerprint is the same the kept result is used; any edit, new page, deletion, or settings change makes it differ, so the answer is never out of date and nothing has to be cleared by hand. On a test site of 4,000 content files the check takes about 15 ms instead of about 100 ms, and with 20,000 files about 80 ms instead of about 550 ms (with the files already in the operating system's cache; a slow disk gains more)
- A kept copy that is damaged, from another format, or missing its map is ignored and made again; a result over 4 MB is not kept. New test `media_usage_cache_test.py` (17 checks) proves the kept result is really the one used (by planting a false one), and that content changes, a page letting go of a file, a file deleted by hand, and a settings change each bring the real answer back

## 0.1.7 — 2026-09-30 — Keep paths out of search engines' reach (robots.txt)
- **Settings > General > Search engines**: a box for paths crawlers should not visit, one per line (`/private/`, `/thank-you`, `/*.pdf$`). They become `Disallow` lines in `/robots.txt`, between `Allow: /` and the sitemap. With nothing listed the file is exactly what it was before. A line typed as a whole rule (`Disallow: /x`) is accepted; comments, full addresses, paths with spaces, and anything that is not a path are dropped, duplicates are removed, and at most 100 rules of 200 characters are kept, so nothing typed can add a line of its own to the file. A rule of `/` closes the whole site to crawlers (useful for a staging copy). The text says that robots.txt only asks well-behaved crawlers to stay away and is not protection, and points to the per-page "No index" setting for keeping a page out of results
- The file is written by a small class (`src/RobotsTxt.php`), with unit tests (19) and an HTTP test (19): the default file, saving, checking, that a form sent without the box does not clear the rules, and that only who may change settings can

## 0.1.6 — 2026-09-30 — Fuller structured data for search engines
- **Posts and projects**: a post is a `BlogPosting` and a project is now an `Article` (before, projects had none). Each names its **author** (the person typed in the editor; before, it was always the organization), the **date published** (a date that the YAML reader had turned into a Unix time was ignored before, so posts written that way had no date), the date modified, the summary, and a **picture**: the entry's main image, then its SEO share image, then the first picture in its blocks, then the site's default share image, always as a full address. The headline is cut at 110 characters, which search engines require
- **Every other page** is a `WebPage` (name, address, language, summary) that points to the site
- The **organization** carries a contact point when the Footer has a phone number or an email
- The home page keeps its `WebSite` with the site search action, per language, and the search page it points at is checked by the tests. Text typed in the admin cannot end the script tag (checked with a title and an author made of markup)
- New test `seo_jsonld_test.py` (31 checks)

## 0.1.5 — 2026-09-30 — The admin passes an accessibility audit
- Every admin screen and every state that needs a click (the icon and image pickers, confirmation dialogs, messages in the bottom bar, the phone sidebar, the block editor with every block open, each tab of the editor, Settings, and Theme) was scanned with axe-core in light mode, dark mode, and at phone width. It found real problems, all fixed; the scan now reports none. Details and how to repeat it: `docs/admin-accessibility.md`
- **Muted text was too pale**: the grey of helper text, table headers, and labels was 2.6:1 on white (4.5:1 is the minimum), and several other greys, the green and orange text, and some dark-mode greys were also short. The four Tailwind shades involved are nudged in `tailwind.config.js` (the markup is unchanged), the dark-mode shades are lightened, a few dark-mode gaps are filled (translucent panels, delete buttons, message text), and a few places that used near-invisible grey for meaningful text (a missing translation, "new" in taxonomies) are readable now
- **Tab bars are real tabs** for assistive technology: roles, the panel each controls, which one is selected, one tab stop, and the arrow, Home, and End keys (the editor, Settings, and Theme each switched their tabs their own way, and none said so to a screen reader)
- **Names for controls**: the body and front matter boxes of the editor, the snippet menu, custom field rows, media checkboxes and search, taxonomy and translation fields, menu locations, and "rows per page" now have names; the empty table headers have hidden text
- **Headings**: screens went from the `h1` straight to `h3`; cards are `h2` now (the editor, Settings, user editor, lists, and empty-state notes)
- **Wide tables** that scroll sideways on a phone can be focused, so a keyboard user can scroll them
- **A message in the bottom bar** is shown to assistive technology while it is up (its Dismiss button takes focus for an error, and the layer was hidden from screen readers)
- New test `admin_a11y_test.py` keeps the parts that need no browser from slipping back: heading order, control names, tab panels, and the colour tokens

## 0.1.4 — 2026-09-30 — One image picker with search and pages
- Choosing a picture from the library is one dialog everywhere: the **main image** of an entry, the **image fields of blocks**, and now the **image settings** of the theme (logo, share image, footer background) and of content types, which had only a text box. The dialog has a **search box** (name or tag), a **tag filter**, and **pages** (24 at a time, with Previous and Next), and asks the server for one page at a time. Before, the editor page carried the first 120 pictures inside it, had no search for the main image, and could not reach the 121st picture at all
- Nothing is embedded in the editor page any more, so pages with a large library open faster. The dialog is keyboard friendly and named for screen readers. Anyone who can edit content, use Media, or change settings may use it (`/admin/media-picker`, JSON)
- New test `media_picker_test.py` (29 checks)

## 0.1.3 — 2026-09-30 — Where each media file is used
- **Media** shows, for every file, **where it is used**: "Unused", or "Used in 3 places" that opens a list of links to the pages, posts, projects, forms, menus, taxonomies, and the site and theme settings that point at it (in a list and in the grid). A file counts as used when its address appears in a content file (front matter, blocks, or text) or in the settings; it does not know about links people typed by hand elsewhere
- **Deleting a used file asks first** and names the places ("This file is used in About, Home page and 2 more"); the server refuses if it is not confirmed. **Bulk delete keeps files in use** and says how many it kept. A new **Use** filter (Any, In use, Unused) and an "N unused" link in the toolbar find the files nothing points at; with "Apply to all filtered" this cleans up every unused file at once
- The activity log records which places a deleted file was still used in. New test `media_usage_test.py` (26 checks)

## 0.1.2 — 2026-09-30 — The version is visible on GitHub
- Every time the `VERSION` file changes on `main`, a GitHub Actions workflow (`.github/workflows/release.yml`) tags it (`v0.1.2`) and publishes a **Release** whose notes are that version's entry from this changelog. The repository page now shows the latest version and the list of releases, and the README has a version badge. Until now the number lived only in the `VERSION` file, so nothing on the repository page showed it
- The site's own update check is unchanged: it compares its local `VERSION` with the `VERSION` file on the configured branch. It reports an update only when the site is older than GitHub, so a site running this very code (such as the development copy) is always "up to date"

## 0.1.1 — 2026-09-30 — Line endings, roadmap, and the Actions warnings
- Saving content from the editor now writes line breaks as LF. Browsers send a text box's line breaks as CRLF, so a page edited in the admin came out with some lines ending in CRLF and Git warned about it on every commit (and would show whole files as changed). Applies to the Markdown body and to raw front matter typed in the Advanced tab. `.gitattributes` also keeps code, docs, and tests LF on every machine, and marks fonts and images as binary
- The GitHub Actions workflow uses `actions/checkout@v7` (v4 ran on Node 20, which is being retired) and a fixed `ubuntu-24.04` runner instead of `ubuntu-latest`, which changes version on 19 October
- The roadmap is brought up to date: what has been done since it was last edited is listed, and items that were done or no longer apply (custom roles, the right utility rail, image transforms, the first theme polish list) are removed

## 2026-09-30 — A lighter repository
- The `_reference/` folder (the old PicolinoCMS code and the admin HTML templates the admin was built from, 39 MB in 5,600 files) and the unused `scripts/vendorize.py` are removed from the repository; they stay in the Git history. The working notes `docs/todo.md`, `docs/migration-plan.md` and `docs/admin-gap-list.md` and the local `.claude/` folder are no longer tracked (they are in `.gitignore`). Full backups no longer list `_reference/` as excluded, since the folder is gone

## 2026-09-30 — Works on PHP 8.1 again
- One migration in the system database was declared with the return type `null`, which only exists from PHP 8.2, so on PHP 8.1 (the stated minimum) every page failed with a fatal error. It is `void` now. Found by the new GitHub Actions run on PHP 8.1
- A test of roles left a database connection with an open write transaction, which locked the database for the rest of the run on Linux (each request waited five seconds); it closes its connection now

## 2026-09-30 — Tests run on every push
- A GitHub Actions workflow (`.github/workflows/tests.yml`) runs the whole suite (PHP unit checks and the HTTP tests) on PHP 8.1, 8.3 and 8.5 for every push to `main` and every pull request. The README's Tests badge is now the live result of that workflow

## 2026-09-30 — The storage size is measured every 12 hours
- Adding up every file in uploads, content and the system folder took about a minute on a large site, and it ran on the first admin page of each request that needed it. The result is now kept in the system database with the time it was measured and used for **12 hours**; after that the next admin page measures again. Uploads and deletions in Media adjust the kept size at once, so the storage limit still holds between measurements, and a kept value that is missing, damaged, incomplete, or dated in the future is simply measured again
- **Settings > Limits > Storage** says when it was last measured, and a **Recalculate now** button (super admin only, written to the activity log) measures at once, for example after copying files in by hand. Content saved and backups taken change the size a little between measurements; the figure catches up at the next one

## 2026-09-30 — A typo in one file no longer takes the site down
- A content file whose front matter cannot be read (for example an unquoted comma inside `{ … }`) used to make **every page** of the site fail with a fatal error. Now only that file is affected: it is treated as an unpublished draft (visitors do not see it, and it is not in the sitemap), shows an **Unreadable** badge in the content list, and every admin gets one notification (per file) with the file name, what the parser said, and a link to it. The rest of the site, the admin lists, search, and the dashboard carry on
- The editor still opens such a file, with a red notice, the parser's message, and the raw front matter in the Advanced tab to correct. Saving front matter that still cannot be read writes nothing and returns to the editor with the reason (before, it was an error page). Once it reads again the page is public again and the badge is gone

## 2026-09-30 — Every message in the bottom bar
- All admin messages now use the bottom bar, including on screens with no Save button (Updates "Check status", Media, Menus, Import, Backups, and the like). Before, only screens with an action bar did; the others still showed the old boxes in the corner. On a screen without a bar, one with no buttons slides up for the message (same colours, countdown line, Dismiss, Escape, and queueing) and away again. The old corner toasts, the copy "URL copied" box, and the boxes that some screens drew themselves are gone; the last inline error boxes (Import, Backup restore) are messages too now. Explanations with their own buttons (the address-change notice, the delete page) and the permanent banners (default password, storage) stay where they are, since they are not passing messages

## 2026-09-30 — Upload limits in Settings
- **Settings > Limits > File uploads** (super admin only): the **largest file** (default 20 MB, 0 to let the server decide) and the **kinds of file allowed** as tick boxes: images, SVG drawings, documents, archives, audio, video. The page says what the server itself allows (PHP `upload_max_filesize` and `post_max_size`) and the real limit is never higher than that. The size used to be a hidden setting; a site that had set it keeps its value. A file that is too big or of an unticked kind is refused with the reason. Dangerous kinds (programs, scripts, web pages) can never be ticked, at least one kind always stays on, and changes are written to the activity log
- The **Media** screen shows the real limit and the kinds under the drop text, and its file field only offers the allowed kinds. The extra **Upload files** buttons (at the top and in the empty library) are gone, since the upload row is always in view

## 2026-09-30 — A compact upload row in Media
- The upload area of **Media** is one row instead of a tall box: a small icon with "Drop files to upload / max 20 MB per file", the file field, the tags field, and the Upload button. On a phone the fields stack. Files can be dropped anywhere on the row (it turns blue while you drag over it), where before only the file field itself took a drop. The tags field keeps its label for screen readers

## 2026-09-30 — Clearer number pills on tabs
- The small number on a tab (blocks in a page, versions in its history) has a darker grey background, so it stands out from the page; on the current tab it is a shade darker still. It is one `.tab-count` style in the stylesheet, with a dark-mode variant

## 2026-09-30 — One tab style for the whole admin
- Tabs (the content editor, Settings, Theme, Logs, Users & Roles) now look the same and match the rest of the admin: quiet grey text on a hairline, a light wash on hover, and the current tab in dark text with a 2px dark underline. Before, the colours came from three different scripts and stylesheets that fought each other (dark filled tabs, boxed white ones, and blue underlines depending on the screen). Now the look is one rule in the stylesheet, keyed on `.active` / `aria-selected` / `aria-current`, with a visible keyboard focus ring and a dark-mode variant

## 2026-09-30 — The theme has its own screen
- **Theme** moved out of Settings into its own screen under **Manage** in the admin sidebar (after Menus). Every section the theme declares is a tab (Appearance, Brand, Header, Hero Layouts, Transparent Header, Footer, Social profiles, and whatever a theme adds), with one Save for all of them; the tab you were on is kept after saving, and `admin/theme?tab=header` opens a tab directly. Same permission as Settings (`settings.manage`); changes are written to the activity log
- Settings lost its Theme tab (an old link to it lands on the new screen). Saving Settings no longer touches the theme options at all, so it cannot reset one by mistake. Choosing which theme the site uses stays in Settings > General, and menu locations stay in Settings > Menus

## 2026-09-30 — View site at the top, the version at the bottom
- In the admin sidebar the **View site** button moved to the top, beside the site name (a small button with an arrow, announced as opening a new tab), and the **FarosCMS v…** version badge moved to the bottom, above the storage bar. The badge still turns blue and links to Updates when an update is available

## 2026-09-30 — Content types, Redirects and Translations under Manage
- **Content types**, **Redirects** and **Translations** moved from the System group to **Manage** in the admin sidebar, after Taxonomies and before History, since they shape and organise the content rather than the installation. Their addresses and permissions did not change. The Content types item now also tells screen readers when it is the current page

## 2026-09-30 — The sidebar shows the site's own name
- The top of the admin sidebar no longer has the "FC" logo square. It shows the **Site name** from Settings (cut with "…" when it is long, with the full name on hover), and the version badge now reads **FarosCMS v0.1.0**. The name follows Settings, so a client's admin carries the client's name

## 2026-09-30 — Content as a section of the menu
- In the admin sidebar **Content** is now a section heading, like Manage and System. Under it, **Pages**, **Posts**, **Projects** (and any other content type) are ordinary menu items with their own icons, and **Media** moved there from Manage. The old "Content" link to the list of everything and the small indented list under it are gone (the address `admin/content` still works). Every sidebar item now also tells screen readers when it is the current page

## 2026-09-30 — Users & Roles in one section
- **Users** and **Roles** are now the two tabs of one **Users & Roles** section: one entry in the sidebar instead of two, and a tab strip at the top of each screen. Editing or adding a person keeps the Users tab and the sidebar entry current. The addresses and permissions did not change (`users.manage` for Users, `roles.manage` for Roles, both only for the super admin). A person editing only their own profile sees no tabs

## 2026-09-30 — One Logs section
- **Activity** and **Email logs** are now the two tabs of one **Logs** section: one entry in the sidebar (System group) instead of two, and a tab strip at the top of each screen to switch between them. The addresses, filters, and permissions did not change: a person who may read only one of them sees only that tab, and the sidebar entry opens the first one they may use. The top bar says "Logs" on both

## 2026-09-30 — A bottom bar you edit, and one icon picker
- **Bottom bar on phones** (Theme settings > Header): while it is on, the **hamburger button leaves the header** on phones (the bar has its own Menu button, always first), and the links beside it are a list you edit: up to four, each with an **icon**, a label, and a link (a page, a path, `tel:`, `mailto:`, or a full address). Rows can be added, removed, and moved. With an empty list the bar keeps its automatic links (call, email, and the header button). A row without a link is left out, and a link without an icon gets an arrow. On wider screens, where the bar is off, the header button stays
- **Icons are chosen from a popup of small pictures**, with a search box, instead of a list of names. It is one picker for every place an icon is chosen: the fields of blocks (Features, Contact, Banner, and any block a site adds with `type: icon`), the bottom bar, and any theme setting that declares `type: icon`. They share one library, the theme's `icons/` plus the site's `custom/icons/`, so an icon added there shows up everywhere. Works with the keyboard (arrows, Enter, Escape) and on a phone
- Theme settings can now hold **lists** (repeaters) with the same add, remove, and move controls as the block editor. New field type `icon` for `theme.yaml` and `block.yaml` (choices are the icon set). Rows left empty are not saved. Theme version 1.13.0

## 2026-09-30 — The phone menu, and an accessibility pass
- **Fixed the phone menu breaking on the new heroes.** The menu panel sat inside the header. A header that is sticky or blurred (it scrolls with the page) became the frame of the panel, and a transparent header over a dark hero handed it light-on-dark colours, so on a phone the menu showed as white text on the page behind it. The panel now sits after the header, outside it, and looks the same everywhere
- **Five ways to open the phone menu** (Theme settings > Header > Phone menu): side drawer from the left (as before, the default), side drawer from the right, full screen with large links, a sheet dropping from the top, and a sheet rising from the bottom (it has a handle and suits the bottom bar). All use the same markup, so the list, sub-menus, search, language links, and the header button are the same in each
- While the menu is open the rest of the page is **inert** (no keyboard, pointer, or screen-reader access to what is behind it), focus goes to the close button and returns to the opener, and Escape closes it. The panel is the full visible height on phones with a moving address bar
- **Accessibility scan at phone width, and with the menu open, in light and dark** (`themeAudit.pages(paths, {width: 375, menu: true})`): the home page, the blocks page, cover and steps heroes, a post, and every one of the five menu styles. What it found and what changed: the comparison table's scroll area was a second landmark with the same name as its section (now a named group); the visually hidden texts in the comparison table widened the whole page at 320 px (the table now contains them); an empty list was announced for a Cards block with nothing to show (no list is written); a title area with a photo now has a dark base, so its light text stays readable while the picture loads or if it never does, and the transparent header gets a darker top edge over a photo. Text over photos (about 30 places on the blocks page) is left to a human eye, as the tool says. Theme version 1.12.0

## 2026-09-29 — Choose how each entry opens
- **Every entry can choose its own title layout** (Default, Centered, Split, Cover, Minimal) in its editor, for pages, posts, projects, forms, and any other content type: the new card **Hero Layout** in the right column. "Follow settings" (the default) uses Theme settings > Hero Layouts and says what that currently is. Kept in the front matter as `hero_layout`
- **Transparent over an opening hero** can now be set **per content type** (Theme settings > Transparent Header: Site default, On, Off for pages, posts, projects, forms, and other types) and **per entry** (`header_transparent`, same card). Site default follows Header > Transparent over an opening hero. The header now also sits over the **title area** of a page, not only over a Hero block: room is left above the title, and over a Cover (or a Default with an image) the header text turns light. A site that already had the Header setting on will see it over title areas too; set the content type to Off to keep them solid
- The decision is made in one place, `components/hero-layout.twig`, used by the layout and the title area. Templates that show the title area set `title_header`. Theme version 1.11.0

## 2026-09-29 — Title layouts like the Hero block
- **Theme settings > Hero Layouts** (the title area of pages, posts, projects, and forms) now offers the layouts of the Hero block besides Default and Centered: **Split** (text beside the image), **Cover** (text over a full-width image, with the same two shades that keep the text readable) and **Minimal** (a large centred heading, no image). Split and Minimal get the soft palette glow of the Hero block. A Cover without an image falls back to Default. The line above the title (date, tags, categories, author) is kept in every layout
- The five templates that each carried their own copy of the title markup (page, post, project, form, sidebar) now share one component, `components/page-header.twig`, so a layout is written once. Covered by `tests/http/hero_layouts_test.py`. Theme version 1.10.0
- Removed **Theme settings > Home Sections** (show latest projects/posts and their limits). It only applied to a home page without blocks; a home page built with blocks ignored it, and the **Latest** block does the same job with more control. A home page with no blocks now shows just its title, text, and image. Values already stored are left alone and ignored

## 2026-09-29 — Hero with numbered steps
- The **Hero** block has a new layout, *Text over a full-width image, with numbered steps*: the cover hero with up to four numbered steps (a title and a short text each) under the heading and buttons, in the style of Features > Numbered steps. It works with a transparent header like the cover layout, is on the blocks showcase page, and is covered by tests. On phones the steps stack. Each step can also have a link (and link text): its title becomes the link, which covers the whole step, and a "Learn more" hint with an arrow shows under it, as in Features

## 2026-09-29 — Spacing after a cover hero
- A section right after a hero with a full-width cover image lost its top space (two sections on the same background share one gap, but the cover image is a background of its own), so the Logos block's heading touched the image. Every block after a cover hero now keeps its top space. Theme version 1.8.1

## 2026-09-29 — A storage limit, and a quieter dashboard
- **Storage limit** (Settings > Limits, super admin only, default 1 GB, 0 for none): a way to give a client a fixed amount of space and watch it. What counts is uploads, content, and the system database with its backups. The bar in the sidebar and on the dashboard shows use against the limit ("128 MB / 1 GB"), turns **orange from 80%** and **red from 90%**, and from 90% every person in the admin sees a message at the top of every page with a button to write to the super admin (a ready-made email with the site name and the figures; the super admin sees a button to change the limit instead). At 100% new uploads (the media library and a picture added while editing) are refused with the reason, while editing, saving, and backups carry on. The change is written to the activity log
- New permission `limits.manage`, held only by the super admin and never grantable, so an admin cannot lift a limit that was set for them
- Removed the **Quick actions** panel from the dashboard and the **+ New** button from the top bar

## 2026-09-29 — One title per admin page
- The page title now appears once, in the top bar, and is specific to the page ("Edit Page", "Import Pages", "History of …", "Delete “…” (EL)"). The repeated heading inside the content is gone; the description and the buttons beside it stay. The grey line under it ("FarosCMS admin / Menus") is gone too. Every admin page has exactly one `<h1>`. A screen sets its title with `{% set page_heading = … %}` after `extends`

## 2026-09-29 — System in the account menu
- **System** (health checks, scheduled tasks, environment) moved out of the sidebar into the account menu at the top right, under Settings. It only shows information, so it no longer takes a place among the things you work with

## 2026-09-29 — Roles of your own
- **Admin > Roles** can now make roles beyond Admin, Editor, and Basic user (a photographer, a translator, an intern): a name, a description, and a starting point (only signing in, or a copy of Basic user, Editor, or Admin). A new role becomes a column in the permission table, where you tick exactly what it may do; it appears in Users for giving to people, with its own badge, and can be renamed. Up to 20 per site
- The same limits as the built-in roles: signing in and the own profile stay on, and managing users, roles, and restoring backups can never be given. A role cannot be deleted while someone has it. A form that names a role that does not exist gives the person Basic user
- Permission changes to your own roles are written to the activity log with what was added and removed
- The permission table now also ignores a role that was created in another window after the page was opened, instead of clearing it

## 2026-09-29 — Messages take over the action bar
- On every screen with the bottom action bar, messages (saved, warnings, errors) no longer appear as separate boxes: the bar turns into the message. The buttons slide up and away, the message comes up from below, and the bar takes its colour (green, amber, red) with a thin countdown line. It goes back by itself after three seconds (longer only for a long message); an error stays until it is dismissed (Dismiss or Escape). Several messages show one after another. Screen readers get the message through a live region, and with reduced motion there is no movement. Screens without a bar keep the small box at the bottom right

## 2026-09-29 — A fixed action bar, and two new blocks
- **Save and its companions stay at the bottom of the window** on every screen with a form to save: content editor (Preview, Delete, Save), Settings (Discard, Save), content types, roles, menus, new menu, taxonomies, users, and translations. One shared bar (`partials/action-bar.twig`) sits to the right of the sidebar on wide screens and across the window on phones, so nothing has to be found at the top of a long page. Notifications moved above it
- New block **Comparison table** (`compare`): up to four columns, one of them highlightable with a badge and a button, up to 30 rows with group headings; `yes`/`no` become a check or a cross with text for screen readers. A real table in a named, focusable, scrolling region. Lines and striped variants
- New block **Before and after** (`before-after`): a slider you drag or move with the arrow keys (a native range input laid over the picture), or the two pictures side by side; without JavaScript they sit side by side. Labels, shape, and caption are editable
- Both blocks have ready-made sections, are on the blocks showcase page, and are covered by tests. Theme version 1.8.0

## 2026-09-29 — Layouts per taxonomy, links after an address change, and deleting with a redirect
- **Each taxonomy has its own page layout** (Admin > Taxonomies, "How its pages look"): the same choices a content type has (layout, columns, order, items per page, what shows on each entry, page title and subtitle, filters from other taxonomies) plus which content types are listed, so categories can be a magazine and tags a plain list. `{term}` in the title or subtitle stands for the term's name. Category and tag pages are now paged, filterable, and list entries of several types newest first. The archive form is shared with Content types
- **Term addresses are automatic**: a new category or tag gets its address from its name, with Greek converted to Latin (`Ελληνική κουζίνα` → `elliniki-kouzina`). Changing an address leaves a permanent redirect in every language and updates menu links; entries keep referring to the term by its id, which never changes. Removing a term that entries still use warns how many. The terms table shows how many entries use each term
- **Links inside content follow an address change**: after an address changes, the editor is told how many links in other content still use the old one, and *Review and update* points them at the new address (text, HTML, and block buttons; full addresses of the site and anchors and query strings are kept). Admin > Redirects has a Links column for every permanent redirect and the same review screen. Only the address in the text changes, earlier versions stay in the history, and menus and theme settings are not touched
- **Deleting a public entry asks where visitors should go**: nowhere, the list of its type, the home page, or any address (on the site or another website), which becomes a permanent redirect; the page also shows how many links elsewhere point to it. Drafts are deleted as before. Bulk delete now keeps the deleted text in the history, which it did not before, and says how many public entries went
- New `Taxonomies` and `LinkScanner` classes; taxonomy storage moved out of `App.php`

## 2026-09-29 — Tests, a smaller App.php, and revision history
- **History** (Admin > History, and a History tab in the editor): every save, import, restore, and delete keeps the whole text, so an earlier version can be compared line by line ("what this version changed" or "if you restore it") and brought back. Deleted items are listed and can be brought back exactly as they were. The latest 50 versions of each item are kept, and deleted items for 180 days
- A file changed outside the editor (by hand or through git) is kept as its own version before the next save overwrites it, and the first save of an item that existed before history began keeps what was there
- History follows an item when its address changes, in every language that moves with it
- Restoring passes the same raw HTML rule as saving: someone who may not add HTML gets any HTML that is not already in the current file shown as text. Forms are visible in history only to people who may manage forms
- Content saving moved out of `App.php` into `ContentEditor`, with `HtmlGuard`, `FormFields`, `ContentPaths`, and `FrontMatter` alongside it; `App.php` is about 500 lines shorter and behaves the same (25 public pages compared before and after)
- **Test suite**: `tests/run.sh` runs 660 checks (PHP unit checks, and HTTP tests of the editor role, roles, import, redirects, saving every field, and history) against a temporary copy of the site with fixtures; `tests/README.md` explains what is covered. `scripts/check-blocks.php` validates a site's blocks

## 2026-09-29 — Web addresses and redirects
- New content gets its address from the title, with Greek converted to Latin by the ELOT rules (`ου` → `ou`, `μπ` → `b`, `ευ` → `ev`/`ef`, …); the editor shows it live and nobody types a slug. A title that is already used gets `-2`, and a page cannot take a word the site uses itself (`admin`, `search`, a content type, a language code). Before, saving a new item with a used address silently overwrote the other one
- Changing an address is a deliberate step ("Change" beside the address): the old address becomes a permanent (301) redirect, the other languages can move with it, menu links follow, and redirects that already pointed at the old address are repointed so nobody goes through a chain. The home page and forms keep their address. Drafts that were never public leave no redirect
- New screen **Admin > Redirects** (permission `redirects.manage`, admin and super admin by default): every redirect with its visits, whether the target still exists, and whether a page already sits at the old address; add, edit, turn off, delete, bulk add from a list, filter by origin or use. A pasted full address of this site becomes a path
- **Not found** tab: addresses visitors asked for that do not exist, most asked first, with the referring site and a suggested page; one click makes a redirect. Files and probes (`.php`, `/uploads/`) and POST requests are not recorded, and the list is capped
- Redirects keep the query string, are case-insensitive, stop after six hops, and treat a loop as Not found. Targets are paths on this site or `http(s)` addresses only
- `scripts/check-slugs.php` checks the conversion and the redirect store

## 2026-09-29 — Permissions per role
- New screen **Admin > Roles** (super admin only): a matrix of every permission against Admin, Editor, and Basic user, with a description and a Sensitive or Critical label on the ones that can expose personal data or change the site itself; switching one of those on asks for confirmation
- Changes apply on the person's next click, are stored in the system database (`role_permissions`, only for roles that differ from the built-in set), are written to the activity log with what was added and removed, and can be undone per role with "Reset"
- Never switchable: the super admin's own permissions, and managing users, roles, and restoring backups; sign-in and editing one's own profile are always on for every role
- Importing content from CSV now follows the raw HTML rule, so granting import does not open a way around it
- `scripts/check-permissions.php` also checks custom permissions

## 2026-09-29 — Editor role
- New role **Editor**: writes, edits, publishes, and deletes pages, posts, and projects, and manages media, categories, and tags. It cannot open forms, menus, settings, content types, translations, users, logs, backups, updates, or import/export, and it gets a content-only dashboard without system figures or notifications
- Raw HTML is an administrator privilege: an editor's HTML is shown as plain text (with a notice), while HTML an administrator placed in a page stays intact when an editor saves it; this covers the text, block Markdown fields, and raw front matter
- Markdown links with script addresses (`javascript:`) lose their address for everyone
- Admin actions with no explicit permission are now administrator-only instead of open to anyone who can edit content
- Users screens list all roles with descriptions; new users default to Editor, and an unknown or missing role can no longer turn into Admin
- Refused requests for forms are written to the activity log

## 2026-09-29 — Demo content, accessibility pass, and developer guide (phase 7)
- Demo content: Services, Design & Build, Project Management, Careers, and FAQ are built from blocks (pricing, tabs, timeline, FAQ, cards) in Greek and English; Privacy, Terms, and Cookies use the sidebar template with a contents list; posts and projects are full articles and case studies with results, gallery, and a quote; project excerpts describe web work for each client
- Accessibility (checked with axe-core on every page in light and dark, plus the mobile menu, image and video viewers, and open FAQ): blocks without a heading give their items `<h2>`; contact details are a real list; the slider follows the WAI-ARIA carousel pattern (no duplicate landmark); submenus close with Escape; the sidebar layout no longer widens on phones
- Markdown tables are supported and scroll in a keyboard-focusable box on narrow screens
- Sites can add their own page templates in `custom/page-templates.yaml`
- `docs/theme-developer-guide.md` (recipes, quality checklist, troubleshooting) and `scripts/theme-audit.js` (axe-core scan, 320 px reflow, palette contrast)
- Theme version 1.7.0

## 2026-09-29 — Content types with declared fields (phase 6)
- Content types: definitions in `themes/default/content-types/` (projects and posts ship with one) and `custom/content-types/`, merged per field; fields use the same schema as theme settings and blocks, plus a new `date` type
- Admin > Content types: create a type, set its title and archive layout, and add, reuse, or retire fields; the site's file records only differences from the theme
- Editor: a "<Type> details" tab with an input per declared field, values stored under `custom_fields` (existing content keeps working)
- Pages: declared fields appear as a fact sheet on the item's page and, where marked, on its card; the project page no longer hard-codes client, location, and duration
- Archives: any of the eight Latest-content layouts, ordering (including by a declared field), pagination, and filters from taxonomies and select fields; filtered pages are `noindex`, paginated pages have their own canonical URL; category and tag pages use the same layout
- The demo projects gain a sector (a filter) and a year

## 2026-09-28 — Dynamic and interactive blocks (phase 5)
- New blocks: latest content (8 layouts from a minimal text list to a magazine, optional category or tag filter), video (large viewer by default, YouTube, Vimeo, or a file, nothing loaded until played), slider, pricing, tabs, and banner (dismissible)
- Ready-made sections: magazine posts, project tiles, video, plans and pricing, services in tabs
- Icons: play, pause, chevrons, info, megaphone, x
- Dynamic blocks with nothing to show (no matching content, no valid video) render nothing instead of an empty section
- Theme version 1.5.0; the showcase page includes every new block and variant

## 2026-09-28 — Page templates and ready-made sections (phase 4)
- Page templates: Standard, Landing (no title header, blocks only), and With sidebar ("On this page" contents, related pages from the main menu, contact card from Theme settings > Sidebar template); chosen per page in the editor's Publish panel
- Ready-made sections: 8 section presets and 5 page layouts (company home, service, about, contact, campaign landing) in Greek and English; page layouts can replace or follow existing blocks and set their suggested template
- Saved sections: tick blocks in the editor and save them as a reusable section in `custom/presets/` (kept across updates and in backups); delete from the picker
- Hero: the split layout without an image uses one wide column
- Demo: the Workplace Strategy pages use the sidebar template

## 2026-09-28 — Block editor, second block family, header and footer options (phase 3)
- Admin: Blocks tab in the editor with a block picker, move, duplicate, hide, remove with undo, schema-generated fields (including repeaters, Markdown, and image fields with a media library picker); the server re-checks values and stores only non-default ones; existing blocks are kept when the editor cannot load
- Blocks: gallery (grid, masonry, strip, accessible viewer), team, timeline, contact (details with an optional form), and map (OpenStreetMap, loaded on request by default); demo About and Contact pages use them
- Blocks: optional `block.js` per block, bundled and deferred like block CSS; new `decimal` field type
- Header: classic, centered, minimal, and stacked layouts; transparent over an opening hero; sticky modes; CTA button; top bar; bottom action bar on phones
- Footer: columns, one-row, and centered layouts
- Fonts: Inter is self-hosted with the theme (Latin and Greek subsets, preloaded per language, no third-party requests); new "System fonts" option
- Fixes: Greek initials drop the accent (ΑΡ, not ΆΡ); timeline steps wrap instead of scrolling

## 2026-09-28 — Design system and first block family (phase 2)
- Blocks: pages and posts can list `blocks:` in front matter; 12 blocks (hero, content, text, text-image, features, stats, testimonials, logos, faq, cta, cards, form) with variants, background tones, and spacing; values are checked against each block's `block.yaml`
- Blocks: an opening hero becomes the page title; block CSS loads only where used, bundled into one request per page; a hidden `/blocks` showcase page shows every block and variant
- Design system: new `site.css` with tokens for palette (six palettes, light and dark), fonts, and corner shape (new setting); the palette and font settings now change the site; dark mode follows the system without a flash
- Images: `image()` renders width/height, WebP srcset, lazy or high-priority loading; variants are generated on first request under `/uploads/_v/`, excluded from backups, and removed with the media item
- Media: default alt text per image (Admin > Media), used when a page does not give its own
- Accessibility: skip link, visible focus, reduced motion, accessible mobile menu (dialog, focus handling, Escape), one link per card, labelled form controls with linked errors, correct heading levels in templates and demo content; checked for WCAG AA contrast in all palettes
- SEO: page titles with the site name, Open Graph type/site/locale/image, Twitter cards, share-image fallbacks, and a JSON-LD graph (Organization, WebSite, BlogPosting, BreadcrumbList, FAQPage)
- Theme settings: Brand (logo, default share image) and Social profiles; footer social icons show only when set
- Admin: saving a page keeps its `blocks:` intact (it was previously turned into text custom fields)
- Local development: run `php -S 127.0.0.1:8087 -t public public/index.php` so generated files work as on Apache/nginx; the nginx example in `docs/security.md` now falls back to `index.php`
- Demo content: home pages rebuilt with blocks; English projects pointed to an existing image; post headings start at h2

## 2026-09-28 — Theme foundation (phase 1)
- Theme: `themes/default` is reorganised into `layouts/`, `templates/`, `components/`, and `assets/`, with a `theme.yaml` manifest; rendered pages are unchanged
- Theme: the admin Theme tab is generated from the manifest, and stored theme settings are checked against it on every request (new fields get defaults, invalid values fall back)
- Theme: theme CSS/JS are served from outside `public/` at `/_themes/default/…` with versioned, long-lived caching; the inline theme script moved to `assets/js/site.js`
- Update safety: new `custom/` folder for site-specific CSS/JS, template overrides (by path or by extending `@theme/…`), and string overrides; updates never touch it
- Update safety: Admin > Translations stores only changed strings in `custom/lang/<lang>.yaml` instead of rewriting the theme's language files, with a per-string reset
- Backups: `custom/` is a restore area ("Site customizations") replacing "Theme translations"; restoring an area that did not exist before can now be rolled back cleanly
- Docs: `docs/theming.md` describes the theme contract and the compatibility rules for theme changes

## 2026-09-28
- Remote backups: S3-compatible upload (streamed, not loaded into memory), connection test, and retention pruning limited to FarosCMS backup archives
- A failed remote upload no longer marks the local snapshot as failed; scheduled backups advance and the run is recorded as a warning
- Theme settings are edited through a form (palette, font, hero layouts, home sections, footer) that matches the default theme options
- Site/theme settings live in SQLite; legacy `content/settings/*.yaml` files are still imported once on upgrade
- Development-only folders (`_reference/`, `node_modules/`) are excluded from full snapshots
- Security: CSRF protection on all admin forms, POST-only sign-out, hardened session cookies with id rotation, and immediate effect of user deactivation/role changes
- Security: failed sign-in throttling, masked settings secrets, upload type allowlist with SVG script checks, and security response headers
- Security: admin banner and notification while the shipped default password is still in use
- Backups: every archive carries a SHA-256 manifest and a consistent `VACUUM INTO` database copy; new Verify action
- Backups: superadmin restore of content, uploads, translations, and the system database with verification, mandatory safety snapshot, staged swap, and automatic rollback
- Updates: verified pre-update backup action and preflight gate; safe update design documented in `docs/update-workflow.md`
- Forms: standalone `/admin/forms` module with submission counts, shortcode copy, language versions, filters, and sorting
- Forms: submissions browser with search, language/date filters, pagination, detail panel, reply-by-email, and single/bulk delete
- Admin UI: shared macros (`partials/ui.twig`), one toast placement with auto-hide, Filters buttons with an active dot, sortable tables, confirmation modal instead of `confirm()`, and consistent status badges
- Admin CSS: Tailwind is compiled locally (`npm run build:css`) instead of loading the runtime CDN
- Removed dead code: unused legacy file-upload, menu-translation, and YAML helper methods, the unreachable `files.twig`, and two unreferenced stylesheets
- System: `/admin/system` page with health checks, scheduled tasks, PHP extensions, environment, and content index status/rebuild
- Admin search across all content via the SQLite content index (header search box)
- Email: SMTP/SES sending moved to `Mailer`; SMTP now checks the server's answer after DATA, MIME-encodes non-ASCII subjects and sender names, strips CR/LF from headers, and uses socket timeouts; SES without cURL no longer reports HTTP errors as success
- Content list: search and status filters, language pills, and bulk publish/draft/delete (the home page stays protected)
- Fix: CSV export/import no longer emits PHP 8.4+ `fputcsv`/`fgetcsv` deprecation output into the file
- Updates: remote status is cached in SQLite and refreshed every 12 hours; a notification appears once per new release, and the sidebar version badge and dashboard show when an update is available

## 2026-03-01
- Media/files consolidation completed:
  - `Files` is now unified into `Media Library` (single source of truth)
  - `/admin/files` now redirects to `/admin/media?type=document&view=list`
  - sidebar `Files` entry removed to avoid duplicate management flows
- Media indexing expanded to include legacy `/uploads/files` assets in the unified media registry
- Media admin actions aligned with UOP icon style (save tags, open/view, copy URL, delete, file placeholder icon)
- Media list/thumb controls aligned with UOP behavior:
  - explicit `Filters` toggle button
  - `List` / `Thumbnails` state buttons
  - bulk actions panel appears only when at least one image is selected
- Media thumbs card overflow/layout fixes applied (`min-w-0`, constrained preview/tag row)
- Content editor `Main image` tab upgraded to UOP-like workflow:
  - open in-tab media picker with existing library images
  - choose image to set `main_image` URL directly
  - upload new image from edit screen (`main_image_upload`) and auto-assign
  - clear image action + live preview sync
  - edit form switched to multipart submit to support direct media upload
- Admin shell navigation refreshed:
  - left sidebar switched to fixed-position UOP-like dark navigation style
  - left sidebar bottom actions remain pinned and no longer move with page content scroll
  - sidebar typography compacted and site tagline removed from header block
- Added fixed right utility rail (white background) with icon-only actions:
  - `Translations`, `Settings`, `Logout`
  - replaced temporary off-canvas quick-actions drawer with persistent rail

## 2026-02-14
- Default theme structure simplified to a clean CSS/Twig baseline for faster iteration
- Desktop navigation rebuilt and stabilized:
  - multi-level dropdown behavior with delayed hide
  - active link + active trail state support
  - right-aligned nav with search icon, language switcher, and mode toggle
- Mobile navigation implemented for widths below `780px`:
  - hamburger trigger in header
  - left off-canvas drawer (`~300px`) with smooth slide animation
  - close button, overlay close, ESC close, and auto-close on route change
  - accordion support for nested menu levels
  - mobile social icon section
- Header sticky behavior added:
  - appears after scroll threshold (`200px`)
  - smooth slide-down reveal
  - layout spacer handling to avoid content jump
- Footer refined:
  - footer social icons fixed and decoupled from off-canvas icon classes
  - full-width outer footer shell + constrained inner footer container
  - optional footer background color/image support via theme settings
- Hero/content layout standardized across theme:
  - full-width hero + constrained inner container for all `single-*`
  - same hero structure applied to archives, search, and 404
  - search input moved into hero area; results-only body section
- Added selectable hero variants for single templates via theme settings:
  - `default` (existing hero behavior)
  - `centered` (editorial-style centered heading/meta with media block)
  - per-content-type assignment (`pages`, `posts`, `projects`, `forms`) through `theme_settings.hero_layouts`
- Centered hero refinements:
  - removed divider line and top body gap under centered hero
  - matched image centering and spacing behavior
- Typography/spacing tuning:
  - increased `.single-body` reading size/line-height
  - removed custom `.prose-block` grid gap to rely on natural element flow
  - adjusted hero/body paddings based on visual feedback

## 2026-02-09
- Taxonomies moved to dedicated admin section (`/admin/taxonomies`) with centralized term management
- Content editor now has a separate `Taxonomies` tab with checkbox term selection (no free-text tags/categories)
- Taxonomy model now uses stable term IDs + localized labels/slugs for translation-safe archives
- Taxonomy rendering updated in theme (`taxonomy_url`, `taxonomy_label`) for translated term output
- Added full bilingual starter content set (EL/EN): core pages, service pages, legal pages, posts, and projects
- Main and footer menus updated to production-style starter structure
- Navigation changed from `Insights` to `News` category archive (`/category/news`)
- Added bilingual `contact` form content type with localized fields, submit labels, success messages, and notifications
- Embedded contact form in both contact pages using shortcode (`[form slug=\"contact\"]`)
- Menus refactored to single-file-per-menu definitions (removed language-split menu files)
- Menu editor refactored to one unified screen with inline multilingual labels per item (`label_key` + `Label EL/EN`)
- Menu namespace convention applied: `nav.main.*` for main navigation and `nav.footer.*` for footer navigation
- Admin Translations screen now hides all `nav.*` keys and preserves them on save to avoid accidental overwrite
- Theme header fallback keys updated to `nav.main.*`
- Menu labels now managed directly in Menu edit (per-language columns) instead of through Admin Translations
- Contact form shortcode rendering hardened: HTML-entity-safe shortcode parsing + language fallback when resolving forms
- Added `Settings -> Backup` tab with local snapshot management (create now, retention, download list)
- Added automatic local backup scheduling (`daily` / `weekly` / `monthly`) with last-run tracking
- Backup snapshots now stored in `storage/backups` as full-site zip archives with exclusions for runtime/system paths
- Removed Google Drive/rclone backup integration for now (including UI/actions and snapshot upload button)
- Backup UX cleanup: checkbox is the single enable/disable control; schedule dropdown no longer includes `Disabled`

## 2026-02-08
- Forms system: form builder UI, form rendering, and submissions storage
- Admin submissions tab with CSV export (filename includes site + form title)
- Notifications + auto-reply (optional submission copy)
- SMTP/AWS SES email drivers + SMTP settings tab with test email
- Settings UI reorganized into tabs (Basics/Menus/APIs/SMTP/Advanced)
- Forms: translatable validation/rate-limit error messages via theme language files
- Forms: per-form submit button label (`submit_label`) with fallback to translated `form.submit`
- Forms: per-form success message kept as unique form-level setting
- Content CSV export for all content types except forms (all languages, site/type/lang metadata)
- Content CSV import flow added (with import backups + conflict handling)
- Menus moved to dedicated admin section + settings now keep menu location mapping only
- Menus translation model aligned with content (`translation_id` linking + create/edit translation flow)
- Menus now support nested navigation up to 3 levels (admin + frontend rendering)
- Default main menu includes sample nested items (2nd/3rd level) for review
- Menus list now shows translation language availability in `Lang` column (muted indicators)
- Menu edit actions aligned with content editor layout (save/delete at top-right)
- Theme menus: active link highlighting + active parent trail for nested navigation
- Theme menus: state classes (`is-active`, `is-trail`) and top-level trail styling
- Theme menus: generic recursive macro for rendering any mapped menu location
- Admin content type detection hardened to ignore hidden/system content folders

## 2026-02-07
- Files manager (admin) with uploads to `/public/uploads/files`
- Images now stored in `/public/uploads/images` with migration + content URL rewrite
- File/media URL copy-to-clipboard UX (inline toast + focus styling)
- Media UI: delete link in metadata and smaller preview
- Markdown toolbar for editor (headings, formatting, lists, code, image, snippets)
- Added default 404 template + styling

## 2026-02-06
- Navigation builder (menus in settings + dynamic header)
- SEO helpers (dynamic `sitemap.xml`, `robots.txt`, canonical URLs)
- SEO: Open Graph fields + output, `seo.noindex` with admin toggle, and `hreflang` alternates
- Admin UX: clean defaults for new content (no SEO placeholders)
- Admin UX: translations editor for theme strings
- Admin UX: delete content (with home page protection)
- Admin UX: sidebar content types + active highlighting
- Admin UX: settings form inputs + Advanced YAML toggle + language defaults
- Admin UX: edit screen tabs (Basics/Media/SEO/Custom/Translations/Advanced)
- Admin UX: non-index pill + main-image indicator in lists (with preview tooltip)
- Admin UX: translations tab + translation_id linking (slug fallback)
- Admin UX: table-based list layout + language indicators
- Media uploads + asset manager (admin)
- Main image field for all content types
- Tag/category archives + date format setting
- Taxonomy archive template hierarchy (archive-tag/category)
- Custom fields UI (namespaced `custom_fields`)
- Excerpt field (replaces summary)
- Language defaults switched to Greek; content files realigned
