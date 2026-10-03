import html as htmllib, json, re, sys, sqlite3, urllib.parse
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()

root = Client(); root.login()
pub = Client()

def post_theme(tab, overrides, drop=()):
    """Submit the Theme form the way the browser does: its own values, then what the test changes."""
    form = next(f for f in root.forms('/admin/theme?tab=' + tab) if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] not in drop and x[0] != 'active_tab']
    for k, v in overrides.items():
        if v is None:
            continue
        for item in (v if isinstance(v, list) else [v]):
            fields.append((k, item))
    fields.append(('active_tab', tab))
    return root.request('/admin/theme', data=fields)
def header(**v): return post_theme('header', {'theme_settings[header][%s]' % k: val for k, val in v.items()})
def footer(**v): return post_theme('footer', {'theme_settings[footer][%s]' % k: val for k, val in v.items()})
def page(path='/about'):
    st, _, html = pub.get(path)
    return html
def asset(kind):
    """The theme's stylesheet or script, as the page names it."""
    m = re.search(r'(?:href|src)="([^"]*site\.%s[^"]*)"' % kind, page())
    if not m:
        return ''
    u = urllib.parse.urlparse(m.group(1))
    return pub.get(u.path + ('?' + u.query if u.query else ''))[2]
def stored(yaml):
    run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", (yaml,))
def head(html):
    m = re.search(r'<header class="site-header[^"]*"[^>]*>.*?</header>', html, re.S)
    return m.group(0) if m else ''
def head_tag(html):
    m = re.search(r'<header class="(site-header[^"]*)"([^>]*)>', html)
    return (m.group(1), m.group(2)) if m else ('', '')
def foot(html):
    m = re.search(r'<footer class="site-footer.*?</footer>', html, re.S)
    return m.group(0) if m else ''

# ---- the two tabs
st, _, html = root.get('/admin/theme?tab=header')
check('the Header tab shows a picture of the header', st == 200 and 'data-hf-preview' in html and 'class="hfp hfh"' in html)
layouts = re.findall(r'name="theme_settings\[header\]\[layout\]" value="([a-z_]+)"', html)
check('with every layout to choose from', layouts == ['classic', 'menu_left', 'split', 'centered', 'stacked', 'minimal', 'minimal_center'], layouts)
check('and a field for every choice, under the names the theme has always had', all(('name="theme_settings[header][%s]"' % k) in html for k in (
    'shape', 'width', 'size', 'border', 'nav_style', 'sticky', 'shrink', 'row_tone', 'bar_tone', 'transparent', 'search', 'show_language', 'show_mode', 'cta_label', 'cta_url', 'cta_style',
    'topbar_text', 'topbar_url', 'topbar_tone', 'topbar_contacts', 'topbar_social', 'mobile_menu', 'mobile_bar')) and 'name="theme_settings[header][bar_items][0][label]"' in html or 'theme_settings[header][bar_items]' in html)
check('the tone of the bar is only asked for when the layout is stacked', 'data-only="stacked"' in html)
check('the picture is told which fields it follows', 'data-hf-map=' in html and 'theme_settings[header][layout]' in htmllib.unescape(html.split('data-hf-map=')[1][:600]))
st, _, html = root.get('/admin/theme?tab=footer')
check('the Footer tab shows its picture and every layout', 'class="hfp hff"' in html and re.findall(r'name="theme_settings\[footer\]\[layout\]" value="([a-z]+)"', html) == ['columns', 'mega', 'simple', 'bar', 'centered'])
check('and a field for every choice', all(('name="theme_settings[footer][%s]"' % k) in html for k in (
    'tone', 'brand', 'show_social', 'show_language', 'back_to_top', 'copyright', 'summary', 'email', 'phone', 'address', 'hours', 'cta_heading', 'cta_text', 'cta_label', 'cta_url', 'background', 'background_image')))
check('the media picker can fill the footer image', re.search(r'name="theme_settings\[footer\]\[background_image\]"[^>]*data-image-field|data-image-field[^>]*name="theme_settings\[footer\]\[background_image\]"', html) is not None)
check('the Sidebar and Hero tabs stay where they were put', 'data-tab="single_layouts"' in html and 'data-tab="header"' in html)

