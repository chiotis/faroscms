# Forms

A form is a content file, `content/forms/<slug>.md`, with its settings in the front matter and nothing else that matters in the body (the body is the text above the form on its own page). It is shown on any page with the Form block or the shortcode `[form slug="contact"]`, and has a page of its own at `/forms/<slug>`. Submissions are kept as JSON files in `content/forms-submissions/<slug>/` and read in Admin > Forms.

## One form, many languages

A form is **built once, in the site's first language**. Its fields, the order of them, the choices and their values, what is required, the widths, the spam settings and the email settings (who gets the mail, the reply-to field, whether an automatic reply is sent) live only there, so the languages cannot drift apart.

Every other language is a set of **translations stored in the same file**. There is no `contact.en.md`: asking for a form in another language opens the one builder with that language chosen.

```yaml
title: Επικοινωνία
submit_label: Αποστολή
success_message: Ευχαριστούμε
notifications: {enabled: true, to: owner@example.com, subject: Νέο μήνυμα, auto_reply: true, auto_reply_subject: Λάβαμε το μήνυμά σας, auto_reply_message: Θα απαντήσουμε σύντομα.}
fields:
  - type: text
    name: name
    label: Όνομα
    placeholder: Γράψε το όνομά σου
    translations:                 # per field: label, placeholder, help, options
      en: {label: Name, placeholder: Your name}
  - type: select
    name: topic
    label: Θέμα
    options: ['sales|Πωλήσεις', 'help|Βοήθεια']
    translations:
      en:
        label: Topic
        options: {sales: Sales, help: Help}     # keyed by the value of the choice; the values never change
translations:                     # per form: the texts of the form as a whole
  en:
    title: Contact
    excerpt: ''
    body: 'Text above the form (Markdown)'
    submit_label: Send
    success_message: Thank you
    notifications: {subject: New message, auto_reply_subject: We got it, auto_reply_message: We will answer soon.}
aliases: {contact-en: en}         # old addresses of copies that had one (see Migration)
```

What can be translated: for a field its `label` (the text of a heading or a paragraph), `placeholder`, `help` and the labels of its choices; for the form its `title`, `excerpt`, `body`, `submit_label`, `success_message` and the notification `subject`, `auto_reply_subject` and `auto_reply_message`. Only the texts the first language has filled in are counted. Only languages the site has are kept.

**A text with no translation is shown in the first language** and counted as missing. In the builder the field carries a badge ("2 texts to translate") and the language's chip shows the count; the forms list shows a count for each language.

### In the builder

A chip for each language sits above the tabs (first language: *built here*). With another language chosen:

- the form is drawn in that language, and the inspector of the selected field shows boxes for its texts with the first language's text under each;
- the structure (adding, removing, moving, the type, the choices, the width) cannot change, and everything that is not a text (the recipient, the redirect, spam protection, Submissions, History, Advanced) is hidden;
- *Settings* has the button and the thank-you message, *Email* the subject and the automatic reply, *Page* the text above the form, and the form's name is the box at the top. A box left empty shows the first language's text as its placeholder.

### What a visitor gets

The form shows in the language of the page it is on (its own page, a page with the shortcode, or a Form block), with the first language's text wherever a translation is missing. A submission records the language it was shown in (`lang` in the JSON; Admin > Forms > Submissions can filter by it and the CSV has the column), and the notification and the automatic reply are written in that language. Field names and the stored answers do not depend on the language, so the answers of all languages sit together.

## Migration of older per-language copies

Earlier versions kept a translation of a form as a form of its own (`contact.md` and `contact.en.md`, or a copy with another address sharing the `translation_id`). The first time the site is opened after the update (and any time with `php scripts/migrate-form-translations.php --default-lang=el --langs=el,en`) each such copy is folded into the form of the first language:

- fields are matched **by name**; choices by value. The copy's label, placeholder, help and choice labels become the field's translations, and its title, button, messages, text above the form and email texts the form's;
- a text whose field (or choice) has no match is **kept in a report**, not dropped; Admin > Forms shows it with a button to dismiss it. Settings that differ between the copy and the form (the recipient, Cc, the redirect, ...) are reported too, because the form has one setting for every language;
- a copy with an address of its own leaves that address as an alias of the form: the old page redirects, and a Form block or shortcode that used it still shows the form (in the language of the page); stored submissions filed under it move to the form and keep their language;
- the copies are moved to `storage/form-translation/copies`, never deleted, and only after the form was written; a translation the form already has is never overwritten by a copy;
- a form that exists only in a language that is not the first, with no first-language form to fold into, is left as it is (and reported);
- it can be run again: it only touches copies that are still there.

