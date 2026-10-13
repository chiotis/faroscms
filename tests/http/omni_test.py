"""Admin > Omni: the super admin sees what the site reports, connects it to an Omni with a code, and the site then reports on its
own (after an admin page, after a backup, from the cron script), signed. A small Omni stand-in runs inside this test and checks
every signature the way the real one does. Nothing private is in a report, and no other role can open the screen."""
import hashlib, hmac, html as htmllib, json, re, subprocess, sqlite3, sys, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()
def meta(key):
    c = sqlite3.connect('app/storage/db/app.sqlite'); row = c.execute('select value from system_meta where key = ?', (key,)).fetchone(); c.close()
    return json.loads(row[0]) if row else None
def flash(html):
    m = re.search(r'<template data-flash data-type="(\w+)">(.*?)</template>', html, re.S)
    return (m.group(1), htmllib.unescape(m.group(2).strip())) if m else (None, '')
def loc(hdr): return hdr.get('Location') or ''

# ---- an Omni stand-in: ping, connect with one code, signed reports and disconnect
CODE, SITE_ID, SECRET = 'K7QM-4TZP-9WXC-2HRD', 's_0123456789ab', 'Xy9-secret_from_the_omni_standin_0123456789'
omni = {'mode': 'ok', 'reports': [], 'bad': [], 'disconnects': 0, 'connects': [], 'nonces': set()}
class Omni(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *a): pass
    def _send(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_GET(self):
        if self.path == '/api/v1/ping': return self._send(200, {'omni': True, 'name': 'Stand-in', 'protocols': [1]})
        self._send(404, {'ok': False})
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        if self.path == '/api/v1/connect':
            data = json.loads(body or b'{}'); omni['connects'].append(data)
            if str(data.get('code', '')).upper().replace(' ', '') != CODE: return self._send(403, {'ok': False, 'error': 'invalid_code', 'message': 'no'})
            return self._send(200, {'site_id': SITE_ID, 'secret': SECRET, 'report_every': 21600})
        h = self.headers
        to_sign = '\n'.join(['POST', self.path, h.get('X-Omni-Timestamp', ''), h.get('X-Omni-Nonce', ''), hashlib.sha256(body).hexdigest()])
        good = (h.get('X-Omni-Site') == SITE_ID and hmac.compare_digest('v1=' + hmac.new(SECRET.encode(), to_sign.encode(), hashlib.sha256).hexdigest(), h.get('X-Omni-Signature', ''))
                and abs(time.time() - int(h.get('X-Omni-Timestamp') or 0)) <= 300 and re.fullmatch(r'[0-9a-f]{32}', h.get('X-Omni-Nonce', '')) and h.get('X-Omni-Nonce') not in omni['nonces'])
        omni['nonces'].add(h.get('X-Omni-Nonce'))
        if not good:
            omni['bad'].append(self.path); return self._send(401, {'ok': False, 'error': 'invalid_signature'})
        if omni['mode'] == 'removed': return self._send(410, {'ok': False, 'error': 'site_removed'})
        if self.path == '/api/v1/report':
            omni['reports'].append(json.loads(body)); return self._send(200, {'ok': True, 'report_every': 21600})
        if self.path == '/api/v1/disconnect':
            omni['disconnects'] += 1; return self._send(200, {'ok': True})
        self._send(404, {'ok': False})
server = ThreadingHTTPServer(('127.0.0.1', 0), Omni)
threading.Thread(target=server.serve_forever, daemon=True).start()
OMNI = 'http://127.0.0.1:%d' % server.server_address[1]

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'adm1', 'email': 'adm1@example.test', 'display_name': 'adm1', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
adm = Client(); adm.login('adm1', 'Sturdy-pass-99')
run("insert into backup_runs (filename, status, size_bytes, message, created_at) values (?, 'failed', 0, 'Disk full.', ?)", ('site-backup-x.zip', '2026-10-13T03:00:00+00:00'))

# ---- before connecting: the preview
st, _, page = root.get('/admin/omni')
check('the super admin opens the screen', st == 200, st)
check('the sidebar links to it, marked as the current page', re.search(r'href="[^"]*admin/omni"[^>]*aria-current="page"', page) is not None)
m = re.search(r'<pre[^>]*>(.*?)</pre>', page, re.S)
report = json.loads(htmllib.unescape(m.group(1))) if m else {}
check('the preview is the JSON of the report', list(report.keys()) == ['protocol', 'sent_at', 'site', 'faros', 'storage', 'backups', 'analytics', 'updates', 'system'], list(report.keys()))
site = report.get('site', {})
check('the site and the link to its admin', site.get('url', '').startswith('http://127.0.0.1') and site.get('admin_url', '').endswith('/admin'), site)
check('the installed version', report.get('faros', {}).get('version') == open('app/VERSION').read().strip(), report.get('faros'))
storage = report.get('storage', {})
check('storage in bytes; the disk space this host hides is unknown', isinstance(storage.get('used'), int) and storage.get('disk_free') is None, storage)
last = report.get('backups', {}).get('last') or {}
check('the last backup run, in UTC', [last.get('status'), last.get('at'), last.get('message')] == ['failed', '2026-10-13T03:00:00Z', 'Disk full.'], last)
check('analytics: whether on and which kind, nothing more', report.get('analytics') == {'enabled': False, 'mode': 'off'}, report.get('analytics'))
check('the health checks with their verdict', report.get('system', {}).get('summary', {}).get('status') in ('ok', 'warning', 'error') and len(report.get('system', {}).get('checks', [])) >= 10)
summary = re.search(r'data-omni-summary.*?</dl>', page, re.S)
check('the summary names the last backup as failed', summary is not None and 'Failed · ' in summary.group(0))
check('not connected: a form with the public Omni filled in', 'Not connected' in page and 'name="omni_url" value="https://omni.faroscms.com"' in page and 'name="omni_code"' in page)

# ---- connecting
def connect(url, code):
    st, hdr, _ = root.submit('/admin/omni', lambda f: ['omni_action', 'connect'] in f['buttons'] or any(x[0] == 'omni_code' for x in f['fields']), {'omni_url': url, 'omni_code': code}, button=('omni_action', 'connect'))
    return st, loc(hdr), root.get(loc(hdr))[2]
st, where, page = connect('http://omni.example.com', CODE)
check('an Omni over plain http (not local) is refused before anything is sent', 'over https' in flash(page)[1] and omni['connects'] == [], flash(page))
st, where, page = connect(OMNI, 'AAAA-BBBB-CCCC-DDDD')
check('a wrong code is refused, with what to do', flash(page)[0] == 'error' and 'Make a new one in Omni' in flash(page)[1], flash(page))
check('and the address typed is kept in the form', 'name="omni_url" value="%s"' % OMNI in page)
st, where, page = connect(OMNI, CODE.lower())
check('the right code connects, and the first report goes at once', flash(page) == ('success', 'Connected to %s. The first report was sent.' % OMNI), flash(page))
check('the site said who it is', omni['connects'][-1].get('protocol') == 1 and omni['connects'][-1].get('site', {}).get('admin_url', '').endswith('/admin'), omni['connects'][-1])
check('the report arrived, signed so that Omni accepts it', len(omni['reports']) == 1 and omni['bad'] == [], (len(omni['reports']), omni['bad']))
sent = json.dumps(omni['reports'][0]) if omni['reports'] else ''
check('it is the report of the preview, without the secret', omni['reports'] and omni['reports'][0].get('protocol') == 1 and SECRET not in sent and 'cron-backup.php' not in sent)
check('the screen: connected, to which Omni, the last report', 'Connected' in page and OMNI in page and 'none yet' not in page and 'name="omni_code"' not in page)
check('the secret is never on the page', SECRET not in page)
state = meta('omni') or {}
check('kept in the system database: the id, the secret, the address', [state.get('status'), state.get('site_id'), state.get('secret'), state.get('url')] == ['connected', SITE_ID, SECRET, OMNI], state)
st, _, logs = root.get('/admin/activity-logs')
check('connecting is in the activity log', 'omni.connect' in logs)

# ---- reporting by itself
st, hdr, _ = root.submit('/admin/omni', lambda f: ['omni_action', 'send'] in f['buttons'], button=('omni_action', 'send'))
check('Send report now', flash(root.get(loc(hdr))[2]) == ('success', 'Report sent.') and len(omni['reports']) == 2, len(omni['reports']))
root.get('/admin')
check('an admin page sends nothing when no report is due', len(omni['reports']) == 2, len(omni['reports']))
run("insert into backup_runs (filename, status, size_bytes, message, created_at) values (?, 'success', 2048, '', ?)", ('site-backup-y.zip', '2026-10-13T04:00:00+00:00'))
root.get('/admin')
time.sleep(0.3)
check('after a backup, the next admin page sends a report with it', len(omni['reports']) == 3 and (omni['reports'][-1].get('backups', {}).get('last') or {}).get('status') == 'success', len(omni['reports']))
state = meta('omni'); state['last_sent_at'] = '2026-01-01T00:00:00+00:00'; run("update system_meta set value = ? where key = 'omni'", (json.dumps(state),))
out = subprocess.run(['php', 'app/src/cron-backup.php'], capture_output=True, text=True, timeout=60)
check('the cron script sends a report that is due, and still says only what became of the backup', len(omni['reports']) == 4 and out.stdout.strip() in ('off', 'not due', 'ok'), (len(omni['reports']), out.stdout, out.stderr))
check('every report was signed right', omni['bad'] == [], omni['bad'])

# ---- what Omni can answer
omni['mode'] = 'removed'
st, hdr, _ = root.submit('/admin/omni', lambda f: ['omni_action', 'send'] in f['buttons'], button=('omni_action', 'send'))
page = root.get(loc(hdr))[2]
check('removed in Omni: the site is told, forgets its secret, and offers to connect again', 'Removed by Omni' in page and 'name="omni_code"' in page and 'secret' not in (meta('omni') or {}), flash(page))
omni['mode'] = 'ok'
connect(OMNI, CODE)
st, hdr, _ = root.submit('/admin/omni', lambda f: ['omni_action', 'disconnect'] in f['buttons'], button=('omni_action', 'disconnect'))
page = root.get(loc(hdr))[2]
check('disconnecting tells Omni, signed, and the form comes back', omni['disconnects'] == 1 and 'Not connected' in page and 'name="omni_url" value="%s"' % OMNI in page, (omni['disconnects'], flash(page)))
check('and nothing is sent any more', meta('omni') == {'url': OMNI}, meta('omni'))

# ---- only the super admin
st, _, _ = adm.get('/admin/omni')
check('an administrator may not open it', st == 403, st)
st, _, page = adm.get('/admin')
check('nor sees it in the sidebar', 'admin/omni"' not in page)
st, _, _ = adm.request('/admin/omni', data={'_csrf': re.search(r'name="_csrf" value="([0-9a-f]+)"', page).group(1), 'omni_action': 'connect', 'omni_url': OMNI, 'omni_code': CODE})
check('nor connect the site', st == 403, st)
st, _, page = root.get('/admin/roles')
check('the roles screen offers no switch for it', 'caps[omni.manage]' not in page and 'value="omni.manage"' not in page)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
