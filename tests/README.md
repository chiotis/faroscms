# Tests

One command runs everything:

```bash
tests/run.sh          # all checks (about 10 seconds)
tests/run.sh unit     # PHP checks only, no server
tests/run.sh http     # the browser-level tests
tests/run.sh redirects   # one HTTP test: editor, roles, import, redirects, save, history, taxonomies, links, delete
```

It needs PHP and Python 3 (standard library only). Nothing touches your site: the HTTP tests run against a temporary copy of the code on a free local port, seeded from `tests/fixtures/content`, and remove it afterwards. The exit code is non-zero when anything fails, so it can run in CI.

## What is covered

| Check | What it proves |
|---|---|
| `tests/unit/blocks.php` | Block definitions, defaults, tampered values, video links, storage sanitising |
| `tests/unit/revisions.php` | The line diff and the history store: capture, dedupe, outside changes, renames, pruning, deleted items |
| `tests/unit/taxonomies.php` | Term addresses from names, address changes, removals, the layout choices a taxonomy stores |
| `tests/unit/links.php` | Which links to an address are found and rewritten, and which are left alone |
| `tests/unit/content-types.php` | Content type definitions, merging with the site's file, values, ordering |
| `scripts/check-permissions.php` | Every admin action against every role, custom permissions, the capability catalogue |
| `scripts/check-slugs.php` | Greek to Latin addresses, the redirect store, loops, validation |
| `scripts/check-blocks.php` | Every block in the site's own content uses known fields and valid values |
| `tests/http/editor_test.py` | The editor role end to end: what it can reach, raw HTML, forms, dashboard |
| `tests/http/roles_test.py` | Admin > Roles: who can change permissions, tampering, resets, corrupt data |
| `tests/http/import_test.py` | CSV import follows the raw HTML rule |
| `tests/http/save_test.py` | Saving every editor field, compared with recorded files in `tests/fixtures/golden` (`UPDATE_GOLDEN=1 tests/run.sh save` accepts a deliberate change) |
| `tests/http/history_test.py` | What history records, comparing, restoring, deleted items, renames, and who may do what |
| `tests/http/taxonomies_test.py` | Each taxonomy's own layout, title, paging, filters, and content types; term addresses; redirects when an address changes; who may change them |
| `tests/http/links_test.py` | Links to old addresses: the notice after a change, the review screen, updating, chains, other websites, history, permissions |
| `tests/http/delete_test.py` | Deleting with a redirect: the choices, refused targets, drafts, permissions, restore, bulk delete |
| `tests/http/blocks_test.py` | The comparison table and before/after blocks on the showcase page: markup, accessibility attributes, script bundling, the editor |
| `tests/http/custom_roles_test.py` | Making, changing, giving, and deleting roles of your own; what a person with such a role can reach; the limits and tampering |
| `tests/http/redirects_test.py` | Automatic addresses, address changes, redirects, the not-found list, permissions |

Not covered: real browsers and screen readers, hosting and web server rules, sending email, backups and restores, Google sign-in. See `scripts/theme-audit.js` for the in-browser accessibility scan.

## Adding a test

- **Logic** (PHP): add a `check('what', $actual, $expected)` to a file in `tests/unit/`, or a new file and one line in `run_unit()` of `run.sh`.
- **A screen or a rule**: add checks to a file in `tests/http/`; `client.py` is a tiny cookie-keeping client (`login`, `get`, `submit` a form found on a page). Each test starts from a fresh copy of the fixtures with the default `admin` account. To add a new file, list it in `run_http()` of `run.sh`.
- **When you add an admin action**, add it to the table in `scripts/check-permissions.php`; that is what stops a new screen from being open to the wrong role.
