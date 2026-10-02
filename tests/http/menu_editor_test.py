import json, re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field
from roles_test_lib import set_caps  # noqa

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()

def data(html):
    m = re.search(r'<script type="application/json" id="menu-editor-data">(.*?)</script>', html, re.S)
    return json.loads(m.group(1)) if m else None
def file(key): return open('app/content/menus/%s.yaml' % key, encoding='utf-8').read()
def settings():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='site_settings'").fetchone(); c.close()
    return v[0] if v else ''
def save(client, key, rows, extra=None, title='Main Menu'):
    fields = {'menu_json': json.dumps(rows), 'menu_title': title}
    fields.update(extra or {})
    return client.submit('/admin/menus-edit?key=' + key, has_field('menu_json'), fields, button=('menu_action', 'save'))
def row(depth, el, en, url, **more):
    return dict({'depth': depth, 'labels': {'el': el, 'en': en}, 'url': url}, **more)

# ---- the screen
st, _, html = root.get('/admin/menus-edit?key=main')
d = data(html)
check('the editor opens with its data for the script', st == 200 and d is not None and 'assets/js/admin-menus.js' in html, st)
check('and the old form of rows is gone', 'menu_label_key[]' not in html and 'js-add-menu-item' not in html)
check('the data cannot close the script tag', '</script' not in re.search(r'id="menu-editor-data">(.*?)</script>', html, re.S).group(1))
check('it has the menu, its languages and the default one', d['key'] == 'main' and [l['code'] for l in d['languages']] == ['el', 'en'] and d['default_language'] == 'el', d and d['languages'])
check('the items come as rows with depths', len(d['items']) >= 5 and d['items'][0]['depth'] == 1 and any(i['depth'] == 2 for i in d['items']), [i['depth'] for i in d['items']])
groups = {g['id']: g for g in d['groups']}
check('it offers the pages, the posts, the projects, the lists and the categories', all(k in groups for k in ('pages', 'posts', 'projects', 'lists')) and any(k.startswith('taxonomy-') for k in groups), list(groups))
about = next((i for i in groups['pages']['items'] if i['url'] == 'about'), None)
check('a page is offered once with its title in each language', about is not None and about['labels'].get('en') and about['labels'].get('el') and sum(1 for i in groups['pages']['items'] if i['url'] == 'about') == 1, about)
check('the home page is among the lists', any(i['url'] == '' for i in groups['lists']['items']))
check('the places of the theme are there, and this person may place menus', {p['key'] for p in d['locations']} >= {'header', 'footer'} and d['can_place'] is True, d['locations'])
check('the bar to save has the delete button and the note for unsaved changes', 'data-menu-delete' in html and 'data-dirty-note' in html)
for name in ('data-source-groups', 'data-menu-list', 'data-lang-switch', 'data-menu-preview', 'data-menu-locations', 'data-undo', 'data-redo', 'data-drop-line'):
    check('the page has the part ' + name, name in html)

# ---- saving
before = file('main')
st, hdrs, _ = save(root, 'main', [row(1, 'Αρχική', 'Home', '/'), row(1, 'Σχετικά', 'About', 'about', **{'class': 'nav-cta', 'target': '_blank'}),
                                  row(1, 'Εταιρεία', 'Company', '', hidden=True), row(2, 'Κρυφό παιδί', 'Hidden child', 'careers'), row(1, 'Καριέρα', 'Careers', 'careers')])
check('saving goes back to the editor', st in (302, 303) and 'saved=1' in (hdrs.get('Location') or ''), (st, hdrs.get('Location')))
text = file('main')
check('the menu file is rewritten, keeping only what is set', 'hidden: true' in text and 'class: nav-cta' in text and 'target: _blank' in text and "label: ''" not in text, text[:300])
d2 = data(root.get('/admin/menus-edit?key=main')[2])
check('and opens again as it was saved, with its nesting', [(i['depth'], i['url']) for i in d2['items']] == [(1, '/'), (1, 'about'), (1, ''), (2, 'careers'), (1, 'careers')], [(i['depth'], i['url']) for i in d2['items']])
check('a hidden item keeps its flag', d2['items'][2]['hidden'] is True and d2['items'][1]['hidden'] is False)
st, _, html = pub.get('/en/')
nav = re.search(r'<nav id="site-menu".*?</nav>', html, re.S)
nav = nav.group(0) if nav else ''
check('the site shows the labels in the language of the page, the new tab and the button class', '>About</a>' in nav and 'nav-cta' in nav and 'target="_blank"' in nav and '>Careers</a>' in nav, nav[:400])
check('a hidden item is not shown, nor what is under it', 'Company' not in nav and 'Hidden child' not in nav, nav[:400])
st, _, html = pub.get('/')
nav = re.search(r'<nav id="site-menu".*?</nav>', html, re.S)
check('and the default language has its own labels', nav is not None and 'Σχετικά' in nav.group(0))

