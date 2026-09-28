# FarosCMS Security Notes

This page lists the protections built into the admin and what the server operator still has to configure.

## Built In

### Sessions

- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when the request arrives over HTTPS (directly or via `X-Forwarded-Proto: https`).
- `session.use_strict_mode` is enabled, so unknown session ids are never adopted.
- The session id is regenerated on every sign-in and sign-out.
- Every admin request re-reads the signed-in user from SQLite. Deactivating a user or changing their role takes effect on their next click, not when their session expires.

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
     }
     ```
5. If a credential was ever committed to git (for example SMTP credentials in the old `content/settings/site.yaml`), rotate it with the provider. Deleting the file does not remove it from history.
