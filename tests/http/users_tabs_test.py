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
    m = re.search(r'<aside.*?</aside>', html, re.S) or re.search(r'<nav.*?</nav>', html, re.S)
    return m.group(0) if m else html
def tabs(html):
    m = re.search(r'<nav class="[^"]*" aria-label="Users and roles">(.*?)</nav>', html, re.S)
    return m.group(1) if m else ''

st, _, html = root.get('/admin')
side = sidebar(html)
check('the sidebar has one Users & Roles entry that opens Users', re.search(r'href="[^"]*admin/users"[^>]*>\s*<svg.*?</svg>\s*Users &amp; Roles', side, re.S) is not None, st)
check('and no separate Users or Roles entries', not re.search(r'</svg>\s*Users\s*</a>', side) and not re.search(r'</svg>\s*Roles\s*</a>', side))

for path, current in (('/admin/users', 'Users'), ('/admin/roles', 'Roles')):
    st, _, html = root.get(path)
    body = tabs(html)
    check(path + ' shows the tabs', st == 200 and body != '', st)
    check('Users and Roles are the tabs', 'admin/users' in body and 'admin/roles' in body and '>Users<' in body and '>Roles<' in body)
    check('the current one is marked, once', re.search(r'class="[^"]*\bactive\b[^"]*" aria-current="page">' + current + '<', body) is not None and body.count('aria-current="page"') == 1, body)
    check('the top bar says Users & Roles and the sidebar entry is current', re.search(r'<h1[^>]*>\s*Users &amp; Roles\s*</h1>', html) is not None and 'aria-current="page"' in sidebar(html), re.findall(r'<h1[^>]*>[^<]*', html)[:2])

st, _, html = root.get('/admin/users-edit?id=1')
check('editing a person keeps the Users tab and the sidebar entry current', st == 200 and '>Users<' in tabs(html) and 'aria-current="page"' in sidebar(html), st)
st, _, html = root.get('/admin/users-edit')
check('so does adding one', st == 200 and '>Roles<' in tabs(html), st)

# an editor sees none of it, but still edits their own profile
st, _, html = ed.get('/admin')
check('an editor has no Users & Roles entry', 'Users &amp; Roles' not in html, st)
st, _, _ = ed.get('/admin/users'); check('and cannot open Users', st in (302, 403), st)
st, _, _ = ed.get('/admin/roles'); check('or Roles', st in (302, 403), st)
me = re.search(r'href="[^"]*admin/users-edit\?id=(\d+)"', ed.get('/admin')[2])
st, _, html = ed.get('/admin/users-edit?id=' + (me.group(1) if me else '2'))
check('their own profile opens without the tabs', st == 200 and tabs(html) == '', st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
