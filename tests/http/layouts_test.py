import re, sys, os, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:220]))
    if not ok: fails.append(label)

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='theme_settings'").fetchone(); c.close()
    return v[0] if v else ''
def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()
def custom(type_):
    path = 'app/custom/content-types/%s.yaml' % type_
    return open(path, encoding='utf-8').read() if os.path.exists(path) else ''

root = Client(); root.login()
pub = Client()

def theme_form(client):
    return next(f for f in client.forms('/admin/theme?tab=single_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
def save(client, overrides, drop=(), tab='single_layouts'):
    """Submit the Theme form the way the browser does: its own values, then what the test changes."""
    form = theme_form(client)
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] not in drop and x[0] != 'active_tab']
    for k, v in overrides.items():
        for item in (v if isinstance(v, list) else [v]):
            fields.append((k, item))
    fields.append(('active_tab', tab))
    return client.request('/admin/theme', data=fields)

# ---- Single Layouts: a card for each content type
st, _, html = root.get('/admin/theme?tab=single_layouts')
cards = re.findall(r'<article class="lc-card[^>]*aria-labelledby="single-([a-z0-9_-]+)"', html)
check('the tab has a card for each content type, forms and pages included', st == 200 and set(cards) >= {'pages', 'posts', 'projects', 'forms'}, cards)
check('the page layout, the title area and the header are chosen on a card', all(('name="single_layouts[posts][%s]"' % k) in html for k in ('template', 'title', 'header', 'side')), '')
check('the parts are chips', all(('name="single_layouts[posts][%s]"' % k) in html for k in ('image', 'excerpt', 'byline', 'toc', 'related', 'card')), '')
check('a page has no line above its title to switch', 'name="single_layouts[pages][byline]"' not in html and 'name="single_layouts[posts][byline]"' in html)
check('a form has no page layout to choose', 'name="single_layouts[forms][template]"' not in html and 'name="single_layouts[forms][title]"' in html)
check('the old tabs are gone', 'data-tab="hero_layouts"' not in html and 'data-tab="transparent_header"' not in html and 'data-tab="sidebar"' not in html)
check('the text of the sidebar card is in this tab', 'name="theme_settings[sidebar][card_heading]"' in html and 'name="theme_settings[sidebar][toc]"' not in html)
check('the little preview and the script that keeps it in step are there', 'data-lc-preview' in html and 'js/admin-layouts.js' in html)

# ---- the older settings are where a card starts
run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", ("hero_layouts:\n  default: default\n  posts: split\n  pages: default\n  projects: default\n  forms: default\n",))
st, _, html = root.get('/admin/theme?tab=single_layouts')
check('a site that chose a title layout before finds it on the card', re.search(r'name="single_layouts\[posts\]\[title\]" value="split" checked', html) is not None)

# ---- saving the cards: the page of a post changes
post = '/en/posts/rebrand-readiness-guide'
st, _, plain = pub.get(post)
if st != 200:
    post = '/posts/rebrand-readiness-guide'
    st, _, plain = pub.get(post)
st, hdr, _ = save(root, {'single_layouts[posts][template]': 'sidebar', 'single_layouts[posts][side]': 'left', 'single_layouts[posts][title]': 'centered', 'single_layouts[posts][header]': 'on'})
check('saving goes back to the same tab', st == 302 and 'saved=1' in (hdr.get('Location') or '') and 'tab=single_layouts' in (hdr.get('Location') or ''), hdr.get('Location'))
check('what is stored is under the content type', 'single_layouts:' in stored() and re.search(r'posts:\s+title: centered\s+header: \'?on\'?\s+side: left', stored()) is not None, stored()[-700:])
st, _, html = pub.get(post)
check('a post now has the sidebar layout, on the left', st == 200 and 'class="site-wrap with-sidebar is-left"' in html and 'with-sidebar-aside' in html, st)
check('the title area is the centered one', 'single-hero-centered' in html, '')
check('and the header sits over it', 'has-transparent-header' in html, '')
st, _, html = pub.get('/about')
check('the other content types are not touched', 'with-sidebar' not in html and 'has-transparent-header' not in html and re.search(r'<section class="single-hero[ "]', html) is not None, html[:200])

# the parts of the title area and of the sidebar
st, hdr, _ = save(root, {'single_layouts[posts][template]': 'sidebar', 'single_layouts[posts][title]': 'default', 'single_layouts[posts][header]': 'site', 'single_layouts[posts][side]': 'right'},
                  drop=('single_layouts[posts][excerpt]', 'single_layouts[posts][byline]', 'single_layouts[posts][toc]', 'single_layouts[posts][related]'))
st, _, html = pub.get(post)
check('a box that is not ticked switches the part off', 'single-hero-subtitle' not in html and 'class="single-hero-meta"' not in html and 'sidebar-toc' not in html, '')
check('and the sidebar is on the right again', 'class="site-wrap with-sidebar"' in html)

