import os, re, sys, urllib.parse, urllib.request, urllib.error, uuid
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
    try:
        r = c.opener.open(urllib.request.Request(BASE + '/admin/media', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})); return r.status, r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.headers
def media_ids(): return sorted(f[:-5] for f in (os.listdir('app/content/media') if os.path.isdir('app/content/media') else []) if f.endswith('.yaml'))

root = Client(); root.login()
root.submit('/admin/users-edit', lambda f: any(x[0] == 'username' for x in f['fields']), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})

# ---- nothing yet
st, _, html = root.get('/admin/media')
check('with no files the screen says so and where to drop them', st == 200 and 'No files uploaded' in html and 'md-upload' in html)
check('the thumbnails are the usual view', re.search(r'<a href="[^"]*view=thumbs[^"]*" title="Thumbnails" aria-current="true"', html) is not None, re.findall(r'title="Thumbnails"[^>]*', html))

# ---- a file
before = set(media_ids())
post(root, [('media_action', 'upload'), ('upload_tags', 'hero, news')], 'sunrise.png')
new = (set(media_ids()) - before).pop()
st, _, html = root.get('/admin/media')
check('a file is a tile with its name, size and whether it is used', 'class="md-grid"' in html and 'sunrise.png' in html and 'class="md-tile"' in html and 'Unused' in html and '<table' not in html)
check('a click on it opens its details: a template for the window, and the window', ('data-md-open="%s"' % new) in html and ('<template data-md-detail="%s">' % new) in html and 'id="media-detail"' in html)
m = re.search(r'<template data-md-detail="%s">(.*?)</template>' % new, html, re.S)
detail = m.group(1) if m else ''
check('the details have the address, the alt text and tags to edit, where it is used, and Delete', all(k in detail for k in ('value="/uploads/', 'name="alt"', 'name="tags"', 'value="hero, news"', 'Nothing points at this file', 'name="media_action" value="delete"', 'name="media_action" value="save_tags"', 'Delete this file')), detail[:200])
check('with the list they came from, so saving returns there', all(('name="_state_%s"' % k) in detail for k in ('type', 'tag', 'q', 'usage', 'view', 'per_page', 'page')))
check('the selection bar and its forms are there', all(k in html for k in ('id="media-bulk-panel"', 'id="media-select-all"', 'id="media-bulk-tags-form"', 'id="media-bulk-delete-form"', 'class="media-select-item"')))
check('a file has a checkbox, a way to copy its address and one to open it', 'class="media-select-item"' in html and 'data-copy-url="/uploads/' in html and 'title="Open file"' in html)

# ---- the details are saved
post(root, [('media_action', 'save_tags'), ('id', new), ('alt', 'A sunrise over the sea'), ('tags', 'hero, sea'), ('_state_view', 'thumbs')])
st, _, html = root.get('/admin/media')
check('the alt text and the tags are saved, and shown in the details', 'value="A sunrise over the sea"' in html and 'value="hero, sea"' in html)

# ---- the views: thumbnails by default, the list on request, remembered for the visit
st, _, html = root.get('/admin/media?view=list')
check('the list is a table of files with a row for each', '<table class="md-table"' in html and 'sunrise.png' in html and 'class="md-grid"' not in html)
check('and the next visit to the screen keeps it', '<table class="md-table"' in root.get('/admin/media')[2])
check('a thumbnail view can be asked for again', 'class="md-grid"' in root.get('/admin/media?view=thumbs')[2])
other = Client(); other.login()
check('a new visit starts with thumbnails again', 'class="md-grid"' in other.get('/admin/media')[2])

# ---- finding files
st, _, html = root.get('/admin/media?q=sunrise')
check('a search finds a file by name, and offers to clear it', 'sunrise.png' in html and 'Clear filters' in html and 'value="sunrise"' in html)
st, _, html = root.get('/admin/media?q=zzz-nothing')
check('a search with no result says so, in place of the files', 'No files match' in html and 'class="md-tile"' not in html and 'Clear filters' in html)
st, _, html = root.get('/admin/media?tag=sea')
check('a tag narrows the list, and there is a list of the tags', 'sunrise.png' in html and 'name="tag"' in html and '<option value="sea" selected>' in html)
st, _, html = root.get('/admin/media?type=document')
check('a kind narrows it', 'sunrise.png' not in html and 'No files match' in html)
st, _, html = root.get('/admin/media')
check('the kinds are links, and so are the filters for use', 'aria-label="Kind of file"' in html and 'aria-label="Use"' in html and re.search(r'>In use</a>', html) is not None and 'per_page' in html)

# ---- deleting from the details
post(root, [('media_action', 'delete'), ('id', new), ('_state_view', 'thumbs')])
check('a file is deleted from its details', new not in media_ids())

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