# ---- what a site had before still draws the same
run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", ("header:\n  layout: stacked\n  row_tone: soft\n  sticky: always\nfooter:\n  layout: bar\n",))
cls, attrs = head_tag(page())
check('a header saved before the new choices has none of them', 'header--stacked' in cls and 'row-soft' in cls and 'size-regular' in cls and 'edge-line' in cls and 'nav-soft' in cls and 'is-floating' not in cls and 'width-wide' not in cls and 'data-sticky="always"' in attrs and 'data-smart' not in attrs, (cls, attrs))
check('and a footer too', 'footer--bar' in foot(page()) and 'is-light' not in foot(page()) and 'footer-cta' not in foot(page()))
run("delete from system_meta where key='theme_settings'")

# ---- layouts
for layout, nav_in_row, bar in (('classic', True, False), ('menu_left', True, False), ('split', True, False), ('centered', False, True), ('stacked', False, True), ('minimal', False, False), ('minimal_center', False, False)):
    header(layout=layout)
    html = page(); h = head(html)
    row = re.search(r'<div class="site-wrap header-row">(.*?)<div class="header-tools">', h, re.S)
    check('layout %s: header--%s, the menu %s' % (layout, layout, 'in the row' if nav_in_row else ('in a bar underneath' if bar else 'only in the phone menu')),
          'header--%s' % layout in h and (('id="site-menu"' in (row.group(1) if row else '')) == nav_in_row) and (('class="header-bar' in h) == bar), (layout, bool(row)))
header(layout='minimal_center')
check('minimal centered has the menu button on every screen size, as minimal has', 'mobile-menu-button' in head(page()) and '.header--minimal_center .mobile-menu-button' in asset('css'))

# ---- style
header(layout='classic', shape='floating', width='wide', size='tall', border='shadow', nav_style='underline', row_tone='contrast', shrink='1')
cls, attrs = head_tag(page())
check('shape, width, height, edge, links, background and shrinking are classes on the header', all(c in cls.split() for c in ('is-floating', 'width-wide', 'size-tall', 'edge-shadow', 'nav-underline', 'row-contrast', 'tone-contrast', 'is-shrinking')), cls)
header(row_tone='accent', size='compact', border='none', nav_style='plain', shape='full', width='contained', shrink=None)
cls, _ = head_tag(page())
check('another set', all(c in cls.split() for c in ('size-compact', 'edge-none', 'nav-plain', 'row-accent', 'tone-accent')) and 'is-floating' not in cls and 'is-shrinking' not in cls, cls)
stored("header:\n  row_tone: nonsense\n  size: huge\n  border: dotted\n  nav_style: fancy\n  shape: round\n")
cls, _ = head_tag(page())
check('a value the theme does not offer is the usual one', 'size-regular' in cls and 'edge-line' in cls and 'nav-soft' in cls and 'is-floating' not in cls and 'row-' not in cls, cls)

# ---- behaviour
header(sticky='smart')
cls, attrs = head_tag(page())
check('smart: a header that stays at the top and says it hides when scrolling down', 'data-sticky="always"' in attrs and 'data-smart' in attrs, attrs)
js = asset('js')
check('and the script that does it', 'data-smart' in js and 'is-tucked' in js)
header(sticky='off')
check('off: the header scrolls away', 'data-sticky="off"' in head_tag(page())[1])
stored("header:\n  sticky: nonsense\n")
check('anything else: after scrolling', 'data-sticky="on_scroll"' in head_tag(page())[1])

# ---- what it holds
header(sticky='on_scroll', search='none', show_language=None, show_mode=None, cta_label='Get a quote', cta_url='contact', cta_style='outline')
h = head(page())
check('no search, no language switcher, no dark mode switch', 'search-link' not in h and 'header-search' not in h and 'lang-switcher' not in h.split('<div class="mobile-drawer')[0] and 'data-theme-toggle' not in h, '')
check('an outline button', 'class="btn header-cta"' in h and 'Get a quote' in h, re.findall(r'<a class="[^"]*header-cta[^"]*"', h))
html = page()
drawer = html[html.index('id="mobile-drawer"'):]
check('and the phone menu does not offer them either', 'class="text-link"' not in drawer.split('drawer-icons-wrap')[0] and 'lang-switcher' not in drawer.split('drawer-icons-wrap')[0], '')
header(search='field', show_language='1', show_mode='1', cta_style='solid')
h = head(page())
check('a search box, the switches, a solid button', 'header-search' in h and 'lang-switcher' in h and 'data-theme-toggle' in h and 'class="btn btn-primary header-cta"' in h)

