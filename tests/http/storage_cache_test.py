import json, os, re, sys, time, sqlite3, urllib.request, urllib.error, uuid
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

MB = 1024 * 1024
HOUR = 3600
PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082')

def db(): return sqlite3.connect('app/storage/db/app.sqlite')
def run(sql, *args):
    c = db(); c.execute(sql, args); c.commit(); c.close()
def kept():
    r = db().execute("select value from system_meta where key='storage_usage'").fetchone()
    return json.loads(r[0]) if r else None
def keep(uploads, age=0, content=1000, system=2000):
    run("insert or replace into system_meta (key, value, updated_at) values ('storage_usage', ?, '2026-01-01T00:00:00+00:00')",
        json.dumps({'parts': {'uploads': uploads, 'content': content, 'system': system}, 'measured_at': int(time.time()) - age}))
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def used_label(c):
    m = re.search(r'<span>Storage</span><span class="[^"]*">([^<]*)</span>', c.get('/admin')[2])
    return m.group(1) if m else None
def post_media(c, fields, file=None):
    b = uuid.uuid4().hex
    body = b''
    for k, v in [('_csrf', token(c))] + list(fields.items()):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    if file:
        body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file[]"; filename="{file}"\r\nContent-Type: image/png\r\n\r\n'.encode() + PNG + b'\r\n'
    body += f'--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        r = c.opener.open(req); return r.status, r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.headers

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'adm1', 'email': 'adm1@example.test', 'display_name': 'adm1', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
adm = Client(); adm.login('adm1', 'Sturdy-pass-99')
run("delete from system_meta where key='storage_usage'")

# ---- the first look measures and keeps
root.get('/admin')
k = kept()
check('the first page measures the folders and keeps the result', k is not None and set(k['parts']) == {'uploads', 'content', 'system'} and all(isinstance(v, int) for v in k['parts'].values()), k)
check('with the time it was measured', k is not None and abs(k['measured_at'] - time.time()) < 60, k)

# ---- a kept measurement is used for twelve hours, without walking the folders
keep(5 * MB, age=60)
check('a young measurement is what the sidebar shows', (used_label(root) or '').startswith('5.0 MB') or (used_label(root) or '').startswith('5.'), used_label(root))
with open('app/public/uploads/behind-its-back.bin', 'wb') as f: f.write(b'\0' * (3 * MB))
check('a folder that changed behind its back is not measured again', (used_label(root) or '').startswith('5.'), used_label(root))
keep(5 * MB, age=11 * HOUR + 59 * 60)
check('at 11 h 59 min it is still used', (used_label(root) or '').startswith('5.') and kept()['parts']['uploads'] == 5 * MB, kept())

# ---- after twelve hours it is measured again
keep(5 * MB, age=12 * HOUR + 5)
label = used_label(root)
k = kept()
check('after 12 hours it is measured again', k['parts']['uploads'] >= 3 * MB and k['parts']['uploads'] != 5 * MB and abs(k['measured_at'] - time.time()) < 60, k)
check('and the new size includes what changed behind its back', k['parts']['uploads'] >= 3 * MB, k)
os.remove('app/public/uploads/behind-its-back.bin')

# ---- a measurement that cannot be trusted is measured again
for label, raw in (('one from the future', {'parts': {'uploads': 9 * MB, 'content': 1, 'system': 1}, 'measured_at': int(time.time()) + 5 * HOUR}),
                   ('one with a part missing', {'parts': {'uploads': 9 * MB, 'content': 1}, 'measured_at': int(time.time())}),
                   ('one with a part that is not a number', {'parts': {'uploads': 'lots', 'content': 1, 'system': 1}, 'measured_at': int(time.time())})):
    run("insert or replace into system_meta (key, value, updated_at) values ('storage_usage', ?, '2026-01-01T00:00:00+00:00')", json.dumps(raw))
    root.get('/admin')
    check(label + ' is measured again', kept()['parts']['uploads'] != 9 * MB and kept()['parts']['uploads'] != 'lots', kept())
