"""Revision history: what is recorded, comparing, restoring, deleted items, renames, and who may do what."""
import re, sqlite3, sys, urllib.parse, zlib
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

def db():
    return sqlite3.connect('app/storage/db/app.sqlite')

def revisions(type_, slug, lang='el'):
    c = db()
    rows = c.execute('select id, action, actor, title, checksum from content_revisions where type=? and slug=? and lang=? order by id', (type_, slug, lang)).fetchall()
    c.close()
    return rows

def raw_of(rev_id):
    c = db()
    blob = c.execute('select content from content_revisions where id=?', (rev_id,)).fetchone()[0]
    c.close()
    return zlib.decompress(blob, -15).decode('utf-8')

def path(type_, slug, lang='el'):
    return f'app/content/{type_}/{slug}' + ('' if lang == 'el' else '.' + lang) + '.md'

def read(type_, slug, lang='el'):
    return open(path(type_, slug, lang), encoding='utf-8').read()

def loc(hdr): return hdr.get('Location') or ''

def token(client):
    return re.search(r'name="_csrf" value="([0-9a-f]+)"', client.get('/admin')[2]).group(1)

def create(client, title, body, type_='pages', extra=None):
    fields = {'title': title, 'status': 'published', 'visible': '1', 'body': body}
    fields.update(extra or {})
    st, hdr, _ = client.submit(f'/admin/edit?type={type_}&slug=&lang=el', has_field('body'), fields)
    return urllib.parse.unquote(re.search(r'slug=([^&]+)', loc(hdr)).group(1))

def edit(client, type_, slug, fields, lang='el', drop=()):
    st, hdr, _ = client.submit(f'/admin/edit?type={type_}&slug={slug}&lang={lang}', has_field('body'), fields, drop=list(drop))
    return st, urllib.parse.unquote(re.search(r'slug=([^&]+)', loc(hdr)).group(1)) if 'slug=' in loc(hdr) else None, loc(hdr)

def post(client, do, rev_id, tok=None):
    return client.request('/admin/revisions', data=[('_csrf', tok or token(client)), ('do', do), ('id', str(rev_id))])