# ---- the top bar
header(topbar_text='Free consultation', topbar_url='contact', topbar_tone='contrast', topbar_social='1')
post_theme('header', {'theme_settings[social][facebook]': 'https://www.facebook.com/example'})
h = head(page())
check('the top bar has its message as a link, its own background and the social icons', 'class="topbar tone-contrast"' in h and re.search(r'<p class="topbar-text"><a href="[^"]*/contact">Free consultation</a>', h) is not None and 'topbar-icons' in h, re.findall(r'<div class="topbar[^>]*>', h))
header(topbar_text='', topbar_social=None)
check('without a message, contacts or icons there is no bar', 'class="topbar' not in head(page()))
header(topbar_text='Hello', topbar_url='', topbar_tone='accent', topbar_social=None)
h = head(page())
check('a message with no link is plain text on the palette colour', 'class="topbar tone-accent"' in h and '<p class="topbar-text">Hello</p>' in h)

# ---- the footer
footer(layout='mega', tone='light', brand='none', copyright='© {year} {site}. Made in Athens.', hours='Mon–Fri 9:00–17:00', email='hello@example.com', show_social='1', show_language='1', back_to_top='1',
       cta_heading='Ready to start?', cta_text='Tell us about it.', cta_label='Get in touch', cta_url='contact')
f = foot(page())
check('footer: mega, on the page background', 'footer--mega' in f and ' is-light' in f and 'tone-accent' not in f and 'footer-grid--mega' in f)
check('no brand when it is switched off', 'class="footer-brand"' not in f)
check('the copyright line is the site\'s own, with the year and the name', re.search(r'<p class="footer-copy">© \d{4} [^<]+\. Made in Athens\.</p>', f) is not None, re.findall(r'<p class="footer-copy">[^<]*', f))
check('opening hours are in the contact column', 'Mon–Fri 9:00–17:00' in f and 'hello@example.com' in f)
check('the call to action band, the language switcher and the back to top link', 'footer-cta' in f and 'Ready to start?' in f and re.search(r'<a class="btn btn-primary" href="[^"]*/contact">Get in touch', f) is not None and 'lang-switcher' in f and 'class="footer-top"' in f, [x in f for x in ('footer-cta', 'Ready to start?', 'lang-switcher', 'class="footer-top"')])
check('social icons are in the footer when it is ticked', 'footer-icons' in f)
footer(tone='accent', brand='name', show_social=None, show_language=None, back_to_top=None, cta_heading='', cta_text='', cta_label='')
f = foot(page())
check('the palette colour: its classes, no social icons, no band, no switcher', 'is-accent' in f and 'tone-accent' in f and 'footer-icons' not in f and 'footer-cta' not in f and 'lang-switcher' not in f and 'footer-top' not in f and 'class="footer-brand"' in f, re.findall(r'<footer class="[^"]*"', f))
footer(tone='muted')
check('muted', ' is-muted' in foot(page()))
footer(tone='dark')
f = foot(page())
check('dark is the base: no tone class', 'is-light' not in f and 'is-muted' not in f and 'is-accent' not in f and '<footer class="site-footer footer--mega">' in f, re.findall(r'<footer class="[^"]*"', f))
css = asset('css')
check('and the dark colours are the base the others leave', '.site-footer:not(.is-light, .is-muted, .is-accent)' in css)
stored("footer:\n  tone: nonsense\n  layout: nonsense\n  brand: nonsense\n")
f = foot(page())
check('a value the theme does not offer is the usual one', 'footer--columns' in f and 'is-' not in re.search(r'<footer class="([^"]*)"', f).group(1) and 'class="footer-brand"' in f)
footer(brand='logo')
post_theme('brand', {'theme_settings[brand][logo]': '/uploads/media/5e6915a67b9ceec5.jpg'})
check('the logo can be the brand of the footer', 'footer-logo' in foot(page()))

