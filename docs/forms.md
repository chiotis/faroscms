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
