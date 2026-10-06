# Working in the admin (a browser signed in to the site)

Use this when you drive a browser (ChatGPT agent, Claude in Chrome, computer use) or when you tell a person where to click. The admin is at `<site>/admin`. A site with no accounts asks for its **first administrator** on `/admin/login` (username, email, password of at least 8 characters; there is no default account): that is the owner's decision, never create the account yourself with a password you choose. Let the person sign in themselves; do not ask them to type a password into the chat.

What you can see depends on the person's role (superadmin, admin, editor, user). A screen you cannot open answers "Forbidden"; say so and ask the person to do it or to change the role.

## Screens

Left menu: **Content** (Pages, Posts, Projects… the types that are on, Media), **Forms**, **Menus**, **Theme**, **Taxonomies**, **Content types**, **SEO**, **Redirects**, **Translations**, **History**, then **Users & Roles**, **Analytics**, **Logs**, **Backups**, **Updates**, **Settings**. Every form ends in one fixed Save bar (it also shows the messages). Dashboard (`/admin`) lists what needs attention.

| Screen | Address | Use it for |
|---|---|---|
| Content list | `/admin/content?type=pages&lang=el` | search, filter by status, bulk publish / draft / delete |
| New entry | `/admin/new?type=pages&lang=el` | start a page, post or project |
| Edit entry | `/admin/edit?type=pages&slug=about&lang=el` | tabs: Content, (type) details, **Blocks**, Media, Taxonomies, Custom Fields, SEO, Translations, History, Advanced |
| Import / Export | `/admin/import?type=posts`, `/admin/export?type=posts` | CSV in and out (see `importing.md`) |
| Media | `/admin/media` | upload, alt text, tags, where a file is used |
| Menus | `/admin/menus` | two-panel menu editor, labels per language, which menu goes in header/footer |
| Forms | `/admin/forms` | form builder, submissions, CSV export |
| Theme | `/admin/theme?tab=header` | tabs per section, Branding, Single Layouts, Archive Layouts, Footer Blocks (live preview) |
| SEO | `/admin/seo?tab=search` | title format, social cards, robots, sitemap, structured data, checks of every entry |
| Redirects | `/admin/redirects` | add, import a list, addresses visitors missed |
| Translations | `/admin/translations` | the theme's words per language |
| Taxonomies | `/admin/taxonomies` | categories, tags, own taxonomies and their terms |
| Content types | `/admin/content-types` | switch types on, make a type, its fields |
| History | `/admin/revisions` | every saved version, compare, restore |
| Settings | `/admin/settings?tab=basics` | tabs basics (General), smtp, auth, backup, updates, apis, limits |
| Backups | `/admin/backups` | create, verify, download, restore |
| System | `/admin/system` | health checks, content index status |

## Make a page with blocks, in the browser

1. `/admin/new?type=pages&lang=<default>`: type the **Title** (the address is made from it; check it under the title and edit it if it is not right), choose **Status** `Draft` in the Publish card, and the **Template** if you want `Landing`.
2. Open the **Blocks** tab. *Add block* opens a picker with three tabs: **Blocks** (each with a description and a small drawing), **Ready-made sections**, and **Page layouts** (a whole page; *Replace* or *Add after*). Choosing one adds it with default values.
3. Each block is a card: drag or use the arrows to reorder; *Duplicate*, *Hide*, *Remove* (with undo). Open it to fill its fields. The **Design** tab of a block holds its layout drawings (`variant`), background and spacing. Repeaters (items, buttons) have *Add* and *Remove* per row. Image fields open the media library (*Library…*) or take an address.
4. The Content tab holds the Markdown body (visual or Markdown mode; the choice is remembered).
5. **Save** (bottom bar). Check the message. Use *View* to see the page; a signed-in person sees drafts.
6. For the translation: **Translations** tab, *Create* next to the language; it opens a new entry already linked (same `translation_id`) and with the same address.

Typing blocks as YAML into the **Advanced** tab (the raw front matter) does **not** work reliably: the Blocks tab's own state is sent with the form and replaces what the YAML says. Use the Blocks tab, a page layout, or the CSV import.

## Other routine jobs

- **Change the look**: Theme > the tab > change > Save. Check the live preview first. Branding colours accept `#rrggbb`.
- **Menus**: Menus > the menu > pick what the site has from the left panel, drag it into place, set each link's label for every language > Save. *Place* decides header/footer.
- **Upload a picture**: Media > Upload (drag files), then open the file to write its description (alt text) and tags.
- **SEO of one page**: the entry's SEO tab (title, description, canonical, share image, noindex) with a search-result preview and a character count.
- **Redirect after changing an address**: the editor offers it when a published address changes; or Redirects > Add / Import lines `old-path new-path`.
- **Publish many**: Content list > tick > Publish.
- **Undo a mistake**: History > the entry > compare > Restore; or Backups.

## Do not press, unless the person asked for exactly that

*Delete* (entries, media in use are protected, users), *Restore* in Backups, *Install* in Updates, *Reset* in Translations, Roles changes, Users, anything in Settings > Limits/Auth/Email that holds a secret. Make a backup first (*Backups > Create full backup*) before any bulk change.

## When something does not match this page

The admin is a form-driven PHP app; labels and tabs can move between versions (see the installation's `CHANGELOG.md`). Trust what you see over this list, keep to the same goals, and report what was different.
