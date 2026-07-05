# FarosCMS

FarosCMS is a flat-file PHP CMS with Markdown/YAML content, Twig themes, multilingual content, and an admin interface migration in progress.

See [docs/migration-plan.md](docs/migration-plan.md) for the current migration plan.

## Local Development

Run the app with PHP's built-in server:

```bash
php -S 127.0.0.1:8087 -t public
```

Then open:

- Frontend: `http://127.0.0.1:8087/`
- Admin: `http://127.0.0.1:8087/admin/login`

The imported development admin account is:

```text
Username: admin
Password: 1234
```

Change this before using FarosCMS outside local development.
