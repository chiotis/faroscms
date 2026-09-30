import os, re, sys, sqlite3, urllib.parse, urllib.request, urllib.error, uuid
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000001e221bc330000000049454e44ae426082')
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def post(c, fields, file=None):
    b = uuid.uuid4().hex
    body = b''
    for k, v in [('_csrf', token(c))] + list(fields):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    if file:
        body += f'--{b}\r\nContent-Disposition: form-data; name="upload_file[]"; filename="{file}"\r\nContent-Type: image/png\r\n\r\n'.encode() + PNG + b'\r\n'
    body += f'--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        r = c.opener.open(req); return r.status, r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.headers
def loc(h): return urllib.parse.unquote_plus(h.get('Location') or '')
def media_ids(): return sorted(f[:-5] for f in (os.listdir('app/content/media') if os.path.isdir('app/content/media') else []) if f.endswith('.yaml'))
def upload(c, name):
    before = set(media_ids())
    post(c, [('media_action', 'upload'), ('upload_tags', '')], name)
    new = set(media_ids()) - before
    assert len(new) == 1, new
    mid = new.pop()
    meta = open(f'app/content/media/{mid}.yaml').read()
    return mid, re.search(r'^path: (.+)$', meta, re.M).group(1).strip()
def delete(c, mid, confirm=False, extra=()):
    fields = [('media_action', 'delete'), ('id', mid)] + ([('confirm_used', '1')] if confirm else []) + list(extra)
    return post(c, fields)
def row(html, name):
    """The list-view row (or card) that holds this file name."""
    i = html.find(name)
    return html[html.rfind('<tr', 0, i):html.find('</tr>', i)] if i >= 0 else ''
def save_page(c, slug, body='x', extra=None):
    f = {'title': slug.title(), 'body': body, 'blocks_editor': '0'}; f.update(extra or {})
    return c.submit(f'/admin/edit?type=pages&slug={slug}&lang=en', has_field('body'), f)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'admin', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})

# ---- a file nobody uses
lone, lone_path = upload(root, 'lone-file.png')
html = root.get('/admin/media')[2]
check('a new file that nothing points at is shown as Unused', 'Unused' in row(html, 'lone-file.png'), row(html, 'lone-file.png')[-400:])
check('the toolbar counts the unused files and links to them', re.search(r'\d+ unused</a>', html) is not None and 'usage=unused' in html)

# ---- used in a page body, and in a block
used, used_path = upload(root, 'used-file.png')
save_page(root, 'usage-body', body=f'Look: ![x](/uploads/{used_path}) here')
html = root.get('/admin/media')[2]
r = row(html, 'used-file.png')
check('a file in a page text says where it is used', 'Used in 1 place' in r and 'Usage-Body' in r, r[-500:])
check('with a link to that page', 'href="' in r and 'slug=usage-body' in r, r[-500:])
save_page(root, 'usage-block', extra={'blocks_editor': '1', 'blocks_json': '[{"type":"hero","heading":"H","image":"/uploads/%s"}]' % used_path})
html = root.get('/admin/media')[2]
r = row(html, 'used-file.png')
check('and in a block of another page', 'Used in 2 places' in r and 'slug=usage-block' in r, r[-500:])
check('the unused file is still unused', 'Unused' in row(html, 'lone-file.png'))

# ---- the grid shows it too
html = root.get('/admin/media?view=thumbs')[2]
check('the grid view shows it as well', 'Used in 2 places' in html and 'Unused' in html)
root.get('/admin/media?view=list')

# ---- filters
html = root.get('/admin/media?usage=unused')[2]
check('the Unused filter shows only unused files', 'lone-file.png' in html and 'used-file.png' not in html, re.findall(r'data-media-count[^<]*<', html))
html = root.get('/admin/media?usage=used')[2]
check('the In use filter shows only files in use', 'used-file.png' in html and 'lone-file.png' not in html)
html = root.get('/admin/media?usage=unused')[2]
check('the filter is kept in the links of the page', 'usage=unused' in html.split('mediaFilter')[0] and 'Unused only' in html)
check('and offered in the filter panel', re.search(r'<option value="unused"\s+selected', html) is not None)