## File upload fields

A field of type `file` lets a visitor attach files. Everything about it is set in the builder (palette group *Files*) and stored with the field:

```yaml
- type: file
  name: cv
  label: CV
  required: true
  help: Your latest CV.
  button: Choose a file                 # the text of the button (translatable)
  error_size: '"{name}" is too big (max {max}).'   # optional wording of the messages (translatable)
  upload:
    multiple: false                     # one file, or several
    min: 0                              # several only: at least / at most this many (at most 20)
    max: 5
    max_size_mb: 5                      # one file; default 10
    total_mb: 20                        # all files together; none by default
    extensions: [pdf, docx, jpg, png]   # chips for images, PDF, documents, archives, plus any others
    rename: "cv-{field:surname}-{field:name}-{date}"
  translations:
    en: {label: Resume, help: Your latest resume., button: Choose a file}
```

**Limits.** What really applies is the smaller of the form's choice, the server's `upload_max_filesize` and `post_max_size` and the site's upload limit (Settings > Limits). The builder shows the effective limit and says when the server caps what was asked for. A site whose storage limit is full refuses new files (the visitor is told to contact the owner) while everything else keeps working.

**Kinds.** Only extensions in the field's list are accepted (when it names none: images, PDF, documents and archives). The real content is checked with `finfo` against the extension, so a PDF renamed `.jpg` or text renamed `.pdf` is refused; the browser's `Content-Type` is never trusted. Programs, scripts, web pages and SVG (`php`, `phtml`, `phar`, `cgi`, `sh`, `js`, `html`, `svg`, `htaccess`, and the rest of `FormUploads::BLOCKED`) are refused whatever a form says, in any part of the name (`shell.php.pdf`) and by content (HTML, PHP, shell, executables).

**Names.** The stored name comes from the `rename` pattern: text mixed with `{field:name}` (the answer of any other field, e.g. `{field:surname}`), `{form}`, `{date}` or `{date:Y-m-d}`, `{time}`, `{submission}`, `{index}` (the number of the file in the field), `{original}` (the original name without its extension), `{random}` and `{lang}`. It goes through the same Greek-to-Latin address rules as page addresses (`Παπαδόπουλος` becomes `papadopoulos`), the extension is always the real, checked one, a name taken in the same submission gets `-2`, `-3`, an empty result falls back to the original name (then `file`), and the name is cut at 80 characters. The builder's inspector shows a live preview with sample values. The visitor's original name is kept only as text in the submission, never used as a file name.

**The visitor's side.** Without JavaScript the browser's own file control is used (the form is `multipart/form-data`). With it the field becomes a drop zone with a *choose files* button, the list of chosen files (name, size) with a button to take one out, and the same checks the server makes, told at once and announced to screen readers. The server checks everything again. A form that comes back with a mistake cannot keep the files (a browser does not allow it), so it says they have to be chosen again. The picker's CSS and script are loaded only by a page that has such a form. The honeypot and the wait between sends work as before, and nothing is written to disk until the rest of the submission is valid (temporary files are removed when it is not). A request larger than `post_max_size` is thrown away by PHP; the form then says the files are too large to be sent together.

**Where the files are kept.** In `storage/form-uploads/<form>/<submission>/`, outside the web root, with an `.htaccess` that denies everything (`docs/security.md` has the nginx rule). The submission's JSON lists them under `files` (stored name, original name, size, type) and the field's answer is the list of stored names. A form with a file field always keeps its submissions, whatever `store_submissions` says, because the files are reached from them. Files are kept **forever** and deleted only with their submission (single or bulk delete; the confirmation says how many files go with it). They are part of the site's storage: counted on the storage screen and the dashboard (Settings > Limits shows them as *files sent through forms*) and included in backups; a restore has its own area, *Form files*.

**In the admin.** Submissions shows a paperclip with the number of files in the list, each file with its size and a download link in the details, *Download all as ZIP* for a submission, a filter *Only with files*, and the CSV has the file names. A file is only given out by `/admin/form-file?slug=…&id=…&f=…` (and `/admin/form-files-zip`) to a signed-in user who may manage forms, as an attachment with `X-Content-Type-Options: nosniff`; each download is written to the activity log.

**Email.** The notification always has an admin link for each file (and one for all of them). *Email > Also attach the files to the email* attaches them when they are together no larger than the size set there (10 MB by default); above it only the links are sent and the email says so. The visitor's automatic reply never carries files or admin links; with *Add a copy of their answers* it lists the names.
