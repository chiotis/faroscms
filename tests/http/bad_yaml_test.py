import re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:220]))
    if not ok: fails.append(label)

def db(): return sqlite3.connect('app/storage/db/app.sqlite')
def path(p): return 'app/content/' + p
def read(p): return open(path(p), encoding='utf-8').read()
def write(p, text): open(path(p), 'w', encoding='utf-8').write(text)

BROKEN = """---
title: Broken page
status: published
visible: true
blocks:
  - type: hero
    variant: steps
    heading: Steps
    items:
      - { title: Design, text: Structure and visuals, reviewed together. }
---

The text of the page.
"""
write('pages/broken.md', BROKEN)

root = Client(); root.login()
pub = Client()

# ---- the rest of the site keeps working
for p in ('/', '/about', '/services', '/search?q=page', '/sitemap.xml', '/robots.txt', '/en/about', '/rebrand-readiness-guide'):
    st, _, html = pub.get(p)
    check('the public %s still loads' % p, st in (200, 404) and 'Fatal error' not in html and 'ParseException' not in html, (st, html[:120]))
st, _, html = pub.get('/about'); check('the ordinary page is intact', st == 200 and 'Fatal error' not in html)
st, _, html = pub.get('/broken')
check('the unreadable page is not shown to visitors', st == 404, st)
st, _, xml = pub.get('/sitemap.xml'); check('and is not in the sitemap', '/broken' not in xml)

# ---- the admin flags it
st, _, html = root.get('/admin/content?type=pages')
check('the admin list still opens', st == 200 and 'Fatal error' not in html, st)
row = re.search(r'<tr[^>]*>(?:(?!</tr>).)*/broken(?:(?!</tr>).)*</tr>', html, re.S)
check('and shows the file with an Unreadable badge and as a draft', row is not None and 'Unreadable' in row.group(0) and re.search(r'>\s*draft\s*<', row.group(0), re.I) is not None, (row is not None and 'Unreadable' in row.group(0), re.findall(r'>\s*(Draft|Published|Unpublished)\s*<', row.group(0)) if row else None))
n = db().execute("select count(*) from notifications where type='content.unreadable'").fetchone()[0]
check('the admins are told once', n == 1, n)
for _ in range(3): pub.get('/about')
n = db().execute("select count(*) from notifications where type='content.unreadable'").fetchone()[0]
check('however many pages are viewed after', n == 1, n)
body = db().execute("select body, target_url from notifications where type='content.unreadable'").fetchone()
check('with the file name, the parser message and a link to the editor', body and 'broken.md' in body[0] and 'Malformed inline YAML' in body[0] and 'slug=broken' in body[1], body)
st, _, html = root.get('/admin/search?q=broken'); check('admin search still works', st == 200 and 'Fatal error' not in html, st)
st, _, html = root.get('/admin'); check('so does the dashboard', st == 200 and 'Fatal error' not in html, st)

# ---- the editor opens, with the raw text to fix
st, _, html = root.get('/admin/edit?type=pages&slug=broken&lang=el')
check('the editor opens', st == 200 and 'Fatal error' not in html, st)
check('it says what is wrong', 'The front matter of this file cannot be read' in html and 'Malformed inline YAML' in html)
ta = re.search(r'<textarea name="frontmatter"[^>]*>(.*?)</textarea>', html, re.S)
check('and holds the raw text for fixing', ta is not None and 'reviewed together' in ta.group(1) and 'type: hero' in ta.group(1), ta and ta.group(1)[:120])

# ---- saving it while it is still broken writes nothing
form = next(f for f in root.forms('/admin/edit?type=pages&slug=broken&lang=el') if any(x[0] == 'body' for x in f['fields']))
fields = [tuple(x) for x in form['fields']]
st, hdr, _ = root.request(form['attrs'].get('action') or '/admin/save', data=fields)
loc = hdr.get('Location') or ''
check('saving unchanged sends you back with the reason', st == 302 and 'frontmatter_error=' in loc and 'slug=broken' in loc, (st, loc))
check('and the file is untouched', read('pages/broken.md') == BROKEN)
st, _, html = root.get(loc); check('the reason is shown there', 'cannot be read' in html and 'Malformed' in html, st)

# ---- fixing it
fixed = BROKEN.replace('{ title: Design, text: Structure and visuals, reviewed together. }', '{ title: Design, text: "Structure and visuals, reviewed together." }')
front = fixed.split('---\n')[1]
fields = [tuple(x) for x in form['fields'] if x[0] != 'frontmatter'] + [('frontmatter', front.strip())]
st, hdr, _ = root.request(form['attrs'].get('action') or '/admin/save', data=fields)
check('a fixed front matter saves', st == 302 and 'saved=1' in (hdr.get('Location') or ''), (st, hdr.get('Location')))
text = read('pages/broken.md')
check('the file reads again', 'Structure and visuals, reviewed together.' in text and 'The text of the page.' in text, text[:300])
st, _, html = pub.get('/broken')
check('and the page is public again', st == 200 and 'Fatal error' not in html, st)
st, _, html = root.get('/admin/content?type=pages')
row = re.search(r'<tr[^>]*>(?:(?!</tr>).)*/broken(?:(?!</tr>).)*</tr>', html, re.S)
check('the badge is gone', row is not None and 'Unreadable' not in row.group(0), row and row.group(0)[:200])

# ---- other kinds of broken
write('pages/scalar.md', '---\njust a string\n---\n\nText.\n')
st, _, _ = pub.get('/about'); check('front matter that is not a list of fields does not crash it either', st == 200)
write('posts/oops.md', '---\ntitle: [unclosed\n---\n\nText.\n')
st, _, html = pub.get('/posts'); check('nor a broken post in a listing', st in (200, 404) and 'Fatal error' not in html, st)
st, _, html = pub.get('/en/'); check('nor the English home page', st == 200 and 'Fatal error' not in html, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
