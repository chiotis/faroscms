import re, sys, sqlite3, urllib.parse, os
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

db = lambda: sqlite3.connect('app/storage/db/app.sqlite')
def loc(h): return h.get('Location') or ''
def exists(path): return os.path.exists('app/content/' + path)
def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def rd(source):
    r = db().execute('select target,status_code,origin,note,content_type,enabled from redirects where source=?', (source,)).fetchone()
    return r

root = Client(); root.login()
for u, r in (('ed1', 'editor'), ('usr1', 'user')):
    root.submit('/admin/users-edit', has_field('username'), {'username': u, 'email': u + '@example.test', 'display_name': u, 'role': r, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed, usr = Client(), Client(); ed.login('ed1', 'Sturdy-pass-99'); usr.login('usr1', 'Sturdy-pass-99')
pub = Client()

def make(type_, slug, title, lang='el', status='published', body='Text', extra=''):
    name = slug + ('' if lang == 'el' else '.' + lang) + '.md'
    write(f'{type_}/{name}', "---\ntitle: '%s'\nstatus: %s\nvisible: true\ndate: '2026-01-01'\n%s---\n\n%s\n" % (title, status, extra, body))
def delete(client, type_, slug, lang='el', **fields):
    data = [('_csrf', token(client)), ('type', type_), ('slug', slug), ('lang', lang)] + list(fields.items())
    return client.request('/admin/delete', data=data)

make('pages', 'old-service', 'Old service')
make('pages', 'linker', 'Linker', body='See [the service](/old-service).')
make('pages', 'draft-one', 'Draft one', status='draft')
make('posts', 'old-news', 'Old news')
make('posts', 'older-news', 'Older news')
make('pages', 'english-only', 'English only', lang='en')

# ---- the confirmation page
st, _, html = root.get('/admin/delete?type=pages&slug=old-service&lang=el')
check('a public page gets a confirmation page', st == 200 and 'Where should visitors of /old-service go?' in html, st)
check('with the options', all(x in html for x in ('value="none"', 'value="home"', 'value="custom"', 'name="after_target"')))
check('a page has no list to send visitors to', 'value="archive"' not in html)
check('a page is not redirected by default', re.search(r'value="none"[^>]*checked', html) is not None)
check('it warns about links that will break', '1 link in other content point' in html or '1 link in other content points' in html, re.findall(r'\d+ links? in other[^<]*', html))
check('it lists pages to choose from', '<option value="/linker">' in html and '<option value="/old-service">' not in html)
st, _, html = root.get('/admin/delete?type=posts&slug=old-news&lang=el')
check('a post can go to its list', 'value="archive"' in html and '/posts' in html and re.search(r'value="archive"[^>]*checked', html) is not None)
st, _, html = root.get('/admin/delete?type=pages&slug=draft-one&lang=el')
check('a draft has nothing to ask', st == 200 and 'not public' in html and 'name="after"' not in html, st)
st, hdr, _ = root.get('/admin/delete?type=pages&slug=index&lang=el'); check('the home page cannot be deleted', st == 302 and 'admin/content' in loc(hdr), (st, loc(hdr)))
st, hdr, _ = root.get('/admin/delete?type=pages&slug=nothing-here&lang=el'); check('nothing to delete goes back to the list', st == 302, st)
st, _, html = root.get('/admin/content?type=pages&lang=el')
check('the list links a public page to the confirmation', 'admin/delete?type=pages&amp;slug=old-service&amp;lang=el' in html or 'admin/delete?type=pages&slug=old-service&lang=el' in html)
check('and a draft keeps the simple confirmation', 'id="delete-draft-one"' in html)
st, _, html = root.get('/admin/edit?type=pages&slug=old-service&lang=el')
check('the editor links to it too', 'admin/delete?type=pages&amp;slug=old-service&amp;lang=el' in html or 'admin/delete?type=pages&slug=old-service&lang=el' in html)
st, _, html = root.get('/admin/edit?type=pages&slug=draft-one&lang=el'); check('a draft is deleted from the editor as before', 'formaction="' in html and 'admin/delete' in html)

# ---- the choices
conn = db(); conn.execute("insert or ignore into redirects (source,target,status_code,origin,enabled,created_at,updated_at) values ('older-service','/old-service',301,'manual',1,'2026-01-01T00:00:00+00:00','2026-01-01T00:00:00+00:00')"); conn.commit()
st, hdr, _ = delete(root, 'pages', 'old-service', after='custom', after_target='/linker')
check('deleting with a redirect goes back to the list', st == 302 and 'deleted=1' in loc(hdr) and 'to=' in loc(hdr), loc(hdr))
check('the file is gone', not exists('pages/old-service.md'))
r = rd('old-service')
check('a permanent automatic redirect was added', r is not None and r[0] == '/linker' and r[1] == 301 and r[2] == 'auto' and r[3] == 'Deleted' and r[4] == 'pages' and r[5] == 1, r)
st, h, _ = pub.get('/old-service'); check('visitors are sent to the new place', st == 301 and loc(h).endswith('/linker'), (st, loc(h)))
r = rd('older-service'); check('a redirect that led there now goes straight to the new place', r is not None and r[0] == '/linker', r)
st, _, html = root.get(loc(hdr).replace(BASE, '')); check('the list says where visitors go', 'Visitors who ask for /old-service are sent to /linker' in html, re.findall(r'Deleted[^<]*', html))
rows = db().execute("select action from content_revisions where type='pages' and slug='old-service' order by id").fetchall()
check('the text is kept in the history', [x[0] for x in rows][-1] == 'delete', rows)
st, _, html = root.get('/admin/activity-logs'); check('the activity log records it', 'content.delete' in html or 'Content deleted' in html)

# a post to its list
st, hdr, _ = delete(root, 'posts', 'old-news', after='archive')
r = rd('posts/old-news'); check('a post can be sent to its list', r is not None and r[0] == '/posts', r)
st, h, _ = pub.get('/posts/old-news'); check('and visitors follow it', st == 301 and loc(h).endswith('/posts'), (st, loc(h)))
# the home page, in the other language
make('pages', 'about-en', 'About EN', lang='en')
st, hdr, _ = delete(root, 'pages', 'about-en', lang='en', after='home')
r = rd('en/about-en'); check('home in another language means that language\'s home', r is not None and r[0] == '/en', r)
# another website
make('pages', 'partner-page', 'Partner page')
st, hdr, _ = delete(root, 'pages', 'partner-page', after='custom', after_target='https://example.org/partner')
r = rd('partner-page'); check('visitors can be sent to another website', r is not None and r[0] == 'https://example.org/partner', r)
# this site's own address typed in full
make('pages', 'full-address', 'Full address')
st, hdr, _ = delete(root, 'pages', 'full-address', after='custom', after_target=BASE + '/linker?x=1#top')
r = rd('full-address'); check('a full address of this site becomes a path', r is not None and r[0] == '/linker?x=1#top', r)

# ---- bad choices delete nothing
make('pages', 'keep-me', 'Keep me')
for label, fields, want in (
    ('an address that does not exist', {'after': 'custom', 'after_target': '/does-not-exist'}, 'Nothing exists at that address'),
    ('an empty address', {'after': 'custom', 'after_target': ''}, 'Choose where visitors should go'),
    ('a script address', {'after': 'custom', 'after_target': 'javascript:alert(1)'}, 'Use an address on this site'),
    ('the address being deleted', {'after': 'custom', 'after_target': '/keep-me'}, 'That is the address being deleted'),
    ('a relative address', {'after': 'custom', 'after_target': 'linker'}, 'Start an address on this site with a slash'),
    ('the list of a page', {'after': 'archive'}, 'Choose where visitors should go'),
):
    st, hdr, html = delete(root, 'pages', 'keep-me', **fields)
    check(f'{label} is refused with a message', st == 200 and want in html and exists('pages/keep-me.md') and rd('keep-me') is None, (st, want in html, exists('pages/keep-me.md')))
check('the choice is kept on the page after an error', 'name="after_target"' in html)
conn = db(); conn.execute("insert or ignore into redirects (source,target,status_code,origin,enabled,created_at,updated_at) values ('circle','/keep-me',301,'manual',1,'2026-01-01T00:00:00+00:00','2026-01-01T00:00:00+00:00')"); conn.commit()
st, hdr, html = delete(root, 'pages', 'keep-me', after='custom', after_target='/circle')
check('a redirect that would go in a circle is refused', st == 200 and 'in a circle' in html and exists('pages/keep-me.md'), st)

# ---- without a choice nothing is redirected
st, hdr, _ = delete(root, 'pages', 'keep-me')
check('deleting without a choice works as before', st == 302 and 'deleted=1' in loc(hdr) and 'gone=' in loc(hdr) and not exists('pages/keep-me.md'), loc(hdr))
check('and adds no redirect', rd('keep-me') is None)
st, _, html = root.get(loc(hdr).replace(BASE, ''))
check('the list warns and offers to send visitors somewhere', 'will see a "not found" page' in html and 'admin/redirects?source=' in html and '/keep-me' in html, st)
st, _, html = pub.get('/keep-me'); check('the address is not found', st == 404)

# ---- a draft
st, hdr, _ = delete(root, 'pages', 'draft-one')
check('a draft is deleted without a warning', st == 302 and 'deleted=1' in loc(hdr) and 'gone=' not in loc(hdr) and not exists('pages/draft-one.md'), loc(hdr))
make('pages', 'draft-two', 'Draft two', status='draft')
st, hdr, _ = delete(root, 'pages', 'draft-two', after='home')
check('a redirect is never made for a draft', rd('draft-two') is None and not exists('pages/draft-two.md'))

# ---- who can choose
make('pages', 'editor-page', 'Editor page')
st, _, html = ed.get('/admin/delete?type=pages&slug=editor-page&lang=el')
check('an editor gets the confirmation without redirect choices', st == 200 and 'name="after"' not in html and 'An administrator can add a redirect' in html, st)
st, hdr, _ = delete(ed, 'pages', 'editor-page', after='custom', after_target='/linker')
check('an editor cannot add a redirect by sending the choice anyway', st == 302 and rd('editor-page') is None and not exists('pages/editor-page.md'), (st, rd('editor-page')))
make('pages', 'user-page', 'User page')
st, _, _ = usr.get('/admin/delete?type=pages&slug=user-page&lang=el'); check('a basic user cannot open it', st in (302, 403), st)
st, _, _ = delete(usr, 'pages', 'user-page'); check('nor delete', st in (302, 403) and exists('pages/user-page.md'), st)
st, _, _ = root.request('/admin/delete', data=[('type', 'pages'), ('slug', 'user-page'), ('lang', 'el')]); check('a delete without the security token is refused', st == 419 or st == 403, st)
check('and nothing was deleted', exists('pages/user-page.md'))

# ---- bringing it back removes the redirect
rev = db().execute("select id from content_revisions where type='pages' and slug='old-service' and action='delete'").fetchone()[0]
st, hdr, _ = root.request('/admin/revisions', data=[('_csrf', token(root)), ('do', 'undelete'), ('id', str(rev))])
check('the page can be restored from the history', st == 302 and exists('pages/old-service.md'), (st, loc(hdr)))
check('and its redirect is removed, since the page is back', rd('old-service') is None, rd('old-service'))
check('the page is public again', pub.get('/old-service')[0] == 200)

# ---- bulk delete
for n in (1, 2): make('pages', f'bulk-{n}', f'Bulk {n}')
make('pages', 'bulk-draft', 'Bulk draft', status='draft')
data = [('_csrf', token(root)), ('type', 'pages'), ('lang', 'el'), ('bulk_action', 'delete'), ('selected[]', 'bulk-1'), ('selected[]', 'bulk-2'), ('selected[]', 'bulk-draft')]
st, hdr, _ = root.request('/admin/content-bulk', data=data)
msg = urllib.parse.unquote_plus(loc(hdr))
check('bulk delete removes them', st == 302 and not exists('pages/bulk-1.md') and not exists('pages/bulk-draft.md') and '3 items deleted' in msg, msg)
check('and says how many were public', '2 of them were public' in msg, msg)
rows = db().execute("select slug, action from content_revisions where slug like 'bulk-%' and action='delete' order by slug").fetchall()
check('bulk delete keeps the text in the history now', [r[0] for r in rows] == ['bulk-1', 'bulk-2', 'bulk-draft'], rows)
st, _, html = root.get('/admin/revisions?view=deleted'); check('so they can be brought back', 'bulk-1' in html and 'Bulk 1' in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