# ---- a field that is not a list of items saves nothing
now = file('main')
for bad in ('not json', '{"a":1}', ''):
    st, _, html = root.submit('/admin/menus-edit?key=main', has_field('menu_json'), {'menu_json': bad, 'menu_title': 'Typed title'}, button=('menu_action', 'save'))
    check('a menu field that cannot be read saves nothing and says so: ' + (bad or 'empty'), st == 200 and 'nothing was saved' in html and file('main') == now and 'Typed title' in html, st)
st, _, html = root.submit('/admin/menus-edit?key=main', has_field('menu_json'), {'menu_json': json.dumps([row(1, 'x', 'y', 'a')] * 301), 'menu_title': 'Main Menu'}, button=('menu_action', 'save'))
check('so does a menu of far too many items', st == 200 and 'nothing was saved' in html and file('main') == now, st)

# ---- where a menu appears
st, hdrs, _ = root.submit('/admin/menus-new', has_field('new_key'), {'new_key': 'extra', 'new_title': 'Extra'})
check('a menu is made', st in (302, 303) and 'key=extra' in (hdrs.get('Location') or ''), hdrs.get('Location'))
d3 = data(root.get('/admin/menus-edit?key=extra')[2])
check('it is in no place yet, and the places are free to take', all(p['menu'] != 'extra' for p in d3['locations']) and not any(p['locked'] for p in d3['locations'] if p['key'] == 'footer'), d3['locations'])
save(root, 'extra', [row(1, 'Μόνο εδώ', 'Only here', 'about')], {'menu_locations_present': '1', 'menu_locations[]': ['footer']}, 'Extra')
check('ticking a place puts the menu there', 'footer: extra' in settings(), settings()[-300:])
st, _, html = pub.get('/en/')
foot = re.search(r'<footer.*?</footer>', html, re.S)
check('and the footer of the site shows it', foot is not None and 'Only here' in foot.group(0), foot.group(0)[-300:] if foot else '')
d4 = data(root.get('/admin/menus-edit?key=main')[2])
check('the menu that was there says what shows in the place now', next(p for p in d4['locations'] if p['key'] == 'footer')['menu'] == 'extra')
save(root, 'extra', [row(1, 'Μόνο εδώ', 'Only here', 'about')], {'menu_locations_present': '1', 'menu_locations[]': []}, 'Extra')
check('unticking it gives the place back to the theme\'s menu', 'footer: footer' in settings(), settings()[-300:])
st, _, html = pub.get('/en/')
foot = re.search(r'<footer.*?</footer>', html, re.S)
check('and the footer is as before', foot is not None and 'Only here' not in foot.group(0))
d5 = data(root.get('/admin/menus-edit?key=footer')[2])
check('the theme\'s own menu for a place cannot be unticked', next(p for p in d5['locations'] if p['key'] == 'footer')['locked'] is True)

# ---- someone who may change menus but not settings
set_caps(root, {'editor': ['menus.manage']})
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed2', 'email': 'ed2@example.test', 'display_name': 'ed2', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed2', 'Sturdy-pass-99')
st, _, html = ed.get('/admin/menus-edit?key=extra')
check('an editor with the right opens the editor', st == 200 and data(html) is not None, st)
check('and is told places cannot be changed by them', data(html)['can_place'] is False and 'name="menu_locations[]"' not in html)
settings_before = settings()
save(ed, 'extra', [row(1, 'Μόνο εδώ', 'Only here', 'about')], {'menu_locations_present': '1', 'menu_locations[]': ['header']}, 'Extra')
check('even a form that asks for a place changes nothing', settings() == settings_before and 'header: extra' not in settings())
check('but the items are saved', 'Only here' in file('extra'))

# ---- deleting
st, hdrs, _ = root.submit('/admin/menus-edit?key=extra', has_field('menu_json'), {}, button=('menu_action', 'delete'))
check('a menu is deleted from the editor', st in (302, 303) and 'deleted=1' in (hdrs.get('Location') or ''), hdrs.get('Location'))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
