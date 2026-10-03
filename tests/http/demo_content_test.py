import os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
pub = Client()

def form(client=root):
    return next((f for f in client.forms('/admin/system') if any(x[0] == 'system_action' and x[1] == 'add_demo' for x in f['fields'])), None)
def add(groups, client=root):
    f = form(client)
    token = next(x[1] for x in f['fields'] if x[0] == '_csrf') if f else next(x[1] for x in form(root)['fields'] if x[0] == '_csrf')
    return client.request('/admin/system', data=[('_csrf', token), ('system_action', 'add_demo')] + [('demo[]', g) for g in groups])

root.submit('/admin/content-types', lambda f: ['type', 'books'] in [list(x) for x in f['fields']] and ['action', 'toggle'] in [list(x) for x in f['fields']], {'enabled': '1'})
# a site with content of its own: the fixtures have an About page
open('app/content/pages/about.md', 'w', encoding='utf-8').write('---\ntitle: My own About\nstatus: published\nvisible: true\n---\n\nMine.\n')
st, _, html = root.get('/admin/system')
f = form()
check('System has a Demo content card with a group for each part of the demo', st == 200 and f is not None and 'Demo content' in html, st)
values = re.findall(r'name="demo\[\]" value="([a-z]+)"', html)
check('the groups: pages, posts, projects, books, taxonomies, pictures and files', values == ['pages', 'posts', 'projects', 'books', 'taxonomies', 'media'] or set(values) >= {'pages', 'books', 'taxonomies', 'media'}, values)
check('the menus are not offered, and a question is asked first', 'value="menus"' not in html and 'data-confirm="Add the files' in html)
check('what the site has is said: the groups say how many are new', re.search(r'Books · \d+ new', html) is not None)

before = open('app/content/pages/about.md', encoding='utf-8').read()
st, hdr, _ = add(['books', 'taxonomies', 'media'])
loc = hdr.get('Location') or ''
check('adding goes back to System and says how many', st == 302 and 'demo=ok' in loc and re.search(r'demo_added=(\d+)', loc) and int(re.search(r'demo_added=(\d+)', loc).group(1)) > 10, loc)
st, _, html = root.get(loc)
check('the screen says what was added', 'Added ' in html and 'Nothing that was there was changed' in html)
check('the books are in the site, with their covers and the taxonomies', os.path.isfile('app/content/books/dracula.md') and os.path.isfile('app/content/taxonomies/genre.yaml') and os.path.isfile('app/content/taxonomies/project-types.yaml') and os.path.isfile('app/public/uploads/media/d4f7b0a23e6c8459.png') and os.path.isfile('app/content/media/d4f7b0a23e6c8459.yaml'))
check('pages that were not chosen were not added', not os.path.isfile('app/content/pages/privacy-policy.md'))
check('the pages of the site are as they were', open('app/content/pages/about.md', encoding='utf-8').read() == before)
check('a book of the demo has its page (the Books type is on)', pub.get('/books/dracula')[0] == 200)
st, _, html = pub.get('/genre/gothic')
check('a taxonomy added by the demo has its pages, listing the books', st == 200 and 'Δράκουλας' in html and 'Φρανκενστάιν' in html, st)
st, _, html = root.get('/admin/activity-logs')
check('the activity log has it', 'system.demo_content' in html or 'Demo content added' in html)
st, _, html = root.get('/admin/system')
check('the groups added say they are there', re.search(r'name="demo\[\]" value="books" disabled', html) is not None or 'Books · there' in html)

st, hdr, _ = add(['books', 'taxonomies', 'media'])
check('doing it again adds nothing and says so', 'demo_added=0' in (hdr.get('Location') or '') and 'Nothing to add' in root.get(hdr.get('Location'))[2])
st, hdr, _ = add(['pages', 'posts', 'projects'])
check('the rest of the demo: pages the site lacks are added, its own About stays', int(re.search(r'demo_added=(\d+)', hdr.get('Location') or '').group(1)) > 5 and os.path.isfile('app/content/pages/privacy-policy.md') and open('app/content/pages/about.md', encoding='utf-8').read() == before, hdr.get('Location'))
check('and the site shows them', pub.get('/privacy-policy')[0] == 200 and pub.get('/projects/alpha-office-fitout')[0] == 200)
st, hdr, _ = add([])
check('nothing chosen adds nothing', 'demo_added=0' in (hdr.get('Location') or ''))

# who may
st, _, html = ed.get('/admin/system')
check('an editor does not reach System', st in (302, 403) and 'add_demo' not in html, st)
tok = next(x[1] for x in form(root)['fields'] if x[0] == '_csrf')
os.rename('app/content/books/dracula.md', 'app/content/books/dracula.moved')
st, _, _ = ed.request('/admin/system', data=[('_csrf', tok), ('system_action', 'add_demo'), ('demo[]', 'books')])
check('nor can an editor ask for it', not os.path.isfile('app/content/books/dracula.md'), st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
