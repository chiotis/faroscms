# FarosCMS Todo

This is the active working checklist for FarosCMS. Use this file first when deciding what to build next.

Reference docs:

- `docs/migration-plan.md` explains the original migration strategy.
- `docs/admin-gap-list.md` compares FarosCMS with the admin template and phpFlat baseline.
- `docs/system-database.md` documents the SQLite system layer.
- `docs/security.md` documents admin security behavior and the operator checklist.

## Current Status

FarosCMS is now a working flat-file CMS with a SQLite system layer.

Content remains file-based. SQLite is used for system/admin data, starting with users.

## Next Tasks

### 1. Permissions

- [x] Define role capabilities for `superadmin`, `admin`, and `user`.
- [x] Enforce module access in admin routes.
- [x] Protect user management so only `superadmin` can manage users.
- [x] Prevent unsafe self-lockout actions.
- [x] Add clear unauthorized/forbidden admin response.

### 2. Activity Logs

- [x] Add activity log writer around the existing `activity_logs` SQLite table.
- [x] Log login success/failure.
- [x] Log content create/update/delete.
- [x] Log media upload/delete/tag changes.
- [x] Log user create/update/deactivate.
- [x] Log settings changes.
- [x] Add `/admin/activity-logs` screen from the admin template.
- [x] Add filters by user, action, date, and level.

### 3. Email Logs

- [x] Persist email send attempts in `email_logs`.
- [x] Capture recipient, subject, provider, status, timestamp, and error message.
- [x] Add `/admin/email-logs` screen from the admin template.
- [x] Add clear logs action.
- [x] Connect failed email notifications.

### 4. Dashboard

- [x] Add real `/admin/dashboard` route or make `/admin` the dashboard.
- [x] Show real content counts.
- [x] Show users count.
- [x] Show backup status.
- [x] Show recent activity once logs exist.
- [x] Show recent email log attempts.
- [x] Show real storage usage in dashboard and admin shell.
- [x] Show system status from real checks only.

### 5. Notifications

- [x] Wire `notifications` SQLite table.
- [x] Add notification dropdown to the admin header.
- [x] Add mark-as-read and mark-all-read actions.
- [x] Derive notifications from failed emails, backups, and system checks.
- [x] Derive update notifications once the Updates module exists.

### 6. Security Hardening

See `docs/security.md`.

- [x] CSRF tokens on every admin POST, including sign-in.
- [x] Sign-out through POST only.
- [x] Session cookie flags, strict mode, and id regeneration on sign-in/sign-out.
- [x] Re-check user status and role on every admin request.
- [x] Throttle repeated failed sign-ins by address and username.
- [x] Never render stored secrets back into Settings.
- [x] Restrict media uploads to inert file types and reject scripted SVGs.
- [x] Security headers for admin and frontend responses.
- [x] Warn while the shipped default admin password is still in use.
- [ ] Rotate any credential that was ever committed to git (manual, provider side).

## Admin Modules Backlog

### Backups

- [x] Create dedicated `/admin/backups` page.
- [x] Keep schedule/retention settings in Settings.
- [x] Add backup history from SQLite `backup_runs`.
- [x] Add S3-compatible remote storage settings, including Backblaze B2.
- [x] Wire remote backup upload, connection test, and remote pruning.
- [ ] Add restore flow after careful safety review.
- [x] Add delete old snapshot action.

### Updates

- [x] Add `/admin/updates` page.
- [x] Add version display.
- [x] Add local `VERSION` file and GitHub raw `VERSION` source.
- [x] Add read-only update check first.
- [x] Add changelog display.
- [ ] Design backup-before-update flow before any install action.

### Forms

- [ ] Make Forms feel like a standalone admin module.
- [ ] Adapt closer to `forms-list.html`.
- [ ] Improve submission browsing.
- [ ] Keep export behavior.

### Files / Media

- [ ] Decide whether `/admin/files` should be a real route or keep redirecting to `/admin/media?type=document`.
- [ ] Add true Files navigation identity if needed.
- [ ] Keep current media library functionality.

## UI Consistency Backlog

- [ ] Normalize hidden filter panels across all list screens.
- [ ] Add sortable table controls where useful.
- [ ] Add bulk actions beyond media where safe.
- [ ] Normalize toast placement and behavior.
- [ ] Use modal/off-canvas patterns for details and confirmations.
- [x] Replace static storage indicator with real storage usage.
- [x] Replace `base` badge with real version/status.

## System Backlog

- [x] Add first-pass system health checks: PHP version, upload limit, memory limit, writable dirs, SQLite status, mail status, and disk free.
- [ ] Add deeper system health checks: cache/index status and scheduled tasks.
- [ ] Add content index rebuild command/action.
- [ ] Wire `content_index` for faster admin filtering/search.
- [ ] Add cache/status tooling if caching is introduced.
- [ ] Decide later whether to refactor from `src/` into stricter phpFlat `system/` structure.
- [ ] Decide later whether to remove Twig/Composer or keep them as FarosCMS architecture.
- [ ] Replace runtime Tailwind CDN with local compiled CSS.

## Done

- [x] Bootstrap FarosCMS from PicolinoCMS.
- [x] Rename visible CMS branding to FarosCMS.
- [x] Adapt main admin shell.
- [x] Adapt login screen.
- [x] Adapt content list.
- [x] Adapt content editor.
- [x] Adapt settings.
- [x] Adapt menus.
- [x] Adapt taxonomies.
- [x] Adapt translations.
- [x] Adapt media/files UI around existing functionality.
- [x] Add CSV import/export flows.
- [x] Add SQLite system database foundation.
- [x] Add SQLite users table.
- [x] Import existing YAML users into SQLite.
- [x] Switch login to SQLite users with YAML fallback.
- [x] Add `/admin/users`.
- [x] Add `/admin/users-edit`.
- [x] Add user create/edit/password/status/role basics.
- [x] Add Google Sign-In settings in Settings > Auth.
- [x] Add Google OAuth login/callback for existing active users with matching email.