# ---- a single entry can still choose
open('app/content/posts/own.md', 'w', encoding='utf-8').write("---\ntitle: Own layout\nstatus: published\nvisible: true\ndate: '2026-03-01'\ntemplate: standard\nhero_layout: minimal\n---\n\nText.\n")
st, _, html = pub.get('/posts/own')
check('an entry can ask for the plain layout, and its own title area', st == 200 and 'with-sidebar' not in html and 'single-hero-minimal' in html, st)
st, _, html = root.get('/admin/edit?type=posts&slug=own&lang=el')
check('the editor offers the plain layout and says what the others get', 'value="standard"' in html and 'Like the others (With sidebar)' in html, '')
os.remove('app/content/posts/own.md')

# ---- a card that is not submitted leaves its type as it is
st, hdr, _ = save(root, {}, drop=tuple(k for k in [x[0] for x in theme_form(root)['fields']] if k.startswith('single_layouts[projects]')))
check('a type whose card was not sent keeps its layout, others are saved', st == 302 and 'projects:' in stored(), stored()[-300:])

# ---- Archive Layouts
st, _, html = root.get('/admin/theme?tab=archive_layouts')
cards = re.findall(r'aria-labelledby="archive-(type|taxonomy)-([a-z0-9_-]+)"', html)
check('the tab has a card for each content type that has a list, and each taxonomy', ('type', 'posts') in cards and ('type', 'projects') in cards and ('taxonomy', 'tags') in cards and ('taxonomy', 'categories') in cards and ('type', 'pages') not in cards and ('type', 'forms') not in cards, cards)
check('with the layout, columns, order, page size, parts, filters and the texts', all(('name="archive_types[posts][%s]"' % k) in html for k in ('layout', 'columns', 'per_page', 'order', 'title', 'subtitle')) and 'name="archive_types[posts][taxonomies][]"' in html and 'name="archive_types[posts][show_image]"' in html, '')
check('a taxonomy chooses which content types it lists', 'name="archive_taxonomies[tags][types][]"' in html)
st, _, html = pub.get('/en/posts')
check('before, the list of posts is cards', st == 200 and 'block--cards' in html, st)
st, hdr, _ = save(root, {'archive_types[posts][layout]': 'list', 'archive_types[posts][per_page]': '2', 'archive_types[posts][title]': 'All the news'}, tab='archive_layouts')
check('saving an archive card goes back to its tab', st == 302 and 'saved=1' in (hdr.get('Location') or '') and 'tab=archive_layouts' in (hdr.get('Location') or ''), hdr.get('Location'))
check('only what differs from the theme is written, in the site\'s own file', 'layout: list' in custom('posts') and 'per_page: 2' in custom('posts') and 'title: \'All the news\'' in custom('posts') + "title: 'All the news'" and 'show_image' not in custom('posts'), custom('posts'))
st, _, html = pub.get('/en/posts')
check('the list of posts follows', 'block--list' in html and 'All the news' in html and html.count('class="content-item') <= 2 + 1, (st, re.findall(r'block--[a-z]+', html)))
check('an archive card that did not change writes nothing', custom('projects') == '' and custom('pages') == '', custom('projects'))

st, hdr, _ = save(root, {'archive_taxonomies[tags][layout]': 'compact', 'archive_taxonomies[tags][types][]': ['posts']}, tab='archive_layouts')
tags = open('app/content/taxonomies/tags.yaml', encoding='utf-8').read()
check('a taxonomy card is saved in the taxonomy file', 'layout: compact' in tags and re.search(r'types:\s+- posts', tags) is not None, tags[-200:])

# ---- the old places no longer edit the layout, and do not lose it
st, _, html = root.get('/admin/content-types?type=posts')
check('the content type screen links to the archive layouts instead of holding them', st == 200 and 'name="archive[layout]"' not in html and 'tab=archive_layouts' in html, st)
form = next(f for f in root.forms('/admin/content-types?type=posts') if any(x[0] == 'label' for x in f['fields']))
root.request('/admin/content-types', data=[tuple(x) for x in form['fields']])
check('saving it leaves the archive layout as the card made it', 'layout: list' in custom('posts'), custom('posts'))
st, _, html = root.get('/admin/taxonomies?taxonomy=tags')
check('the taxonomy screen links to them too', st == 200 and 'name="archive[layout]"' not in html and 'tab=archive_layouts' in html, st)

# ---- someone who may not manage the theme
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed3', 'email': 'ed3@example.test', 'display_name': 'ed3', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed3', 'Sturdy-pass-99')
st, _, _ = ed.get('/admin/theme?tab=archive_layouts')
check('an editor cannot open them', st in (302, 403), st)
before = custom('posts')
st, _, _ = ed.request('/admin/theme', data=[('active_tab', 'archive_layouts'), ('archive_types[posts][layout]', 'editorial')])
check('nor save them', custom('posts') == before, st)

# ---- the new title area lines up with the old settings once more: a site that never saved the cards
run("delete from system_meta where key='theme_settings'")
run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", ("hero_layouts:\n  default: default\n  posts: cover\n  pages: default\n  projects: default\n  forms: default\nsidebar:\n  toc: false\n",))
st, _, html = pub.get(post)
check('settings saved before the cards existed still draw the page', st == 200, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
