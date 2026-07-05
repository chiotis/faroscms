# FarosCMS Migration Plan

FarosCMS will start from the existing PicolinoCMS codebase and keep its working flat-file CMS behavior, while replacing the old admin interface with the new prepared admin templates.

The migration should stay incremental. The first goal is a running FarosCMS baseline with the same behavior as PicolinoCMS. Visual/admin changes should then be applied module by module, with each step keeping the underlying content, menu, taxonomy, media, forms, auth, and frontend behavior intact.

## Baseline Sources

### Existing CMS Reference

Path: `_reference/picolinocms-old`

Useful pieces to preserve:

- `public/index.php` single entry point.
- `src/App.php` routing, frontend rendering, admin actions, forms, menus, taxonomies, media, backups, and exports.
- `src/ContentRepository.php` flat-file content discovery and Markdown/YAML parsing.
- `src/Auth.php` file-based admin authentication.
- `content/` as the source of truth for pages, posts, projects, forms, menus, media metadata, taxonomies, settings, and users.
- `themes/default/` as the current frontend theme baseline.
- `admin/templates/` as the behavior reference for existing admin screens.

Important note: PicolinoCMS currently uses Twig, Symfony YAML, and League CommonMark through `vendor/`. FarosCMS can begin from that same working dependency model, then we can decide later whether to vendorize, reduce, or replace dependencies.

### New Admin Template Reference

Path: `_reference/admin interface templates`

Useful pieces to adapt:

- `templates/layout.html` for the new admin shell.
- `templates/login.html` for authentication.
- `templates/dashboard.html` for the admin landing screen.
- `templates/content-list.html` and `templates/content-edit.html` for content management.
- `templates/forms-list.html` and `templates/form-edit.html` for forms.
- `templates/menus-list.html` and `templates/menu-edit.html` for menus.
- `templates/taxonomies-list.html` and `templates/taxonomy-edit.html` for taxonomies.
- `templates/files.html` for media/file management.
- `templates/settings.html` for site/system settings.
- `templates/translations.html` for language workflows.
- `templates/backups.html`, `templates/updates.html`, `templates/activity-logs.html`, and `templates/email-logs.html` for system modules.
- `public/assets/css/app.css` and `public/assets/js/admin.js` as the admin asset baseline.

## Target Structure

The target should follow the phpFlat-style separation:

```text
project-root/
├── public/
│   ├── index.php
│   └── assets/
├── content/
├── system/
│   ├── Core/
│   ├── Models/
│   ├── Views/
│   └── config.php
├── storage/
└── docs/
```

Because the existing CMS is already functional, the first implementation pass can keep a smaller Picolino-like structure while renaming and stabilizing it:

```text
public/
content/
src/              temporary compatibility layer
admin/templates/ temporary admin Twig layer
themes/
storage/
vendor/
```

After FarosCMS is running, we can move toward `system/` in a controlled refactor.

## Naming Rules

- Product name: `FarosCMS`
- Namespace target: `FarosCMS`
- Old names such as `PicolinoCMS`, `FlatCMS`, and `phpFlat` should remain only in reference files or historical notes.
- Admin template branding should change from `phpFlat`/`pF` to `FarosCMS`.

## Admin Mapping

| PicolinoCMS behavior reference | FarosCMS admin template target | Notes |
| --- | --- | --- |
| `admin/templates/base.twig` | `layout.html` converted to admin layout Twig | Replace shell first; preserve URL helpers, auth links, active section logic. |
| `admin/templates/login.twig` | `login.html` converted to Twig | Keep `Auth::attempt()` behavior. |
| `admin/templates/list.twig` | `content-list.html` converted to Twig | Must render real content types, languages, statuses, translation badges, edit/delete/preview links. |
| `admin/templates/edit.twig` | `content-edit.html` converted to Twig | Highest-risk screen; migrate after list/login/layout are stable. |
| `admin/templates/media.twig`, `files.twig` | `files.html` converted to Twig | Preserve upload, metadata, media kind filters, and direct URLs. |
| `admin/templates/menus-list.twig`, `menus-new.twig`, `menus.twig` | `menus-list.html`, `menu-edit.html` converted to Twig | Preserve nested menu items and menu location mapping. |
| `admin/templates/taxonomies.twig` | `taxonomies-list.html`, `taxonomy-edit.html` converted to Twig | Preserve taxonomy YAML shape. |
| `admin/templates/settings.twig` | `settings.html` converted to Twig | Keep existing settings schema first; reorganize UI later. |
| `admin/templates/translations.twig` | `translations.html` converted to Twig | Preserve translation IDs and language matrix behavior. |
| backup/update handlers in `App.php` | `backups.html`, `updates.html` | Some UI exists before all backend actions are complete. Mark gaps explicitly. |

