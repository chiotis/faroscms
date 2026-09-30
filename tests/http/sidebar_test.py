import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')

def sidebar(html):
    m = re.search(r'<aside.*?</aside>', html, re.S)
    return m.group(0) if m else ''
def groups(side):
    """Section labels in order, with the entries under each: {label: [names]}"""
    out, current = {}, None
    for m in re.finditer(r'<p class="px-2 pb-1\.5[^>]*>([^<]+)</p>|<a href="([^"]*)"[^>]*>(.*?)</a>', side, re.S):
        if m.group(1):
            current = m.group(1).strip(); out[current] = []
        elif current is not None:
            name = re.sub(r'<[^>]+>', '', m.group(3)).strip()
            if name: out[current].append((name, m.group(2).split('/admin')[-1]))
    return out

st, _, html = root.get('/admin')
side = sidebar(html); g = groups(side)
check('the sidebar has Overview, Content, Manage and System sections in that order', list(g)[:4] == ['Overview', 'Content', 'Manage', 'System'], list(g))
check('Content is a heading, not a link to everything', not re.search(r'href="[^"]*admin/content"[^>]*>\s*<svg.*?</svg>\s*Content\s*</a>', side, re.S))
names = [n for n, _ in g['Content']]
check('under Content: Pages, Posts, Projects, then Media', names == ['Pages', 'Posts', 'Projects', 'Media'], g['Content'])
check('each content type is a menu item with its icon', all(('?type=' + t) in dict((n, u) for n, u in g['Content'])[n.title()] for n, t in (('Pages', 'pages'), ('Posts', 'posts'), ('Projects', 'projects'))) and side.count('<svg') >= 10, g['Content'])
check('Media moved out of Manage', 'Media' not in [n for n, _ in g['Manage']], g['Manage'])
check('Manage keeps Forms, Menus, Taxonomies', all(x in [n for n, _ in g['Manage']] for x in ('Forms', 'Menus', 'Taxonomies')), g['Manage'])
check('the small indented list of types is gone', 'pl-6' not in side)

for path, active in (('/admin/content?type=posts', 'Posts'), ('/admin/content?type=projects', 'Projects'), ('/admin/media', 'Media'), ('/admin/edit?type=pages&slug=about&lang=en', 'Pages')):
    st, _, html = root.get(path)
    cur = re.findall(r'<a href="[^"]*"[^>]*aria-current="page"[^>]*>(.*?)</a>', sidebar(html), re.S)
    cur = [re.sub(r'<[^>]+>', '', c).strip() for c in cur]
    check('%s: only %s is current' % (path, active), st == 200 and cur == [active], (st, cur))

st, _, html = ed.get('/admin')
g = groups(sidebar(html))
check('an editor sees Content with the types and Media, and no Forms or Menus', [n for n, _ in g.get('Content', [])] == ['Pages', 'Posts', 'Projects', 'Media'] and 'Forms' not in [n for n, _ in g.get('Manage', [])], g)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
