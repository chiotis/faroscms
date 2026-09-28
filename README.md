# FarosCMS

FarosCMS is a flat-file PHP CMS with Markdown/YAML content, Twig themes, multilingual content, and an admin interface migration in progress.

See [docs/migration-plan.md](docs/migration-plan.md) for the current migration plan.

## Local Development

Run the app with PHP's built-in server:

```bash
php -S 127.0.0.1:8087 -t public public/index.php
```

Passing `public/index.php` as the router lets PHP serve generated files (responsive image variants, theme assets) the same way Apache and nginx do.

Then open:

- Frontend: `http://127.0.0.1:8087/`
- Admin: `http://127.0.0.1:8087/admin/login`

The admin CSS is a compiled Tailwind build committed to the repository (`public/assets/css/admin.build.css`), so servers need no Node.js. After changing Tailwind classes in `admin/templates/` or `public/assets/js/admin.js`, rebuild it:

```bash
npm install
npm run build:css
```

See [docs/architecture.md](docs/architecture.md) for the code map and [docs/theming.md](docs/theming.md) for the theme contract. Site-specific CSS, JS, template, and string overrides go in `custom/`, which updates never touch.

The imported development admin account is:

```text
Username: admin
Password: 1234
```

Change this before using FarosCMS outside local development. The admin shows a red banner while this password is still in use.

See [docs/security.md](docs/security.md) for the security model and the server operator checklist.
