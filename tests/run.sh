#!/usr/bin/env bash
#
# Runs the checks:
#   tests/run.sh            everything
#   tests/run.sh unit       the PHP checks only (fast, no server)
#   tests/run.sh js         the JavaScript checks only (the visual editor's serializer and toolbar; needs Node 20 or later)
#   tests/run.sh http       the browser-level tests only
#   tests/run.sh redirects  one HTTP test by name (editor, roles, import, redirects, save, history, taxonomies, links, delete, blocks, hero_layouts, mobile_menu, bottom_bar, logs, users_tabs, sidebar, sidebar_brand, theme_page, media_upload, upload_limits, notifications, bad_yaml, custom_roles, storage, storage_cache, line_endings, media_usage, media_picker, admin_a11y, seo_jsonld, robots, media_usage_cache, update_install, setup, fresh_install, catalogue, menu_editor, form_builder, layouts, header_footer, branding, custom_taxonomies, type_options, demo_content, hero_video, seo, analytics, translations, content_types, media, dashboard, editor_visual, youtube_playlist blocks_pack geo clean_addresses footer_blocks updates_screen)
#
# The HTTP tests never touch your site: they run against a temporary copy of the code with the small
# content set in tests/fixtures, on a free local port, and remove it afterwards. The server runs with the functions that
# shared hosts often switch off (set_time_limit, ignore_user_abort, disk_free_space, opcache_reset, curl_exec) disabled, because
# calling a disabled function is a fatal error and the code must cope. They need PHP and Python 3
# (standard library only).
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MODE="${1:-all}"
FAILED=()
TOTAL_OK=0

report() { # name output
  local ok bad
  ok=$(printf '%s\n' "$2" | grep -c '^ok')
  bad=$(printf '%s\n' "$2" | grep -c '^FAIL')
  if [ "$bad" -eq 0 ] && printf '%s\n' "$2" | grep -q 'ALL PASSED'; then
    printf '  pass  %-28s %s checks\n' "$1" "$ok"
    TOTAL_OK=$((TOTAL_OK + ok))
  else
    printf '  FAIL  %-28s %s failed\n' "$1" "$bad"
    printf '%s\n' "$2" | grep -vE '^ok|^note' | sed 's/^/          /' | head -20
    FAILED+=("$1")
  fi
}

run_unit() {
  echo "Unit checks"
  for spec in "blocks:tests/unit/blocks.php" "content-types:tests/unit/content-types.php" "revisions:tests/unit/revisions.php" "taxonomies:tests/unit/taxonomies.php" "links:tests/unit/links.php" "robots:tests/unit/robots.php" "seo:tests/unit/seo.php" "analytics:tests/unit/analytics.php" "menus:tests/unit/menus.php" "content-csv:tests/unit/content-csv.php" "form-fields:tests/unit/form-fields.php" "form-templates:tests/unit/form-templates.php" "branding:tests/unit/branding.php" "footer-blocks:tests/unit/footer-blocks.php" "starter-content:tests/unit/starter-content.php" "single-layouts:tests/unit/single-layouts.php" "layouts-admin:tests/unit/layouts-admin.php" "site-settings:tests/unit/site-settings.php" "backup-manager:tests/unit/backup-manager.php" "taxonomy-editor:tests/unit/taxonomy-editor.php" "redirect-admin:tests/unit/redirect-admin.php" "translations:tests/unit/translations.php" "media-admin:tests/unit/media-admin.php" "site-limits:tests/unit/site-limits.php" "system-status:tests/unit/system-status.php" "dashboard:tests/unit/dashboard.php" "visual-markdown:tests/unit/visual-markdown.php" "youtube-playlist:tests/unit/youtube-playlist.php" "twig-site-functions:tests/unit/twig-site-functions.php" "block-runtime:tests/unit/block-runtime.php" "login-controller:tests/unit/login-controller.php" "content-paths:tests/unit/content-paths.php" "geo:tests/unit/geo.php" "forms:tests/unit/forms.php" "accounts:tests/unit/accounts.php" "content-type-admin:tests/unit/content-type-admin.php" "archive-builder:tests/unit/archive-builder.php" "backup-admin:tests/unit/backup-admin.php" "content-screens:tests/unit/content-screens.php" "admin-screens:tests/unit/admin-screens.php" "public-site:tests/unit/public-site.php" "settings-and-front:tests/unit/settings-and-front.php" "release-package:tests/unit/release-package.php" "update-installer:tests/unit/update-installer.php" "update-installer-restricted:tests/unit/update-installer-restricted.php" "first-admin:tests/unit/first-admin.php" "content-type-catalogue:tests/unit/content-type-catalogue.php" "html-to-markdown:tests/unit/html-to-markdown.php" "wordpress-import:tests/unit/wordpress-import.php" "permissions:scripts/check-permissions.php" "slugs-and-redirects:scripts/check-slugs.php" "content-blocks:scripts/check-blocks.php"; do
    local name="${spec%%:*}" file="${spec#*:}" out
    out=$(cd "$ROOT" && php "$file" 2>&1)
    if [ "$name" = "content-blocks" ]; then
      # This one prints a summary instead of "ALL PASSED".
      if printf '%s\n' "$out" | tail -1 | grep -q ' 0 problems'; then printf '  pass  %-28s %s\n' "$name" "$(printf '%s\n' "$out" | tail -1)"; else printf '  FAIL  %-28s\n' "$name"; printf '%s\n' "$out" | tail -5 | sed 's/^/          /'; FAILED+=("$name"); fi
    else
      report "$name" "$out"
    fi
  done
}

