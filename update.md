# FarosCMS Update Guide (Public Website)

This document explains a safe update process when new code is available in the main repository.

## Update Source

- Repository: `chiotis/faroscms`
- Branch: `main`
- Version source: `https://raw.githubusercontent.com/chiotis/faroscms/main/VERSION`
- Changelog source: `https://raw.githubusercontent.com/chiotis/faroscms/main/CHANGELOG.md`
- Private repositories require a GitHub token in Admin `Settings -> APIs`.

## 1) Before You Update

- Ensure PHP version is compatible (`>= 8.1`).
- Ensure the web root still points to `public/`.
- Make a full backup **before every update**.

### Mandatory backup (recommended order)

1. In Admin: `Settings -> Backup -> Create Snapshot Now`.
2. Also keep a filesystem backup of:
   - `content/`
   - `public/uploads/`
   - `storage/`
   - `.env` (if used in your server setup)

## 2) What Contains Live Site Data

These paths usually contain live data and must be protected:

- `content/` (pages, posts, settings, menus, users, form submissions, media metadata)
- `public/uploads/` (images/files)
- `storage/` (backups/cache/runtime artifacts)

If you do a clean deploy from ZIP, restore these paths from backup afterwards.

## 3) Recommended Update Method (Git)

Run on the production server, inside your project directory.

```bash
cd /path/to/faroscms

# Optional: inspect local changes first
git status

# Get latest from main repository
git fetch origin

# Update tracked branch safely
git pull --ff-only origin main

# Install/update PHP dependencies exactly as lock file defines
composer install --no-dev --prefer-dist --optimize-autoloader
```

If your production branch is not `main`, replace it accordingly.

## 4) Alternative Method (ZIP/FTP Deploy)

If you do not use git on the server:

1. Download latest release/archive from main repository.
2. Upload new code to server.
3. Do **not** overwrite live data paths unintentionally:
   - keep/restore `content/`
   - keep/restore `public/uploads/`
   - keep/restore `storage/`
4. Ensure `vendor/` is updated (or run `composer install` on server).

## 5) Post-Update Checklist

- Clear PHP OPcache / restart PHP-FPM if available.
- Verify permissions for writable paths:
  - `content/`
  - `public/uploads/`
  - `storage/`
- Open and check:
  - homepage
  - one page/post/project
  - `/admin`
  - `/admin/media`
  - `/sitemap.xml`
  - `/robots.txt`

## 6) Rollback Plan

If something breaks:

1. Restore latest backup snapshot from `storage/backups/`.
2. If using git, return to previous commit/tag and re-run Composer:

```bash
cd /path/to/faroscms
git log --oneline -n 10
# checkout previous stable commit/tag
# git checkout <commit-or-tag>
composer install --no-dev --prefer-dist --optimize-autoloader
```

3. Re-check frontend/admin routes.

## 7) Practical Recommendation

For production stability:

- Keep customizations in separate commits/branch.
- Update via staging first, then production.
- Treat updates as: **backup -> update -> verify -> keep snapshot**.