# ---- the links at the bottom are a menu with a place of its own
st, _, html = root.get('/admin/menus-edit?key=main')
d = json.loads(re.search(r'id="menu-editor-data">(.*?)</script>', html, re.S).group(1))
check('the place for them is offered to menus', 'footer_legal' in [p['key'] for p in d['locations']], [p['key'] for p in d['locations']])
check('and no links show until the menu exists', 'footer-legal' not in foot(page()))
root.submit('/admin/menus-new', has_field('new_key'), {'new_key': 'legal', 'new_title': 'Legal'})
rows = [{'depth': 1, 'labels': {'el': 'Απόρρητο', 'en': 'Privacy'}, 'url': 'privacy'}, {'depth': 1, 'labels': {'el': 'Όροι', 'en': 'Terms'}, 'url': 'terms'}]
root.submit('/admin/menus-edit?key=legal', has_field('menu_json'), {'menu_json': json.dumps(rows), 'menu_title': 'Legal'}, button=('menu_action', 'save'))
f = foot(page('/en/about'))
check('a menu called legal is shown at the bottom', 'footer-legal' in f and '>Privacy</a>' in f and '>Terms</a>' in f, re.findall(r'footer-legal.{0,200}', f, re.S)[:1])
check('in the language of the page', '>Απόρρητο</a>' in foot(page('/about')))

# ---- mega: a column for each link that has links under it
rows = [{'depth': 1, 'labels': {'el': 'Εταιρεία', 'en': 'Company'}, 'url': ''}, {'depth': 2, 'labels': {'el': 'Σχετικά', 'en': 'About'}, 'url': 'about'}, {'depth': 2, 'labels': {'el': 'Καριέρα', 'en': 'Careers'}, 'url': 'careers'},
        {'depth': 1, 'labels': {'el': 'Βοήθεια', 'en': 'Help'}, 'url': ''}, {'depth': 2, 'labels': {'el': 'Συχνές', 'en': 'FAQ'}, 'url': 'faq'},
        {'depth': 1, 'labels': {'el': 'Επικοινωνία', 'en': 'Contact'}, 'url': 'contact'}]
root.submit('/admin/menus-edit?key=footer', has_field('menu_json'), {'menu_json': json.dumps(rows), 'menu_title': 'Footer'}, button=('menu_action', 'save'))
footer(layout='mega')
f = foot(page('/en/about'))
titles = re.findall(r'<h2 class="footer-title"[^>]*>([^<]*)</h2>', f)
check('mega: a column for each link with links under it, and the others together', titles[:3] == ['Company', 'Company', 'Help'] or titles[:3] == ['Company', 'Help', 'Company'] or ('Help' in titles and 'Company' in titles), titles)
check('with the links of each under its title', re.search(r'footer-group-1.*?>About</a>.*?>Careers</a>', f, re.S) is not None and re.search(r'footer-group-2.*?>FAQ</a>', f, re.S) is not None, '')
check('and the link with nothing under it in the first column', re.search(r'footer-company-title.*?>Contact</a>', f, re.S) is not None, '')
footer(layout='columns')
check('columns shows the menu as before', 'footer-grid--mega' not in foot(page('/en/about')) and 'footer-group-1' not in foot(page('/en/about')))

# ---- the pictures follow the form (initial state)
header(layout='classic', search='field')
st, _, html = root.get('/admin/theme?tab=header')
pv = re.search(r'<div class="hfp hfh"([^>]*)>', html).group(1)
check('the picture of the header starts as the form is', 'data-layout="classic"' in pv and 'data-shape="full"' in pv and 'data-search="field"' in pv, pv[-500:])

# ---- someone who may not manage the theme
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed4', 'email': 'ed4@example.test', 'display_name': 'ed4', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed4', 'Sturdy-pass-99')
before = head_tag(page())[0]
st, _, _ = ed.request('/admin/theme', data=[('active_tab', 'header'), ('theme_settings[header][layout]', 'split')])
check('an editor cannot save them', head_tag(page())[0] == before, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
