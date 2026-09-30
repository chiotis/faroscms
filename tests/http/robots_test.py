import re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def is_settings(f): return any(x[0] == 'title' for x in f['fields']) and any(x[0] == 'date_format' for x in f['fields'])
def stored():
    r = sqlite3.connect('app/storage/db/app.sqlite').execute("select value from system_meta where key='site_settings'").fetchone(); return r[0] if r else ''
def robots(c): 
    st, hdr, body = c.get('/robots.txt'); return st, hdr, body

root = Client(); root.login()
anon = Client()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')

st, hdr, body = robots(anon)
check('robots.txt is plain text', st == 200 and hdr.get('Content-Type', '').startswith('text/plain'), (st, hdr.get('Content-Type')))
check('by default it is what it always was', body == "User-agent: *\nAllow: /\nSitemap: " + BASE + "/sitemap.xml\n", body)

st, _, html = root.get('/admin/settings')
check('the settings have a Search engines section with a box for paths', 'Search engines' in html and 'name="robots_disallow"' in html)
check('that says what robots.txt is and is not', 'does not hide a page' in html and '"No index"' in html)

# ---- saving rules
root.submit('/admin/settings', is_settings, {'robots_disallow': "/private/\n/thank-you\n/*.pdf$\n"})
st, hdr, body = robots(anon)
check('the rules are in robots.txt, as Disallow lines', 'Disallow: /private/\nDisallow: /thank-you\nDisallow: /*.pdf$\n' in body, body)
check('between Allow and Sitemap', body.index('Allow: /') < body.index('Disallow: /private/') < body.index('Sitemap:'), body)
check('and the sitemap is still last', body.rstrip().splitlines()[-1].startswith('Sitemap: '))
check('they are stored as a list', re.search(r'robots_disallow:\s*\n\s*- /private/\n\s*- /thank-you', stored()) is not None, stored()[-300:])
st, _, html = root.get('/admin/settings')
check('the box shows them back', '/private/\n/thank-you\n/*.pdf$' in html.replace('\r', ''), re.findall(r'<textarea name="robots_disallow"[^>]*>[^<]*', html))

# ---- what is typed is checked
root.submit('/admin/settings', is_settings, {'robots_disallow': "Disallow: /ok\nnot-a-path\n# comment\n/with space\n/ok\n/x\nUser-agent: evil\n/y\r\nSitemap: http://evil.test/"})
st, _, body = robots(anon)
lines = body.splitlines()
check('only valid paths are kept, once, and typed rules are accepted', [l for l in lines if l.startswith('Disallow')] == ['Disallow: /ok', 'Disallow: /x', 'Disallow: /y'], body)
check('nothing typed becomes a line of its own', body.count('User-agent:') == 1 and body.count('Sitemap:') == 1 and 'evil' not in body, body)
st, _, html = root.get('/admin/settings')
check('what is shown back is what was kept', '/ok\n/x\n/y' in html.replace('\r', ''), re.findall(r'<textarea name="robots_disallow"[^>]*>[^<]*', html))

# ---- a form that does not have the box does not clear the rules
st, _, html = root.get('/admin/settings')
root.submit('/admin/settings', is_settings, {'title': 'Renamed site'}, drop=['robots_disallow'])
st, _, body = robots(anon)
check('a settings form sent without the box leaves the rules alone', 'Disallow: /ok' in body and 'Renamed site' in stored(), body)

# ---- who may
st, _, page = ed.get('/admin/settings')
check('an editor cannot open the settings, so cannot change the rules', st == 403, st)
before = robots(anon)[2]
tok = re.search(r'name="_csrf" value="([0-9a-f]+)"', ed.get('/admin')[2]).group(1)
ed.request('/admin/settings', {'_csrf': tok, 'title': 'x', 'robots_disallow': '/editor-was-here'})
check('nor by sending the field', robots(anon)[2] == before and 'editor-was-here' not in robots(anon)[2])

# ---- emptying the box removes them
root.submit('/admin/settings', is_settings, {'robots_disallow': ''})
st, _, body = robots(anon)
check('an emptied box removes the rules', 'Disallow' not in body and body.startswith('User-agent: *\nAllow: /\nSitemap: '), body)
check('and the setting is gone from the stored settings', 'robots_disallow' not in stored(), stored()[-200:])

# ---- the whole site can be closed, on purpose
root.submit('/admin/settings', is_settings, {'robots_disallow': '/'})
check('a rule of just a slash is allowed (a staging site)', 'Disallow: /\n' in robots(anon)[2])
root.submit('/admin/settings', is_settings, {'robots_disallow': ''})

# ---- other pages are unaffected
check('the sitemap and the home page still work', anon.get('/sitemap.xml')[0] == 200 and anon.get('/')[0] == 200)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
