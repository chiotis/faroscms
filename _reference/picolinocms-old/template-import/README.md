# HTML Template Import Workspace

This folder is the new clean workflow for building a CMS theme from a ready-made HTML template.

## What To Upload Here

Use only one demo/style first.

1. Put selected HTML template files in:
`template-import/source/templates/`

2. Put all required assets (css/js/fonts/images) in:
`template-import/source/assets/`

3. Put documentation (html/pdf/txt) in:
`template-import/source/docs/`

4. Fill:
`template-import/template-map.yaml`

## First Pass Scope (Recommended)

Map only these 7 template slots first:

- `home`
- `single_page`
- `single_post`
- `single_project`
- `archive_general`
- `archive_posts`
- `archive_projects`

Then we implement and verify end-to-end before adding more.

## Important Notes

- Keep original relative paths from HTML to assets.
- Do not upload all demos at once.
- Do not modify files from `themes/default`.

## Output

Generated/implemented theme work will be prepared under:
`template-import/output/`