root = Client(); root.login()
for u, r in (('ed1', 'editor'), ('bob', 'user')):
    root.submit('/admin/users-edit', has_field('username'), {'username': u, 'email': u + '@example.test', 'display_name': u, 'role': r, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed, bob = Client(), Client(); ed.login('ed1', 'Sturdy-pass-99'); bob.login('bob', 'Sturdy-pass-99')

# ---- saves are recorded
slug = create(root, 'Ιστορικό δοκιμής', 'First version of the text')
check('address made from the title', slug == 'istoriko-dokimis', slug)
revs = revisions('pages', slug)
check('creating records one version', [r[1] for r in revs] == ['create'] and revs[0][2] == 'admin' and revs[0][3] == 'Ιστορικό δοκιμής', revs)
first_file = read('pages', slug)
check('the recorded text is the file', raw_of(revs[0][0]) == first_file)

edit(root, 'pages', slug, {'body': 'Second version of the text'})
revs = revisions('pages', slug)
check('a save adds a version', [r[1] for r in revs] == ['create', 'save'], revs)
second_file = read('pages', slug)
edit(root, 'pages', slug, {'body': 'Second version of the text'})
check('saving the same text again adds nothing', len(revisions('pages', slug)) == 2)

# ---- a change made outside the editor is kept before the next save overwrites it
open(path('pages', slug), 'a', encoding='utf-8').write('\nAdded by hand in a text editor\n')
hand_file = read('pages', slug)
edit(root, 'pages', slug, {'body': 'Third version of the text'})
revs = revisions('pages', slug)
check('outside change and the save are both recorded', [r[1] for r in revs] == ['create', 'save', 'external', 'save'], [r[1] for r in revs])
check('the outside change was kept whole', raw_of(revs[2][0]) == hand_file and 'Added by hand' in hand_file)

# an untouched item gets a baseline the first time it is saved
before = read('pages', 'careers')
edit(root, 'pages', 'careers', {'title': 'Καριέρα'})
revs = revisions('pages', 'careers')
check('first save of an old item keeps what was there', [r[1] for r in revs][:1] == ['baseline'] and raw_of(revs[0][0]) == before, [r[1] for r in revs])

# ---- screens
st, _, html = root.get('/admin/revisions')
check('history screen lists the changes', st == 200 and 'Ιστορικό δοκιμής' in html and 'Changed outside the editor' in html, st)
check('history is in the sidebar', 'admin/revisions' in html)
st, _, html = root.get(f'/admin/revisions?type=pages&slug={slug}&lang=el')
check('item history lists every version', st == 200 and html.count('View changes') >= 4, st)
st, _, html = root.get(f'/admin/edit?type=pages&slug={slug}&lang=el')
check('editor has a History tab', 'data-tab="history"' in html and 'data-panel="history"' in html and 'admin/revisions?id=' in html)
st, _, html = root.get('/admin/edit?type=pages&slug=&lang=el')
check('a new item has no History tab', 'data-tab="history"' not in html)

revs = revisions('pages', slug)
second_id = revs[1][0]
st, _, html = root.get(f'/admin/revisions?id={second_id}')
check('a version shows what it changed', st == 200 and 'Second version of the text' in html and 'bg-emerald-50' in html and 'bg-red-50' in html and 'Restore this version' in html, st)
st, _, html = root.get(f'/admin/revisions?id={second_id}&mode=restore')
check('restore preview compares with the current text', st == 200 and 'Third version of the text' in html and 'If you restore it' in html)
latest_id = revs[-1][0]
st, _, html = root.get(f'/admin/revisions?id={latest_id}')
check('the newest version cannot be restored over itself', 'Restore this version' not in html)
check('unknown version goes back to the list', root.get('/admin/revisions?id=99999')[0] in (302, 303))

# ---- restore
check('restore needs a valid token', post(root, 'restore', second_id, 'bad')[0] == 419)
st, hdr, _ = post(root, 'restore', second_id)
check('restore redirects to the editor', st in (302, 303) and 'restored=1' in loc(hdr), (st, loc(hdr)))
check('the file is back to that version', read('pages', slug) == second_file)
revs = revisions('pages', slug)
check('restoring is itself recorded, and what it replaced is kept', revs[-1][1] == 'restore' and 'Third version' in raw_of(revs[-2][0]), [r[1] for r in revs])
st, _, html = root.get(f'/admin/edit?type=pages&slug={slug}&lang=el&saved=1&restored=1')
check('editor says a version was restored', 'Version restored' in html)
st, _, html = root.get(f'/en/{slug}') if False else Client().get(f'/{slug}')
check('the public page shows the restored text', 'Second version of the text' in html)

# ---- rename keeps the history
n = len(revisions('pages', slug))
st, new_slug, l = edit(root, 'pages', slug, {'slug': 'nea-dieuthinsi'})
check('renamed', new_slug == 'nea-dieuthinsi', (st, l))
check('history moved with the item', revisions('pages', slug) == [] and len(revisions('pages', 'nea-dieuthinsi')) == n, (n, len(revisions('pages', slug)), len(revisions('pages', 'nea-dieuthinsi'))))
slug = 'nea-dieuthinsi'

# translations move together
edit(root, 'pages', 'about', {'title': 'Σχετικά'}); edit(root, 'pages', 'about', {'title': 'About us'}, lang='en')
edit(root, 'pages', 'about', {'slug': 'who-we-are'})
check('every language keeps its history when the address changes', len(revisions('pages', 'who-we-are', 'el')) >= 2 and len(revisions('pages', 'who-we-are', 'en')) >= 2 and revisions('pages', 'about', 'el') == [] and revisions('pages', 'about', 'en') == [])

# ---- delete and bring back
before_delete = read('pages', slug)
st, hdr, _ = root.request('/admin/delete', data=[('_csrf', token(root)), ('type', 'pages'), ('slug', slug), ('lang', 'el')])
import os
check('deleted', not os.path.exists(path('pages', slug)))
revs = revisions('pages', slug)
check('the delete is recorded with the whole text', revs[-1][1] == 'delete' and raw_of(revs[-1][0]) == before_delete, [r[1] for r in revs])
st, _, html = root.get('/admin/revisions?view=deleted')
check('deleted items are listed', st == 200 and 'Bring back' in html and slug in html)
st, _, html = root.get(f'/admin/revisions?id={revs[-1][0]}')
check('a deleted version offers Bring back, not Restore', 'Bring back' in html and 'Restore this version' not in html)
# something else took the address meanwhile
occupied = create(root, 'Another', 'Occupies the address', extra={'slug': slug})
check('the address was taken by other content', occupied == slug)
st, hdr, _ = post(root, 'undelete', revs[-1][0])
check('bringing back onto a used address is refused', 'error=address_in_use' in loc(hdr) and 'Occupies' in read('pages', slug), loc(hdr))
root.request('/admin/delete', data=[('_csrf', token(root)), ('type', 'pages'), ('slug', slug), ('lang', 'el')])
latest_delete = [r for r in revisions('pages', slug) if r[1] == 'delete'][-1]
# the newest delete is of the other content now; bring back the older version through its own id
older = [r for r in revisions('pages', slug) if raw_of(r[0]) == before_delete][-1]
st, hdr, _ = post(root, 'undelete', latest_delete[0])
check('bringing back restores the deleted text', st in (302, 303) and 'restored=1' in loc(hdr) and 'Occupies the address' in read('pages', slug))
st, _, html = root.get('/admin/revisions?view=deleted')
check('a brought-back item leaves the deleted list', slug not in html or 'Bring back' not in html)

# ---- filters
st, _, html = root.get('/admin/revisions?action=delete'); check('filter by change', st == 200 and 'text-red-700">Deleted' in html and 'text-slate-700">Changed outside' not in html)
st, _, html = root.get('/admin/revisions?actor=admin&type=pages'); check('filter by person and type', st == 200 and 'Καριέρα' in html)
st, _, html = root.get('/admin/revisions?q=' + urllib.parse.quote('Καριέρα')); check('search by title', 'Καριέρα' in html and 'Occupies' not in html)

# ---- editors and basic users
check('basic user has no history', bob.get('/admin/revisions')[0] == 403)
check('editor can open history', ed.get('/admin/revisions')[0] == 200)
st, _, html = ed.get('/admin/revisions'); check('editor sees the History link', 'admin/revisions' in html)

# forms are out of reach of editors, in the lists and by address
root.submit('/admin/edit?type=forms&slug=contact&lang=el', has_field('body'), {'form_submit_label': 'Send now'})
form_rev = revisions('forms', 'contact')[-1][0]
st, _, html = ed.get('/admin/revisions')
check('editor does not see form changes in the list', 'forms/contact' not in html)
check('editor cannot open a form version', ed.get(f'/admin/revisions?id={form_rev}')[0] in (302, 303, 403))
check('editor cannot list a form history', ed.get('/admin/revisions?type=forms&slug=contact&lang=el')[0] == 403)
st, _, _ = post(ed, 'restore', form_rev)
check('editor cannot restore a form version', st == 403 and 'Send now' in read('forms', 'contact'), st)
st, _, html = ed.get('/admin/revisions?view=deleted'); check('deleted list works for editors', st == 200)

# ---- raw HTML cannot be brought back by someone who may not add it
html_slug = create(root, 'Embed page', '<div class="embed" data-x="1">embedded</div>')
edit(root, 'pages', html_slug, {'body': 'Plain text now'})
html_rev = [r for r in revisions('pages', html_slug) if '<div class="embed"' in raw_of(r[0])][0][0]
st, hdr, _ = post(ed, 'restore', html_rev)
check('an editor can restore', st in (302, 303) and 'restored=1' in loc(hdr), (st, loc(hdr)))
check('but raw HTML from the old version is shown as text', '<div class="embed"' not in read('pages', html_slug) and '&lt;div class="embed"' in read('pages', html_slug) and 'notice=html' in loc(hdr), read('pages', html_slug))
edit(root, 'pages', html_slug, {'body': 'Plain again'})
st, hdr, _ = post(root, 'restore', html_rev)
check('an admin gets the HTML back', '<div class="embed" data-x="1">embedded</div>' in read('pages', html_slug))

# ---- history does not break the rest
check('public pages still work', Client().get('/')[0] == 200 and Client().get('/en/about')[0] in (200, 301))
check('activity log records restores', 'content.restore' in root.get('/admin/activity-logs')[2] or 'Earlier version restored' in root.get('/admin/activity-logs')[2])

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
