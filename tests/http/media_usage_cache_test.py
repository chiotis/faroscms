import json, os, re, sys, sqlite3, time, urllib.parse, urllib.request, urllib.error, uuid
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082')
def db(): return sqlite3.connect('app/storage/db/app.sqlite')
def kept():
    r = db().execute("select value from system_meta where key='media_usage'").fetchone(); return json.loads(r[0]) if r else None
def write_kept(payload):
    c = db(); c.execute("insert or replace into system_meta (key, value, updated_at) values ('media_usage', ?, '2026-01-01T00:00:00+00:00')", (payload if isinstance(payload, str) else json.dumps(payload),)); c.commit(); c.close()
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def upload(c, name):
    before = set(f for f in os.listdir('app/content/media')) if os.path.isdir('app/content/media') else set()
    b = uuid.uuid4().hex
    body = b''
    for k, v in (('_csrf', token(c)), ('media_action', 'upload'), ('upload_tags', '')):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file[]"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n'.encode() + PNG + f'\r\n--{b}--\r\n'.encode()
    try: c.opener.open(urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b}))
    except urllib.error.HTTPError: pass
    new = [f for f in os.listdir('app/content/media') if f not in before][0]
    return re.search(r'^path: (.+)$', open('app/content/media/' + new).read(), re.M).group(1).strip()
def row(html, name):
    i = html.find(name)
    return html[html.rfind('<tr', 0, i):html.find('</tr>', i)] if i >= 0 else ''
def save_page(c, slug, body):
    return c.submit(f'/admin/edit?type=pages&slug={slug}&lang=en', has_field('body'), {'title': slug.title(), 'body': body, 'blocks_editor': '0'})
def is_settings(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields'])

root = Client(); root.login()
used = upload(root, 'used-pic.png'); other = upload(root, 'other-pic.png')
save_page(root, 'cache-page', f'![x](/uploads/{used})')

# ---- the first look reads everything and keeps the result
check('nothing is kept before the media screen is opened', kept() is None)
html = root.get('/admin/media?view=list')[2]
k = kept()
check('opening the media screen keeps the result', k is not None and k.get('format') == 1 and re.fullmatch(r'[0-9a-f]{64}', k.get('fingerprint', '')) is not None, k and list(k))
check('with the place each file is used in', used in k['map'] and any('Cache-Page' in p['label'] for p in k['map'][used]) and other not in k['map'], k['map'])
check('and the screen shows it', 'Used in 1 place' in row(html, 'used-pic.png') and 'Unused' in row(html, 'other-pic.png'))

# ---- while nothing changed, the kept result is what is used (proved by planting a false one)
planted = json.loads(json.dumps(k))
planted['map'][other] = [{'label': 'Planted place', 'url': '/admin', 'kind': 'pages'}]
write_kept(planted)
html = root.get('/admin/media')[2]
check('an unchanged site uses the kept result', 'Planted place' in row(html, 'other-pic.png') and 'Used in 1 place' in row(html, 'other-pic.png'), row(html, 'other-pic.png')[-300:])
check('and does not write it again', kept() == planted)

# ---- an edit to any content file makes it differ
time.sleep(1.1)
save_page(root, 'cache-page', f'![x](/uploads/{used}) and more text')
html = root.get('/admin/media')[2]
check('after content changes, it is read again (the planted place is gone)', 'Planted place' not in html and 'Unused' in row(html, 'other-pic.png'), row(html, 'other-pic.png')[-200:])
check('and the new result is kept', kept()['fingerprint'] != planted['fingerprint'] and 'Planted' not in json.dumps(kept()))
now = kept()

# ---- a new file, a removed file, and a page that starts to use another file
save_page(root, 'second-page', f'![y](/uploads/{other})')
html = root.get('/admin/media')[2]
check('a new page that uses a file is noticed at once', 'Used in 1 place' in row(html, 'other-pic.png') and 'Second-Page' in row(html, 'other-pic.png'), row(html, 'other-pic.png')[-300:])
root.submit('/admin/edit?type=pages&slug=second-page&lang=en', has_field('body'), {'body': 'no picture now', 'blocks_editor': '0'})
html = root.get('/admin/media')[2]
check('and so is a page that lets go of it', 'Unused' in row(html, 'other-pic.png'))
os.remove('app/content/pages/second-page.en.md')
html = root.get('/admin/media')[2]
check('a content file deleted by hand is noticed', 'Second-Page' not in html)

# ---- the settings are part of what it was made from
before = kept()['fingerprint']
write_kept(dict(kept(), map=dict(kept()['map'], **{other: [{'label': 'Planted again', 'url': '/admin', 'kind': 'pages'}]})))
root.submit('/admin/settings', is_settings, {'title': 'Another title', 'storage_limit_value': ''})
html = root.get('/admin/media')[2]
check('a change in the site settings makes it differ', 'Planted again' not in html and kept()['fingerprint'] != before)

# ---- an untrustworthy copy is ignored
for label, raw in (('one that is not JSON', 'not json'), ('one from another format', json.dumps({'format': 99, 'fingerprint': kept()['fingerprint'], 'map': {other: [{'label': 'Old format', 'url': '/', 'kind': 'x'}]}})),
                   ('one with no map', json.dumps({'format': 1, 'fingerprint': kept()['fingerprint']})), ('one with a map that is not a list', json.dumps({'format': 1, 'fingerprint': kept()['fingerprint'], 'map': 'oops'}))):
    write_kept(raw)
    st, _, html = root.get('/admin/media')
    check(label + ' is made again, and the screen works', st == 200 and 'Old format' not in html and kept() is not None and kept().get('format') == 1, (st, str(kept())[:80]))

# ---- deleting a used file still asks, using the same result
save_page(root, 'cache-page', f'![x](/uploads/{used})')
used_id = next(f[:-5] for f in os.listdir('app/content/media') if f.endswith('.yaml') and ('path: ' + used) in open('app/content/media/' + f).read())
r = root.request('/admin/media', {'_csrf': token(root), 'media_action': 'delete', 'id': used_id})
check('a used file still cannot be deleted without confirming', 'Not deleted' in urllib.parse.unquote_plus(r[1].get('Location') or ''), r[1].get('Location'))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