run("insert or replace into system_meta (key, value, updated_at) values ('storage_usage', 'not json', '2026-01-01T00:00:00+00:00')")
st, _, html = root.get('/admin')
check('so is one that is not even JSON, and the page still loads', st == 200 and isinstance(kept(), dict) and 'parts' in kept())

# ---- uploads and deletions count at once
keep(5 * MB)
before = kept()['parts']['uploads']
st, hdr = post_media(root, {'media_action': 'upload', 'action': 'upload', 'upload_tags': ''}, 'kept.png')
after = kept()['parts']['uploads']
check('an upload adds its size to the kept measurement', st == 302 and after - before == len(PNG), (st, before, after))
newest = max((f for f in os.listdir('app/content/media') if f.endswith('.yaml')), key=lambda f: os.path.getmtime('app/content/media/' + f))
mid = newest[:-5]
st, hdr = post_media(root, {'media_action': 'delete', 'action': 'delete', 'id': mid})
check('deleting an item takes its size off', st == 302 and kept()['parts']['uploads'] == before, (st, kept()['parts']['uploads'], before))
check('and the item is gone', not os.path.exists('app/content/media/' + newest))

# ---- the limit still holds between measurements
run("delete from system_meta where key='site_settings'")
root.submit('/admin/settings', lambda f: any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields']), {'storage_limit_value': '10', 'storage_limit_unit': 'mb'})
keep(10 * MB, content=0, system=0)   # far more than the folder really holds
n = len([f for f in os.listdir('app/content/media') if f.endswith('.yaml')])
st, hdr = post_media(root, {'media_action': 'upload', 'action': 'upload', 'upload_tags': ''}, 'over.png')
check('at the limit by the kept size, an upload is refused', st == 302 and 'storage+limit+is+reached' in (hdr.get('Location') or '').replace('%20', '+'), (st, hdr.get('Location')))
check('and nothing was stored', len([f for f in os.listdir('app/content/media') if f.endswith('.yaml')]) == n)
root.submit('/admin/settings', lambda f: any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields']), {'storage_limit_value': '1', 'storage_limit_unit': 'gb'})

# ---- the settings screen: when, and a button to do it now
keep(7 * MB, age=2 * HOUR)
st, _, html = root.get('/admin/settings?tab=limits')
check('the Limits tab says when it was measured and how often', 'Measured ' in html and 'every 12 hours' in html, re.findall(r'Measured[^<]*', html))
check('and has a Recalculate button', 'Recalculate now' in html and 'id="storage-recalculate"' in html and 'name="storage_action" value="recalculate"' in html)
check('the button is outside the main form, so it saves nothing else', re.search(r'<form id="storage-recalculate"', html) is not None and html.count('form="storage-recalculate"') == 1)
st, _, html = adm.get('/admin/settings')
check('an admin has neither', 'Recalculate now' not in html and 'storage-recalculate' not in html)
adm_csrf = token(adm)
adm.request('/admin/settings?tab=limits', {'_csrf': adm_csrf, 'storage_action': 'recalculate'})
check('and cannot do it by sending the field', kept()['parts']['uploads'] == 7 * MB, kept())
st, hdr, _ = root.request('/admin/settings?tab=limits', {'_csrf': token(root), 'storage_action': 'recalculate'})
k = kept()
check('Recalculate measures now', k['parts']['uploads'] != 7 * MB and abs(k['measured_at'] - time.time()) < 60, k)
st, _, html = root.get('/admin/settings?tab=limits&storage=measured')
check('and says so in the bar', 'data-flash' in html and 'Storage measured again' in html, re.findall(r'Storage measured[^<]*', html))
st, _, html = adm.get('/admin/settings?storage=measured')
check('the message is only for who may see the numbers', 'Storage measured again' not in html)
st, _, html = root.get('/admin/activity-logs')
check('it is in the activity log', 'limits.storage_recalculate' in html or 'Storage use measured again' in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
