import re, sys, sqlite3, urllib.parse, os
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

db = lambda: sqlite3.connect('app/storage/db/app.sqlite')
def loc(h): return h.get('Location') or ''
def read(path): return open('app/content/' + path, encoding='utf-8').read()
def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def redirect_id(source):
    r = db().execute('select id from redirects where source=?', (source,)).fetchone()
    return r[0] if r else None

root = Client(); root.login()
for u, r in (('ed1', 'editor'), ('usr1', 'user')):
    root.submit('/admin/users-edit', has_field('username'), {'username': u, 'email': u + '@example.test', 'display_name': u, 'role': r, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed, usr = Client(), Client(); ed.login('ed1', 'Sturdy-pass-99'); usr.login('usr1', 'Sturdy-pass-99')
pub = Client()
host = BASE.split('//')[1]   # host:port of the test server

def page(slug, title, body, lang='el', extra=''):
    name = slug + ('' if lang == 'el' else '.' + lang) + '.md'
    write('pages/' + name, "---\ntitle: '%s'\nstatus: published\nvisible: true\n%s---\n\n%s\n" % (title, extra, body))

def rename(slug, new, lang='el'):
    st, hdr, _ = root.submit(f'/admin/edit?type=pages&slug={slug}&lang={lang}', has_field('body'), {'slug': new})
    return st, loc(hdr)

# ---- pages that link to one another
page('team', 'Our team', 'Meet us.')
page('team', 'Our team', 'Meet us.', lang='en')
page('linker', 'Linker', 'Read [about the team](/team), or [again](/team#top), the [English page](/en/team), and [full](http://%s/team) address.\n\nBut not [the teams](/team-photos) or [a child](/team/x).' % host,
     extra="blocks:\n  - type: cta\n    actions:\n      - label: Go\n        url: /team\n")
page('other', 'Other', 'Unrelated [link](/careers) here.')
page('team-photos', 'Team photos', 'Photos.')
original_linker = read('pages/linker.md')

# ---- changing the address
st, l = rename('team', 'our-team')
check('address changed', st == 302 and 'address=changed' in l, l)
st, _, html = root.get('/admin/edit?type=pages&slug=our-team&lang=el&saved=1&address=changed')
check('the editor is told about links that still use the old address', re.search(r'(\d+) links? in other content still', html) is not None, re.findall(r'\d+ links? in other[^<]*', html))
n = int(re.search(r'(\d+) links? in other content', html).group(1))
check('the count is right (three in the body, one in a block)', n == 4, n)
check('with a way to review them', 'admin/links?ids=' in html and 'Review and update' in html)
rid = redirect_id('team')
check('the redirect exists', rid is not None)
st, _, html = pub.get('/team'); check('the old links still work through the redirect', st == 200 or st in (301, 302), st)
before = read('pages/linker.md')
check('nothing changed in the content yet', before == original_linker)

# ---- review screen
st, _, html = root.get(f'/admin/links?ids={rid}')
check('review screen lists the entry and the addresses', st == 200 and 'Linker' in html and '/team' in html and '/our-team' in html, st)
check('it does not list unrelated entries', 'Other' not in html.split('Addresses')[1].split('Update')[0] or 'Unrelated' not in html)
check('it offers to update them', 'Update 4 links' in html, re.findall(r'Update \d+ links?', html))
st, _, html = root.get(f'/admin/links?ids=999999'); check('an unknown redirect shows nothing to do', st == 200 and 'nothing to update' in html.lower())

# ---- redirects screen shows the count
st, _, html = root.get('/admin/redirects')
check('redirects screen has a Links column with the count', '>Links<' in html and '4 links' in html and f'admin/links?ids={rid}' in html, re.findall(r'\d+ links?', html))

# ---- who may update
st, _, _ = usr.get(f'/admin/links?ids={rid}'); check('a basic user cannot open it', st in (302, 403), st)
st, _, _ = usr.request('/admin/links', data=[('_csrf', token(usr)), ('ids', str(rid))]); check('nor update', st in (302, 403), st)
check('nothing changed after the refusals', read('pages/linker.md') == original_linker)
st, _, html = ed.get(f'/admin/links?ids={rid}'); check('an editor can open it', st == 200 and 'Update 4 links' in html, st)
st, _, html = ed.get('/admin/redirects'); check('but not the redirects screen', st in (302, 403), st)

# ---- update
st, hdr, _ = root.request('/admin/links', data=[('_csrf', token(root)), ('ids', str(rid))])
check('update redirects back with a summary', st == 302 and 'done=1-4-0' in loc(hdr), loc(hdr))
after = read('pages/linker.md')
check('links now use the new address', after.count('/our-team') == 4 and '(/team)' not in after and '/team#top' not in after, after)
check('anchors are kept', '(/our-team#top)' in after)
check('the full address keeps its host', 'http://%s/our-team)' % host in after)
check('the block link was updated', 'url: /our-team' in after)
check('the link to the other language is left for its own redirect', '(/en/team)' in after)
check('longer addresses are left alone', '(/team-photos)' in after and '(/team/x)' in after)
check('unrelated content is untouched', read('pages/other.md').count('/careers') == 1)
st, _, html = root.get(f'/admin/links?ids={rid}&done=1-4-0'); check('the screen confirms and nothing is left', '4 links updated in 1 entry' in html and 'No text or block links' in html, re.findall(r'\d+ links? updated[^<]*', html))
st, _, html = root.get('/admin/redirects'); check('redirects screen now shows none', '4 links' not in html)
st, _, html = root.get('/admin/edit?type=pages&slug=linker&lang=el')
check('the entry\'s history has the update', 'Links updated' in html, st)
rows = db().execute("select action from content_revisions where type='pages' and slug='linker' order by id").fetchall()
check('the earlier text was kept before the update', [r[0] for r in rows] == ['baseline', 'links'], rows)
st, _, html = root.get('/admin/revisions?action=links'); check('the history filter knows the action', 'linker' in html.lower())
st, _, html = root.get('/admin/activity-logs'); check('the update is in the activity log', 'links_updated' in html or 'Links to changed addresses updated' in html)
st, _, html = root.get('/our-team'); check('pages still render', st == 200)
st, _, html = pub.get('/linker'); check('the updated page renders with new links', st == 200 and 'href="/our-team"' in html or 'href="/our-team#top"' in html, st)

# ---- the English translation moved together, so it has its own redirect
en_id = redirect_id('en/team')
check('the other language has its own redirect', en_id is not None)
st, _, html = root.get(f'/admin/links?ids={en_id}'); check('and it finds the link in that language', st == 200 and '/en/team' in html, st)
root.request('/admin/links', data=[('_csrf', token(root)), ('ids', str(en_id))])
check('English link updated', '(/en/our-team)' in read('pages/linker.md'))

# ---- temporary and switched-off redirects are not used
root.submit('/admin/redirects', has_field('source'), {'source': '/temp-old', 'target': '/careers', 'code': '302'})
page('linker2', 'Linker 2', 'See [x](/temp-old) and [y](/gone-old).')
tid = redirect_id('temp-old')
st, _, html = root.get(f'/admin/links?ids={tid}'); check('a temporary redirect is not offered', 'nothing to update' in html.lower(), st)
root.request('/admin/links', data=[('_csrf', token(root)), ('ids', str(tid))])
check('and nothing was changed', '(/temp-old)' in read('pages/linker2.md'))
root.submit('/admin/redirects', has_field('source'), {'source': '/gone-old', 'target': '/careers', 'code': '301'})
gid = redirect_id('gone-old')
root.request('/admin/redirects', data=[('_csrf', token(root)), ('do', 'toggle'), ('id', str(gid))])
st, _, html = root.get(f'/admin/links?ids={gid}'); check('a redirect that is switched off is not offered', 'nothing to update' in html.lower())
root.request('/admin/redirects', data=[('_csrf', token(root)), ('do', 'toggle'), ('id', str(gid))])
check('turned back on it is offered', 'Update 1 link' in root.get(f'/admin/links?ids={gid}')[2])

# ---- a redirect by hand to another website, and a chain
root.submit('/admin/redirects', has_field('source'), {'source': '/partner', 'target': 'https://example.org/partner', 'code': '301'})
page('linker3', 'Linker 3', 'See [p](/partner) and [c](/chain-a).')
pid = redirect_id('partner')
root.submit('/admin/redirects', has_field('source'), {'source': '/chain-a', 'target': '/chain-b', 'code': '301'})
root.submit('/admin/redirects', has_field('source'), {'source': '/chain-b', 'target': '/careers', 'code': '301'})
cid = redirect_id('chain-a')
root.request('/admin/links', data=[('_csrf', token(root)), ('ids', f'{pid},{cid}')])
text = read('pages/linker3.md')
check('a link can go to another website', '(https://example.org/partner)' in text, text)
check('a chain is followed to its end', '(/careers)' in text, text)

# ---- categories and tags
page('taxlink', 'Tax link', 'Read about [strategy](/tag/strategy) and [in English](/en/tag/strategy).')
rows = []
f = next(f for f in root.forms('/admin/taxonomies?taxonomy=tags') if any(x[0] == 'taxonomy_title' for x in f['fields']))
ids = [v for k, v in f['fields'] if k == 'term_id[]']; slugs = [v for k, v in f['fields'] if k == 'term_slug[]']
els = [v for k, v in f['fields'] if k == 'term_label[el][]']; ens = [v for k, v in f['fields'] if k == 'term_label[en][]']
slugs = ['stratigiki' if i == 'strategy' else s for i, s in zip(ids, slugs)]
root.submit('/admin/taxonomies?taxonomy=tags', lambda f: any(x[0] == 'taxonomy_title' for x in f['fields']), {'term_id[]': ids, 'term_slug[]': slugs, 'term_label[el][]': els, 'term_label[en][]': ens})
sid = redirect_id('tag/strategy')
st, _, html = root.get('/admin/redirects'); check('links to a renamed term are counted too', '1 link' in html, re.findall(r'\d+ links?', html))
root.request('/admin/links', data=[('_csrf', token(root)), ('ids', str(sid))])
check('and updated', '(/tag/stratigiki)' in read('pages/taxlink.md') and '(/en/tag/strategy)' in read('pages/taxlink.md'))
root.request('/admin/links', data=[('_csrf', token(root)), ('ids', str(redirect_id('en/tag/strategy')))])
check('in the other language too', '(/en/tag/stratigiki)' in read('pages/taxlink.md'))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
