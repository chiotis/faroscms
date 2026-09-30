import json, re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()
def put(path, front, body='Text.'):
    open('app/content/' + path, 'w', encoding='utf-8').write('---\n' + front + '---\n\n' + body + '\n')

root = Client(); root.login()
pub = Client()
def bar(path='/about'):
    st, _, html = pub.get(path)
    m = re.search(r'<nav class="mobile-bar".*?</nav>', html, re.S)
    return html, (m.group(0) if m else '')
def labels(bar_html):
    return re.findall(r'<span>([^<]*)</span>', bar_html)

# ---- the settings screen: a list of rows, each with an icon chosen from a popup of pictures
def theme_form():
    return next(f for f in root.forms('/admin/settings') if any(x[0].startswith('theme_settings[header]') for x in f['fields']))
st, _, html = root.get('/admin/settings')
check('the settings screen has the bottom bar list', st == 200 and 'data-repeater' in html and 'Bottom bar links' in html and 'data-repeater-add' in html, st)
check('its rows can be added from a template', 'data-repeater-template' in html and '__INDEX__' in html)
check('an empty list is sent as "none"', 'name="theme_settings[header][bar_items]" value=""' in html)
check('the icon of a row is a select the picker takes over', 'data-icon-picker' in html)
check('the icon library is on the page for the picker', 'id="icon-library"' in html)
lib = json.loads(re.search(r'<script type="application/json" id="icon-library">(.*?)</script>', html, re.S).group(1))
check('it holds every icon of the theme, as pictures', len(lib) >= 30 and all(v.startswith('<svg') for v in lib.values()) and 'phone' in lib and 'megaphone' in lib, len(lib))
check('the picker script is loaded', 'assets/js/admin-icons.js' in html)
check('a picture cannot close the script tag', '</script' not in re.search(r'id="icon-library">(.*?)</script>', html, re.S).group(1))

# ---- saving rows
def save_bar(rows, extra=None):
    form = theme_form()
    drop = [x[0] for x in form['fields'] if 'bar_items' in x[0]]
    overrides = {'theme_settings[header][mobile_bar]': '1'}
    overrides['theme_settings[header][bar_items]'] = ''
    for i, (icon, label, url) in enumerate(rows):
        overrides['theme_settings[header][bar_items][%d][icon]' % i] = icon
        overrides['theme_settings[header][bar_items][%d][label]' % i] = label
        overrides['theme_settings[header][bar_items][%d][url]' % i] = url
    if extra: overrides.update(extra)
    return root.request('/admin/settings', data=[(k, v) for k, v in [tuple(f) for f in form['fields'] if f[0] not in overrides and f[0] not in drop]] + [(k, v) for k, v in overrides.items()])

def stored():
    c = sqlite3.connect('app/storage/db/app.sqlite'); v = c.execute("select value from system_meta where key='theme_settings'").fetchone(); c.close()
    return v[0] if v else ''

# with the bar on and nothing chosen: the automatic links
save_bar([])
html, nav = bar()
check('with no list the bar has the Menu button first, then call/email/button as before', nav != '' and nav.index('data-mobile-open') < nav.index('</nav>') and 'class="mobile-bar-item" data-mobile-open' in nav, nav[:300])

st, _, _ = save_bar([('phone', 'Call us', 'tel:+302100000000'), ('map-pin', 'Visit', 'contact'), ('', 'Quote', '/en/contact'), ('bogus-icon', 'Odd', 'about'), ('star', 'No link', ''), ('', '', '')])
check('the settings save', st in (200, 302), st)
data = stored()
rows_yaml = data.split('bar_items:')[1].split('topbar')[0]
check('at most four rows are kept, in order; an unknown icon is dropped', len(re.findall(r'icon: ', rows_yaml)) == 4 and 'bogus-icon' not in data and 'No link' not in data and 'Call us' in data.split('Visit')[0], rows_yaml[:500])
html, nav = bar()
found = labels(nav)
check('the bar shows Menu first, then the chosen links in order', found == ['Μενού', 'Call us', 'Visit', 'Quote', 'Odd'], found)
check('the links go where they were told', 'href="tel:+302100000000"' in nav and 'href="/contact"' in nav and 'href="/en/contact"' in nav, nav)
check('each shows its icon (a picture of its own)', nav.count('<svg') == 5, nav.count('<svg'))
save_bar([('', '', ''), ('star', 'No link', ''), ('phone', 'Call', 'tel:+30')])
nav = bar()[1]
check('an empty row is not kept, and a row without a link is left out of the bar', labels(nav) == ['Μενού', 'Call'] and 'No link' not in nav, labels(nav))
save_bar([('phone', 'Call us', 'tel:+302100000000'), ('map-pin', 'Visit', 'contact'), ('', 'Quote', '/en/contact'), ('bogus-icon', 'Odd', 'about')])
nav = bar()[1]
check('a link without an icon still has one', re.search(r'<a class="mobile-bar-item" href="/en/contact"><svg[^>]*>.*?</svg><span>Quote', nav, re.S) is not None)
check('the header button is not repeated in the bar', 'is-primary' not in nav)
check('the bar is a named navigation', 'aria-label="' in nav[:80])
check('the menu button of the bar still opens the same panel', 'aria-controls="mobile-drawer" aria-expanded="false"' in nav)
head = html.split('</header>')[0]
check('the header still has its own button for widths where the bar is off', 'class="mobile-menu-button"' in head)
check('the page knows the bar is on (the header button is hidden by it on phones)', 'has-mobile-bar' in html.split('<body')[1][:120])

# the list can be emptied again
save_bar([])
check('emptying the list brings back the automatic links', '<span>Call us</span>' not in bar()[1] and 'data-repeater' in root.get('/admin/settings')[2])
check('and the stored list is empty', re.search(r'bar_items:\s*(\[\]|\{\s*\})', stored()) is not None, stored()[:400])

# ---- off: no bar
save_bar([], {'theme_settings[header][mobile_bar]': None})
run("update system_meta set value = replace(value, 'mobile_bar: true', 'mobile_bar: false') where key='theme_settings'")
html, nav = bar()
check('with the bar off there is none, and no class', nav == '' and 'has-mobile-bar' not in html.split('<body')[1][:120])

# ---- the block editor uses the same picker for its icon fields
put('pages/with-features.md', "title: With features\nstatus: published\nvisible: true\nblocks:\n  - type: features\n    heading: Why us\n    items:\n      - {title: Fast, text: Quick, icon: bolt}\n")
st, _, html = root.get('/admin/edit?type=pages&slug=with-features&lang=el')
check('the page editor carries the icon library', st == 200 and 'id="icon-library"' in html and 'admin-icons.js' in html, st)
m = re.search(r'id="block-editor-data">(.*?)</script>', html, re.S)
defs = json.loads(m.group(1))['definitions']
def icon_fields(fields, out):
    for f in fields:
        if f['type'] == 'icon': out.append(f['key'])
        if f.get('fields'): icon_fields(f['fields'], out)
    return out
found = {d['type']: icon_fields(d['fields'], []) for d in defs}
check('features, contact and banner offer icons as an icon field, not a plain list', found.get('features') and found.get('contact') and found.get('banner'), found)
feature = next(d for d in defs if d['type'] == 'features')
icon = next(f for f in next(x for x in feature['fields'] if x['key'] == 'items')['fields'] if f['key'] == 'icon')
check('with None first and the theme\'s icons after', icon['options'][0] == ['', 'None'] and ['bolt', 'bolt'] in icon['options'] and len(icon['options']) > 30, icon['options'][:3])
check('the saved icon of a block is kept', 'bolt' in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