run_js() {
  echo "JavaScript checks"
  if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
    echo "  FAIL  node and npm are needed for the JavaScript checks"; FAILED+=("node missing"); return
  fi
  # The one tool they need (jsdom) is installed beside them, once, from the lock file. Nothing outside tests/js is touched.
  if [ ! -d "$ROOT/tests/js/node_modules/jsdom" ]; then
    (cd "$ROOT/tests/js" && npm ci --no-audit --no-fund >/dev/null 2>&1) || { echo "  FAIL  npm ci in tests/js did not work"; FAILED+=("npm ci"); return; }
  fi
  report "visual-editor-serializer" "$(cd "$ROOT" && node tests/js/serializer.test.js 2>&1)"
  report "visual-editor-toolbar" "$(cd "$ROOT" && node tests/js/editor-tools.test.js 2>&1)"
  report "seo-count" "$(cd "$ROOT" && node tests/js/seo-count.test.js 2>&1)"
}

run_http() {
  local only="${1:-}"
  command -v python3 >/dev/null 2>&1 || { echo "python3 is needed for the HTTP tests"; FAILED+=("python3 missing"); return; }
  local work port pid
  work="$(mktemp -d)"
  mkdir -p "$work/app"
  # Copy the code but not the site itself (content, storage, custom, uploads) or the tests.
  python3 - "$ROOT" "$work/app" <<'PYCOPY'
import os, shutil, sys
root, dst = sys.argv[1:3]
def ignore(directory, names):
    rel = os.path.relpath(directory, root)
    skip = {'.git', 'node_modules', 'storage', 'custom', 'content', 'tests'} if rel == '.' else ({'uploads'} if rel == 'public' else set())
    return [n for n in names if n in skip]
shutil.copytree(root, dst, ignore=ignore, dirs_exist_ok=True)
PYCOPY
  port="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])')"
  (cd "$work/app" && export PHP_CLI_SERVER_WORKERS=4 && exec php -d disable_functions=set_time_limit,ignore_user_abort,disk_free_space,opcache_reset,curl_exec -S "127.0.0.1:$port" -t public public/index.php >"$work/server.log" 2>&1) &
  pid=$!
  trap 'kill '"$pid"' 2>/dev/null; wait '"$pid"' 2>/dev/null; rm -rf '"$work" EXIT
  for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.1; done

  echo "HTTP tests (temporary copy on port $port)"
  for name in editor roles import redirects save history taxonomies links delete blocks hero_layouts mobile_menu bottom_bar logs users_tabs sidebar sidebar_brand theme_page media_upload upload_limits notifications bad_yaml custom_roles storage storage_cache line_endings media_usage media_picker admin_a11y seo_jsonld robots media_usage_cache update_install setup fresh_install catalogue menu_editor form_builder layouts header_footer branding custom_taxonomies type_options demo_content hero_video seo analytics translations content_types media dashboard editor_visual youtube_playlist blocks_pack geo clean_addresses footer_blocks updates_screen; do
    [ -n "$only" ] && [ "$only" != "$name" ] && continue
    # Every test starts from the same clean site.
    rm -rf "$work/app/content" "$work/app/storage" "$work/app/custom" "$work/app/public/uploads"
    mkdir -p "$work/app/storage/db" "$work/app/storage/backups" "$work/app/custom" "$work/app/public/uploads"
    cp -R "$ROOT/tests/fixtures/content" "$work/app/content"
    local out
    out=$(cd "$work" && FAROS_TEST_BASE="http://127.0.0.1:$port" PYTHONPATH="$ROOT/tests/http" python3 "$ROOT/tests/http/${name}_test.py" 2>&1)
    report "$name" "$out"
  done
  if grep -qiE 'fatal|warning:|deprecated' "$work/server.log" 2>/dev/null; then
    echo "  note  the server log has PHP warnings or errors:"; grep -iE 'fatal|warning:|deprecated' "$work/server.log" | sort | uniq -c | head -5 | sed 's/^/          /'
    FAILED+=("php warnings")
  fi
}

case "$MODE" in
  all) run_unit; echo; run_js; echo; run_http ;;
  unit) run_unit ;;
  js) run_js ;;
  http) run_http ;;
  editor|roles|import|redirects|save|history|taxonomies|links|delete|blocks|hero_layouts|mobile_menu|bottom_bar|logs|users_tabs|sidebar|sidebar_brand|theme_page|media_upload|upload_limits|notifications|bad_yaml|custom_roles|storage|storage_cache|line_endings|media_usage|media_picker|admin_a11y|seo_jsonld|robots|media_usage_cache|update_install|setup|fresh_install|catalogue|menu_editor|form_builder|layouts|header_footer|branding|custom_taxonomies|type_options|demo_content|hero_video|seo|analytics|translations|content_types|media|dashboard|editor_visual|youtube_playlist|blocks_pack|geo|clean_addresses|footer_blocks|updates_screen) run_http "$MODE" ;;
  *) echo "Unknown option: $MODE"; exit 2 ;;
esac

echo
if [ ${#FAILED[@]} -eq 0 ]; then echo "ALL GOOD ($TOTAL_OK checks)"; exit 0; fi
echo "FAILED: ${FAILED[*]}"; exit 1