# ---- deleting one that is in use
st, h = delete(root, used)
check('deleting a used file without confirming is refused', st == 302 and 'Not deleted' in loc(h) and 'used in' in loc(h), loc(h))
check('and the file is still there', os.path.exists(f'app/public/uploads/{used_path}') and used in media_ids())
html = root.get('/admin/media')[2]
r = row(html, 'used-file.png')
check('the delete button asks with the places named', 'used in Usage-Body' in html.replace('&#039;', "'") or 'This file is used in' in r, r[-700:])
check('and sends the confirmation flag', 'name="confirm_used" value="1"' in r)
check('an unused file only asks the plain question', 'Delete this media item?' in row(html, 'lone-file.png') and 'confirm_used' not in row(html, 'lone-file.png'))
st, h = delete(root, used, confirm=True)
check('confirmed, it is deleted', st == 302 and 'deleted' in loc(h).lower() and used not in media_ids() and not os.path.exists(f'app/public/uploads/{used_path}'), loc(h))
html = root.get('/admin/activity-logs')[2]
check('the activity log remembers it was in use', 'was_used_in' in html or 'Media item deleted' in html)

# ---- an unused file needs no confirmation
st, h = delete(root, lone)
check('an unused file is deleted at once', 'deleted' in loc(h).lower() and lone not in media_ids(), loc(h))

# ---- bulk delete keeps what is in use
keep, keep_path = upload(root, 'keep-me.png')
junk1, _ = upload(root, 'junk-one.png')
junk2, _ = upload(root, 'junk-two.png')
save_page(root, 'usage-keep', body=f'![k](https://example.test/uploads/{keep_path}?v=2)')
html = root.get('/admin/media')[2]
check('an address with a domain and a query still counts', 'Used in 1 place' in row(html, 'keep-me.png'), row(html, 'keep-me.png')[-300:])
st, h = post(root, [('media_action', 'bulk_delete'), ('selected_ids[]', keep), ('selected_ids[]', junk1)])
check('a bulk delete removes the unused and keeps the used', keep in media_ids() and junk1 not in media_ids() and '1 file was kept' in loc(h), loc(h))
st, h = post(root, [('media_action', 'bulk_delete'), ('selected_ids[]', keep)])
check('when only used files are chosen, nothing is deleted and it says why', keep in media_ids() and 'Nothing deleted' in loc(h), loc(h))
# "delete all unused": filter, apply to all filtered
st, h = post(root, [('media_action', 'bulk_delete'), ('apply_all_filtered', '1'), ('_filter_type', 'all'), ('_filter_usage', 'unused'), ('_state_usage', 'unused')])
check('apply to all filtered with the Unused filter deletes only unused files', junk2 not in media_ids() and keep in media_ids(), (loc(h), media_ids()))
check('and returns to the same filter', 'usage=unused' in loc(h), loc(h))

# ---- when the page stops using it, the file is unused again
save_page(root, 'usage-keep', body='no image any more')
html = root.get('/admin/media')[2]
check('after the page lets go of it, it shows as Unused again', 'Unused' in row(html, 'keep-me.png'))

# ---- the settings count too
db = sqlite3.connect('app/storage/db/app.sqlite')
db.execute("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, '2026-01-01T00:00:00+00:00')", ('{"logo": "/uploads/%s"}' % keep_path,))
db.commit(); db.close()
html = root.get('/admin/media')[2]
r = row(html, 'keep-me.png')
check('a file used in the theme settings shows Theme settings', 'Used in 1 place' in r and 'Theme settings' in r and 'href="' in r and '/admin/theme' in r, r[-400:])

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
