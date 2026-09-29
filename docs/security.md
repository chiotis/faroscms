# FarosCMS Security Notes

This page lists the protections built into the admin and what the server operator still has to configure.

## Built In

### Sessions

- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when the request arrives over HTTPS (directly or via `X-Forwarded-Proto: https`).
- `session.use_strict_mode` is enabled, so unknown session ids are never adopted.
- The session id is regenerated on every sign-in and sign-out.
- Every admin request re-reads the signed-in user from SQLite. Deactivating a user or changing their role takes effect on their next click, not when their session expires.

### Roles and permissions

Roles are defined in `PermissionService` (the super admin can adjust them, see below). Each admin action needs a capability, and an action nobody mapped is for administrators only, so a new route is never open to a lower role by accident.

| | Super admin | Admin | Editor | Basic user |
|---|:-:|:-:|:-:|:-:|
| Dashboard (content figures) | yes | yes | yes | no |
| Pages, posts, projects: write, publish, delete | yes | yes | yes | no |
| Media, categories and tags | yes | yes | yes | no |
| Raw HTML in content | yes | yes | no | no |
| Forms and their submissions, menus | yes | yes | no | no |
| Settings, content types, translations, system | yes | yes | no | no |
| Activity and email logs, notifications | yes | yes | no | no |
| Backups, updates, import, export | yes | yes | no | no |
| Users and roles | yes | no | no | no |
| Own profile and password | yes | yes | yes | yes |

An editor's dashboard shows only content: the user count, backups, system checks, storage, logs, and notifications are not sent to them. Forms are out of their reach entirely (they hold visitors' personal data and decide where notifications go), including by address, bulk actions, and search. Every refused request is written to the activity log.

**Changing what a role can do.** The table shows the built-in permissions. The super admin can change them for Admin, Editor, and Basic user at **Admin > Roles**; nobody else can open that screen, and it cannot be delegated. The super admin's own permissions are fixed, so nobody can be locked out, and three are never switchable: managing users, managing roles, and restoring backups (each would let its holder gain everything else). Signing in and editing one's own profile stay on for every role.

- Only differences from the built-in set are stored (`system_meta`, key `role_permissions`); a role left alone follows future defaults. Unknown or unreadable stored values are ignored, never turned into access.
- Each permission is labelled Normal, Sensitive (personal data, bulk data, logs, backups, import) or Critical (raw HTML, settings including sign-in and email, updates). Turning on a Sensitive or Critical one asks for confirmation.
- Every change is written to the activity log as a warning with the capabilities added and removed.
- Settings is Critical because it includes Google sign-in and email, which decide who can get into the site. Raw HTML is Critical because it can script the public site.
- Importing content and saving content follow the same raw HTML rule, so granting import does not bypass it.

After adding or changing an admin action, run `php scripts/check-permissions.php`: it lists every action with the roles that may use it and fails when the rules drift.

**Raw HTML.** Raw HTML in Markdown can carry script, and an editor must not be able to script the public site. When someone without the `content.raw_html` capability saves, any HTML they type in the text or in a block's Markdown fields is shown as plain text and the editor is told so. HTML that is already in that content (put there by an administrator, for example an embed) is left alone, so an editor's edit never breaks it. `[text](javascript:…)` style links are neutralised for everybody. Uploads are checked separately (SVG files with scripts are refused).

### Links and deleting

Updating the links that still use an old address needs `content.manage` (it edits content) and only offers permanent redirects that are on. It changes nothing but the address in the text, files of types the person cannot edit (forms without `forms.manage`) are skipped, and every changed file gets a history entry ("Links updated") holding the earlier text. When a public entry is deleted, choosing where visitors go needs `redirects.manage`; without it the choice is ignored, so a permission that lets someone send visitors to another website cannot be used through the delete form. The target is checked like any redirect (no scripts, no loops), and an address on this site must exist. Deleting keeps the text in the history for 180 days, for bulk deletes too.

### Redirects

Redirects are managed with `redirects.manage` (Sensitive: a redirect can send visitors to another website). A target must be a path on this site or an `http(s)` address; `javascript:`, `data:` and other schemes are refused, as are loops and redirects from `/admin`. The list of addresses that were not found keeps only the path and the referring host and path (no query strings, no IP addresses), ignores files and probes, and is capped at 1000 rows. Address changes made by an editor still create their redirect, because that is a side effect of saving, not a redirect management action.

