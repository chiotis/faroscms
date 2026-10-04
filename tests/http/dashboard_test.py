import json, os, re, sys, time, urllib.request
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
anon = Client()
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'

def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)
def dash(c=root): return c.get('/admin')[2]
def attention(html):
    """The titles of the things that need attention."""
    box = re.search(r'id="db-h-attn".*?</section>', html, re.S)
    return re.findall(r'<div class="db-item-text"><strong>([^<]*)</strong>', box.group(0)) if box else []
def collect(payload):
    req = urllib.request.Request(BASE + '/_a/collect', data=json.dumps(payload).encode(), headers={'User-Agent': UA, 'Content-Type': 'text/plain'}, method='POST')
    return urllib.request.urlopen(req).status

# ---- the page for the super admin
st, _, html = root.get('/admin')
check('the dashboard opens, with a greeting and a shortcut to a new page', st == 200 and re.search(r'Good (morning|afternoon|evening)', html) and 'href="/admin/new?type=pages"' in html and '+</span> New Page' in html, st)
check('the figures are tiles that lead somewhere', all(w in html for w in ('Content entries', 'Form submissions', '>Storage<', '>Backups<', 'System status')) and 'class="db-tile" href="/admin/system"' in html)
check('the part for what needs attention is there', 'Needs attention' in html)
items = attention(html)
check('a new site has no backup, and is told', 'There is no backup yet' in items, items)
check('and that visits are not counted', 'Visits are not being counted' in items, items)
check('each line leads to where it is dealt with', 'href="/admin/backups"' in html and 'href="/admin/analytics?tab=settings"' in html)
check('without the site\'s own analytics there is no chart of visitors', 'Visitors, last 90 days' not in html)
check('storage is a tile with a bar, where Users used to be', 'role="progressbar" aria-label="Storage used"' in html and '>Users<' not in html.split('db-h-attn')[0])
check('the recent content, activity and system checks are there', all(w in html for w in ('Recent content', 'Recent activity', 'System checks', 'Storage')))

# ---- drafts that are left
write('pages/old-draft.md', "---\ntitle: 'Old draft'\nstatus: draft\n---\n\nSomething unfinished.\n")
write('pages/new-draft.md', "---\ntitle: 'New draft'\nstatus: draft\n---\n\nJust started.\n")
old = time.time() - 45 * 86400
os.utime('app/content/pages/old-draft.md', (old, old))
html = dash()
check('the drafts are listed, to finish', 'Drafts to finish' in html and 'Old draft' in html and 'New draft' in html)
check('with how long each has been left, and the old one marked', '45 days' in html and 'db-time is-old' in html and 'today' in html, re.findall(r'class="db-time[^"]*">[^<]*', html))
check('the forgotten one is also a thing to watch', any('not touched for over 30 days' in t for t in attention(html)), attention(html))
ed_html = dash(ed)
check('an editor sees the drafts and is told about the forgotten one', 'Drafts to finish' in ed_html and any('not touched for over 30 days' in t for t in attention(ed_html)), attention(ed_html))

# ---- what an editor must not see
check('an editor gets nothing about backups, analytics, the system or people', not any(w in ed_html for w in ('There is no backup yet', 'Visits are not being counted', 'System checks', 'Recent activity', 'Visitors, last 90 days', 'Form submissions', 'The site address is not set')) and attention(ed_html) == [t for t in attention(ed_html) if 'draft' in t or 'language' in t], attention(ed_html))
check('an editor has a shortcut to write', 'href="/admin/new?type=pages"' in ed_html)

# ---- search, links and addresses
root.submit('/admin/seo?tab=crawling', lambda f: any(x[0] == 'robots_disallow' for x in f['fields']), {'discourage': '1'})
items = attention(dash())
check('a site that asks search engines to stay away comes first', items and items[0] == 'The site asks search engines to stay away', items)
root.submit('/admin/seo?tab=crawling', lambda f: any(x[0] == 'robots_disallow' for x in f['fields']), {'discourage': None})
check('and is not told when it does not', 'The site asks search engines to stay away' not in attention(dash()))

write('pages/team.md', "---\ntitle: 'Our team'\nstatus: published\nvisible: true\n---\n\nMeet us.\n")
write('pages/linker.md', "---\ntitle: 'Linker'\nstatus: published\nvisible: true\n---\n\nSee [the team](/old-team).\n")
root.submit('/admin/redirects', has_field('source'), {'source': '/old-team', 'target': '/team', 'code': '301'})
check('a link that still uses an old address is a thing to watch, with the way to update', '1 link in content still uses an old address' in attention(dash()) and 'href="/admin/redirects"' in dash(), attention(dash()))
anon.get('/nowhere-at-all')
check('an address that was asked for and not found is one too', '1 address was asked for and not found' in attention(dash()), attention(dash()))

# ---- the site's own analytics, last 90 days
root.submit('/admin/analytics?tab=settings', lambda f: any(x[0] == 'keep_months' for x in f['fields']), {'mode': 'platform'})
html = dash()
check('with the analytics on, the dashboard has a widget for the last 90 days', 'Visitors, last 90 days' in html and 'id="db-h-an"' in html)
check('it says so when nothing was counted, and links to the reports', 'No visits were counted in the last 90 days' in html and 'href="/admin/analytics"' in html)
check('and the hint to set analytics up is gone', 'Visits are not being counted' not in attention(html))
for p in ('/about', '/about', '/services', '/'):
    collect({'p': p, 'r': 'https://www.google.com/', 'w': 1440})
html = dash()
widget = re.search(r'<section class="[^"]*db-an".*?</section>', html, re.S).group(0)
check('after visits, it shows the figures with their change, and the chart', all(w in widget for w in ('Visitors', 'Page views', 'Bounce rate', 'Visit time')) and '<svg class="an-chart"' in widget and 'Visitors and page views by day' in widget, widget[:200])
check('the chart is a table for those who cannot see it, in a box that hides it', re.search(r'<div class="sr-only">\s*<table>', widget) is not None)
check('and the pages most seen, and where the visitors come from', 'Top pages' in widget and '>/about<' in widget and 'Where they come from' in widget and 'Search engines' in widget, re.findall(r'class="an-label"[^>]*>(?:<a[^>]*>)?([^<]*)', widget))
check('the widget is as wide as the page, above the lists of content', html.index('db-h-an') < html.index('id="db-h-recent"'))
check('an editor does not see it', 'Visitors, last 90 days' not in dash(ed))
check('the reports screen still draws the same chart', 'Visitors and page views by day' in root.get('/admin/analytics')[2] and '<svg class="an-chart"' in root.last_html)
root.submit('/admin/analytics?tab=settings', lambda f: any(x[0] == 'keep_months' for x in f['fields']), {'mode': 'custom', 'tag_id': '', 'head_code': '', 'body_code': ''})
html = dash()
check('a tracking code that is chosen but empty is a warning', 'Your own tracking code is chosen, but empty' in attention(html) and 'Visitors, last 90 days' not in html, attention(html))
root.submit('/admin/analytics?tab=settings', lambda f: any(x[0] == 'keep_months' for x in f['fields']), {'mode': 'off'})

# ---- many things to watch
html = dash()
n = len(attention(html))
check('the count is in the title', ('<span class="db-count">%d</span>' % n) in html, n)
if n > 6:
    check('the rest is in a closed part', 'class="db-more"' in html and 'Show %d more' % (n - 6) in html, n)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