## Migration Phases

### Phase 1: Bootstrap FarosCMS

Goal: Create a running FarosCMS baseline from PicolinoCMS.

Tasks:

- Copy the runtime CMS folders from `_reference/picolinocms-old` into the project root.
- Rename product-facing text to `FarosCMS`.
- Decide whether the first namespace pass changes `FlatCMS` to `FarosCMS` immediately or keeps a compatibility namespace for the first commit.
- Keep the existing frontend theme and content sample intact.
- Run PHP lint checks on copied PHP files.
- Start a local PHP server and verify `/`, `/admin/login`, and at least one content route.

Acceptance:

- The app loads from `public/index.php`.
- Frontend routes still work.
- Admin login still works with the existing reference credentials until we replace defaults.
- No admin template redesign yet beyond minimal branding cleanup.

### Phase 2: New Admin Shell

Goal: Convert the new admin shell into the real admin layout.

Tasks:

- Convert `layout.html` into `admin/templates/base.twig`.
- Replace static links with real `url()` calls.
- Replace static user data with `auth->user()` data.
- Replace `phpFlat` branding with `FarosCMS`.
- Move/use admin CSS and JS from the new template asset folder.
- Keep old admin page blocks rendering inside the new layout.

Acceptance:

- `/admin` uses the new shell.
- Existing admin list/edit/settings pages still load.
- Mobile sidebar, dropdowns, and toast host still work.

### Phase 3: Content List

Goal: Replace the old content list with the new table UI.

Tasks:

- Convert `content-list.html` to `admin/templates/list.twig`.
- Render real `$items`, `$types`, `$languages`, `$translation_langs`, `$current_type`, and `$lang`.
- Wire actions to existing edit/delete/preview routes.
- Preserve type and language filtering.

Acceptance:

- Existing content appears in the new table.
- Editing an item from the table opens the existing edit flow.
- Deleting still uses the existing route and protections.

### Phase 4: Login And Dashboard

Goal: Make admin entry feel like FarosCMS without changing auth semantics.

Tasks:

- Convert `login.html` to Twig.
- Add a real dashboard route before falling back to content list.
- Populate dashboard counts from content, media, forms, and system state where available.

Acceptance:

- Failed login displays errors in the new UI.
- Successful login lands in the FarosCMS admin.
- Dashboard does not invent unavailable backend data.

### Phase 5: High-Value Admin Modules

Recommended order:

1. Menus.
2. Taxonomies.
3. Files/media.
4. Settings.
5. Translations.
6. Forms.
7. Backups.
8. Updates.
9. Users.
10. Logs.

Forms, backups, updates, users, and logs need extra care because the template set has screens that are more ambitious than the current PicolinoCMS backend. Do not imply functionality exists until it is wired.

### Phase 6: Frontend Evolution

Goal: Develop the FarosCMS frontend/theme layer after the admin baseline is stable.

Tasks:

- Keep `themes/default` as the compatibility theme.
- Define a cleaner FarosCMS theme contract.
- Improve template hierarchy and block/section patterns.
- Improve SEO and multilingual rendering without breaking existing content.
- Add frontend starter templates only after the admin migration is stable.

## Risk Notes

- The content editor is the riskiest admin screen because it handles front matter, body content, SEO, custom fields, translations, forms, taxonomies, media picking, and file writes.
- The new admin templates include UI for modules that may be incomplete or absent in PicolinoCMS. Those must be marked as placeholder or implemented deliberately.
- Do not move from Twig to plain PHP templates during the first migration. Twig is already part of the working PicolinoCMS behavior and is the safest bridge for adapting the static HTML templates.
- Do not reorganize into `system/` at the same time as the admin redesign. First preserve behavior, then refactor structure.

## First Implementation Commit

Suggested commit:

```text
Bootstrap FarosCMS from PicolinoCMS reference
```

Scope:

- Copy PicolinoCMS runtime into the project root.
- Rename visible CMS branding to FarosCMS.
- Keep the old admin templates working.
- Do not yet integrate the new admin template screens.

After that commit, the next focused commit should be:

```text
Apply FarosCMS admin shell
```

Scope:

- Convert the new `layout.html` shell into the live admin base template.
- Keep existing admin page content blocks.