### History

Admin > History and the editor's History tab need `content.manage`. Restoring or bringing back an item is a `POST` with the CSRF token, is written to the activity log, and goes through the raw HTML rule: someone without `content.raw_html` gets HTML that is not already in the current file shown as text, so an old version cannot be used to put script back. Forms are excluded from lists and refused by address unless the person may manage forms, because a form's history includes where its notifications go. The history holds the full text of content, so it is protected like the content itself and travels in database backups; it keeps the latest 50 versions of an item and deleted items for 180 days.

### CSRF

- Every admin `POST` must carry the per-session token (`_csrf` field or `X-CSRF-Token` header). This includes the sign-in form.
- Templates add the field with `{{ csrf_field() }}`. `public/assets/js/admin.js` also appends it to any `POST` form that lacks one at submit time, as a safety net for script-built forms.
- A rejected submission returns HTTP 419 with a "form expired" message and is written to the activity log as `security.csrf_rejected`. Uploads larger than PHP's `post_max_size` arrive without any fields and are rejected the same way.
- Sign-out is `POST` only. `GET /admin/logout` just redirects.

### Sign-in Throttling

- Failed password sign-ins are stored in the `login_attempts` SQLite table.
- 8 failures from one address, or 12 failures for one username, within 15 minutes block further attempts (HTTP 429 with `Retry-After`) until the oldest counted failure ages out.
- The address is `REMOTE_ADDR`. `X-Forwarded-For` is ignored for throttling because clients can forge it. Behind a reverse proxy all visitors share the proxy address, so configure the proxy to pass the real client address as `REMOTE_ADDR` (for example `mod_remoteip` or nginx `real_ip`).

### Default Credentials

- `content/users/users.yaml` ships a development admin with a plain-text password. It is imported into SQLite only when the users table is empty.
- Signing in with that shipped password shows a red banner on every admin page and creates a `security.default_password` notification until the password is changed.
- New passwords must be at least 8 characters and cannot equal the shipped password.

### Settings Secrets

- SMTP password, SES secret, Google client secret, S3 secret key, and the GitHub update token are never rendered back into the Settings page.
- Leaving a secret field blank keeps the stored value. Tick "Remove the saved value" to clear it.
- Secrets are stored in `storage/db/app.sqlite`. Full and database backups contain that file, so treat backup archives (local and remote) as sensitive.

### Uploads

- The media library only accepts inert file types: images, office documents, PDF, text/CSV/Markdown, ZIP, and common audio/video formats. Anything else, including `.php`, is rejected.
- Raster images must be detected as `image/*`.
- SVG files are rejected if they contain scripts, event handlers, `javascript:` URLs, `foreignObject`, embedded frames, entity declarations, or external/data references.

### Response Headers

- Admin: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-store, private`.
- Frontend: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.

## Operator Checklist

1. Point the web root at `public/` only. `content/`, `storage/`, `src/`, and `vendor/` must never be reachable over HTTP.
2. Serve the site over HTTPS.
3. Change the shipped admin password immediately (the admin banner reminds you).
4. Make sure uploads can never execute:
   - Apache: `public/uploads/.htaccess` ships with the project and disables script handlers, denies script/HTML extensions, and adds a restrictive CSP to SVG responses. It requires `AllowOverride` to be enabled for that directory.
   - nginx: add an equivalent block, for example:

     ```nginx
     location ^~ /uploads/ {
         location ~* \.(php\d?|phtml|phar|pht|cgi|pl|py|sh|html?)$ { deny all; }
         location ~* \.svg$ { add_header Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; img-src data:"; }
         add_header X-Content-Type-Options nosniff;
         # Missing responsive image variants (/uploads/_v/…) are generated by index.php.
         try_files $uri /index.php?$query_string;
     }
     ```

     Any other static-file location (for example a `\.(css|js|webp)$` caching block) must also end in `try_files $uri /index.php?$query_string;`, because theme assets (`/_themes/…`, `/_custom/…`) are always served by PHP, and image variants are served by PHP until they exist as files.
5. If a credential was ever committed to git (for example SMTP credentials in the old `content/settings/site.yaml`), rotate it with the provider. Deleting the file does not remove it from history.
