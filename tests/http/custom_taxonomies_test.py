import json, re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

db = lambda: sqlite3.connect('app/storage/db/app.sqlite')
def loc(h): return h.get('Location') or ''

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
root.submit('/admin/users-edit', has_field('username'), {'username': 'usr1', 'email': 'usr1@example.test', 'display_name': 'usr1', 'role': 'user', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed, usr = Client(), Client(); ed.login('ed1', 'Sturdy-pass-99'); usr.login('usr1', 'Sturdy-pass-99')
pub = Client()

def new_form(client=root):
    return next(f for f in client.forms('/admin/taxonomies') if any(x[0] == 'new_taxonomy' for x in f['fields']))
def create(title, name='', types=(), client=root, token=None):
    token = token or next(x[1] for x in new_form(client)['fields'] if x[0] == '_csrf')
    data = [('_csrf', token), ('taxonomy', 'tags'), ('new_taxonomy', '1'), ('new_title', title), ('new_name', name)] + [('new_types[]', t) for t in types]
    return client.request('/admin/taxonomies', data=data)
def is_tax_form(f): return any(x[0] == 'taxonomy_title' for x in f['fields'])
def save_terms(taxonomy, rows):
    """rows: (id, slug, el, en)"""
    return root.submit('/admin/taxonomies?taxonomy=' + taxonomy, is_tax_form, {
        'term_id[]': [r[0] for r in rows], 'term_slug[]': [r[1] for r in rows], 'term_label[el][]': [r[2] for r in rows], 'term_label[en][]': [r[3] for r in rows],
        'term_description[el][]': ['' for r in rows], 'term_description[en][]': ['' for r in rows]})
def entry(path, text):
    open('app/content/' + path, 'w').write(text)

# ---- the screen offers it
st, _, html = root.get('/admin/taxonomies')
check('the Taxonomies screen has a New taxonomy form: a name, an address, the content types its pages list', st == 200 and 'data-new-taxonomy' in html and 'name="new_title"' in html and 'name="new_name"' in html and 'name="new_types[]" value="projects"' in html and 'name="new_types[]" value="posts"' in html and 'name="new_types[]" value="pages"' not in html, st)
check('closed until asked for', ' open data-new-taxonomy' not in html and 'bg-white" data-new-taxonomy>' in html)

# ---- making one
st, hdr, _ = create('Project types', '', ['projects'])
check('it is made and opened', st == 302 and 'taxonomy=project-types' in loc(hdr) and 'created=1' in loc(hdr), loc(hdr))
st, _, html = root.get(loc(hdr))
check('the screen says so, and shows where its pages will be', 'Taxonomy created' in html and '/project-types/' in html and 'Project types' in html, st)
check('it is a tab like the others', re.search(r'href="[^"]*taxonomy=project-types"[^>]*aria-current="page"', html) is not None)
check('it is in the site\'s files, with the content types it lists', open('app/content/taxonomies/project-types.yaml').read().count('projects') == 1)
st, _, html = root.get('/admin/theme?tab=archive_layouts')
check('and has a card in Archive Layouts with the content type chosen', 'Project types' in html and 'archive_taxonomies[project-types][types][]' in html, st)
st, _, html = root.get('/admin/activity-logs')
check('the activity log has it', 'taxonomies.create' in html or 'Taxonomy created' in html)

# ---- what is not accepted
def refused(title, name='', client=root):
    st, hdr, _ = create(title, name, [], client)
    if st != 302 or 'new_error' not in loc(hdr):
        return None
    return root.get(loc(hdr))[2]
for name, why in [('posts', 'a content type'), ('admin', 'a reserved word'), ('en', 'a language'), ('tags', 'a taxonomy'), ('index', 'the home page'), ('project-types', 'a taxonomy that exists')]:
    html = refused('Something', name)
    check('an address that is %s is refused, with the form open and the reason said' % why, html is not None and ' open data-new-taxonomy' in html and 'already used by the site' in html, name)
html = refused('Something', 'x')
check('a one-letter address is refused', html is not None and '2 to 40' in html)
html = refused('')
check('and a missing name', html is not None and 'Give the taxonomy a name' in html)
html = refused('!!!')
check('a name that cannot make an address says so', html is not None and 'Type one with Latin letters' in html)
entry('pages/showcase.md', '---\ntitle: Showcase\nstatus: published\n---\nBody\n')
html = refused('Showcase')
check('an address a page has is refused', html is not None and 'There is a page at /showcase' in html, None)
check('nothing was made by any of them', sorted(__import__('os').listdir('app/content/taxonomies')) == ['categories.yaml', 'project-types.yaml', 'tags.yaml'], __import__('os').listdir('app/content/taxonomies'))
st, hdr, _ = create('Industries', '', [], ed)
check('an editor may make one (it is part of taxonomies.manage)', st == 302 and 'taxonomy=industries' in loc(hdr) and 'new_error' not in loc(hdr), loc(hdr))
token = next(x[1] for x in new_form(root)['fields'] if x[0] == '_csrf')
st, _, _ = create('Nope', 'nope', [], usr, token)
check('a person with no rights may not', not __import__('os').path.exists('app/content/taxonomies/nope.yaml'), st)

# ---- a content type cannot take the name of a taxonomy
form = next(f for f in root.forms('/admin/content-types') if any(x[0] == 'name' for x in f['fields']) or any(x[0] == 'action' and x[1] == 'create' for x in f['fields']))
token = next(x[1] for x in form['fields'] if x[0] == '_csrf')
st, hdr, _ = root.request('/admin/content-types', data=[('_csrf', token), ('action', 'create'), ('name', 'project-types'), ('label', 'Project types')])
check('a content type cannot have the name of a taxonomy', 'error=name' in loc(hdr), loc(hdr))

# ---- terms, and the entries filed under them
save_terms('project-types', [('web', 'web', 'Ιστοσελίδες', 'Websites'), ('brand', 'brand', 'Branding', 'Branding')])
entry('projects/alpha.md', '---\ntitle: Alpha Project\nstatus: published\nvisible: true\ndate: 1762732800\nproject-types:\n  - web\n---\nBody\n')
entry('projects/alpha.en.md', '---\ntitle: Alpha Project EN\nstatus: published\nvisible: true\ndate: 1762732800\nproject-types:\n  - web\n---\nBody\n')
entry('projects/beta.md', '---\ntitle: Beta Project\nstatus: published\nvisible: true\ndate: 1762732900\nproject-types:\n  - brand\n---\nBody\n')
entry('posts/gamma-post.md', '---\ntitle: Gamma Post\nstatus: published\nvisible: true\ndate: 1762732800\nproject-types:\n  - web\n---\nBody\n')
entry('posts/gamma-post.en.md', '---\ntitle: Gamma Post EN\nstatus: published\nvisible: true\ndate: 1762732800\nproject-types:\n  - web\n---\nBody\n')
st, _, html = pub.get('/project-types/web')
check('a term has a page at /name/term', st == 200 and 'Alpha Project' in html, st)
check('with the title of the taxonomy and the name of the term', 'Project types: Ιστοσελίδες' in html, re.findall(r'<h1[^>]*>.*?</h1>', html, re.S)[:1])
check('listing only the content types chosen for it', 'Gamma Post' not in html and 'Beta Project' not in html, None)
st, _, html = pub.get('/en/project-types/web')
check('in another language too', st == 200 and 'Alpha Project EN' in html and 'Gamma Post EN' not in html, st)
check('the page names its languages for search engines', 'hreflang="en"' in html and '/project-types/web' in html)
check('and is its own canonical address', 'rel="canonical" href="' in html and '/en/project-types/web"' in html)
check('a term that does not exist is a 404', pub.get('/project-types/nope')[0] == 404)
check('and so is the address with no term, until a page is made there', pub.get('/project-types')[0] == 404)
entry('pages/project-types.md', '---\ntitle: Our work by kind\nstatus: published\n---\nBody\n')
st, _, html = pub.get('/project-types')
check('a page made at that address is served', st == 200 and 'Our work by kind' in html, st)
check('and the terms still work', pub.get('/project-types/web')[0] == 200)
st, _, html = pub.get('/projects/alpha')
check('the entry shows its terms, linked to their pages', st == 200 and 'Project types:' in html and re.search(r'href="[^"]*/project-types/web"', html) and 'Ιστοσελίδες' in html, re.findall(r'Project types:.{0,200}', html, re.S)[:1])
st, _, html = pub.get('/en/projects/alpha')
check('in the language of the page', re.search(r'href="[^"]*/en/project-types/web"', html) and 'Websites' in html, None)
check('the posts and tags keep their own addresses', pub.get('/tag/strategy')[0] == 200 and pub.get('/category/insights')[0] in (200, 404))

# ---- the pages can list more, or other types
root.submit('/admin/theme?tab=archive_layouts', lambda f: any(x[0] == 'active_tab' for x in f['fields']),
            {'archive_taxonomies[project-types][types][]': ['projects', 'posts'], 'active_tab': 'archive_layouts'}, drop=['active_tab'])
st, _, html = pub.get('/project-types/web')
check('a card of Archive Layouts decides which types it lists', 'Alpha Project' in html and 'Gamma Post' in html, st)

# ---- changing the address of a term
before = root.get('/admin/taxonomies?taxonomy=project-types')[2]
st, hdr, _ = save_terms('project-types', [('web', 'websites', 'Ιστοσελίδες', 'Websites'), ('brand', 'brand', 'Branding', 'Branding')])
check('a term whose address changed is saved', 'moved=1' in loc(hdr), loc(hdr))
st, hdr, _ = pub.request('/project-types/web')
check('the old address leads to the new one', st == 301 and loc(hdr).endswith('/project-types/websites'), (st, loc(hdr)))
st, hdr, _ = pub.request('/en/project-types/web')
check('in every language', st == 301 and loc(hdr).endswith('/en/project-types/websites'), (st, loc(hdr)))
check('and the new one works', pub.get('/project-types/websites')[0] == 200)

# ---- a menu can link to a term
st, _, html = root.get('/admin/menus-edit?key=main')
data = json.loads(re.search(r'id="menu-editor-data">(.*?)</script>', html, re.S).group(1))
groups = {g['id']: g for g in data['groups']} if 'groups' in data else {}
flat = json.dumps(data)
check('the menu editor offers the terms of the new taxonomy, by their address', 'project-types/websites' in flat and 'taxonomy-project-types' in flat, None)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
