"""Admin > Omni: the super admin sees what the site would report to an Omni (the summary and the JSON of protocol v1),
nothing private is in it, and no other role can open it or sees it in the sidebar."""
import html as htmllib, json, re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'adm1', 'email': 'adm1@example.test', 'display_name': 'adm1', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
adm = Client(); adm.login('adm1', 'Sturdy-pass-99')

# A backup run that failed, so the report has something to say about backups.
run("insert into backup_runs (filename, status, size_bytes, message, created_at) values (?, 'failed', 0, 'Disk full.', ?)", ('site-backup-x.zip', '2026-10-13T03:00:00+00:00'))

st, _, page = root.get('/admin/omni')
check('the super admin opens the screen', st == 200, st)
check('the sidebar links to it, marked as the current page', re.search(r'href="[^"]*admin/omni"[^>]*aria-current="page"', page) is not None)
m = re.search(r'<pre[^>]*>(.*?)</pre>', page, re.S)
report = json.loads(htmllib.unescape(m.group(1))) if m else {}
check('the preview is the JSON of the report', list(report.keys()) == ['protocol', 'sent_at', 'site', 'faros', 'storage', 'backups', 'analytics', 'updates', 'system'], list(report.keys()))
check('protocol 1', report.get('protocol') == 1)
site = report.get('site', {})
check('the site and the link to its admin', site.get('url', '').startswith('http://127.0.0.1') and site.get('admin_url', '').endswith('/admin'), site)
check('the installed version', report.get('faros', {}).get('version') == open('app/VERSION').read().strip(), report.get('faros'))
storage = report.get('storage', {})
check('storage in bytes, the parts as numbers', all(isinstance(storage.get('parts', {}).get(k), int) for k in ('uploads', 'content', 'system')) and isinstance(storage.get('used'), int), storage)
check('the disk space this host hides is unknown', storage.get('disk_free') is None, storage.get('disk_free'))
last = report.get('backups', {}).get('last') or {}
check('the last backup run, in UTC', [last.get('status'), last.get('at'), last.get('message')] == ['failed', '2026-10-13T03:00:00Z', 'Disk full.'], last)
check('analytics: whether on and which kind, nothing more', report.get('analytics') == {'enabled': False, 'mode': 'off'}, report.get('analytics'))
check('the health checks with their verdict', report.get('system', {}).get('summary', {}).get('status') in ('ok', 'warning', 'error') and len(report.get('system', {}).get('checks', [])) >= 10)
summary = re.search(r'data-omni-summary.*?</dl>', page, re.S)
check('the summary names the last backup as failed', summary is not None and 'Failed · ' in summary.group(0), summary and summary.group(0)[:300])
check('and says the site is not connected', 'Not connected' in page)
check('no file paths from the server in the report', 'cron-backup.php' not in m.group(1) if m else False)

st, _, page = adm.get('/admin/omni')
check('an administrator may not open it', st == 403, st)
st, _, page = adm.get('/admin')
check('nor sees it in the sidebar', 'admin/omni"' not in page)
st, _, page = root.get('/admin/roles')
check('the roles screen offers no switch for it', 'caps[omni.manage]' not in page and 'value="omni.manage"' not in page)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
