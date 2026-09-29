import re, sys, sqlite3
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

def run(sql, args=()):
    c = sqlite3.connect('app/storage/db/app.sqlite'); c.execute(sql, args); c.commit(); c.close()

def layouts(default='default', **per_type):
    """Choose the title layout the way Theme settings > Hero Layouts stores it: one per content type,
    with the general choice for the types that have none (the settings screen always stores all four)."""
    per_type = {**{t: default for t in ('pages', 'posts', 'projects', 'forms')}, **per_type}
    per_type['default'] = default
    body = 'hero_layouts:\n' + ''.join('  %s: %s\n' % (k, v) for k, v in per_type.items())
    run("insert or replace into system_meta (key, value, updated_at) values ('theme_settings', ?, datetime('now'))", (body,))

pub = Client()
def header(path):
    st, _, html = pub.get(path)
    m = re.search(r'<section class="(single-hero[^"]*)"[^>]*>(.*?)</section>', html, re.S)
    return st, (m.group(1) if m else ''), (m.group(2) if m else ''), html

post = '/posts/rebrand-readiness-guide'
project = '/projects/gamma-hospitality-rebrand'
st, _, _, _ = header(post)
if st != 200:
    post = '/rebrand-readiness-guide'
st, _, _, _ = header('/en/projects/gamma-hospitality-rebrand')
project = '/en/projects/gamma-hospitality-rebrand' if st == 200 else '/projects/gamma-hospitality-rebrand'

# A post with the standard template (the fixture posts use the sidebar one, which has no line above the title).
open('app/content/posts/plain.md', 'w', encoding='utf-8').write("---\ntitle: Plain post\nstatus: published\nvisible: true\ndate: '2026-03-01'\nauthor: Ada\nexcerpt: A short summary.\ntags: [design]\ncategories: [news]\nmain_image: /uploads/media/5e6915a67b9ceec5.jpg\n---\n\nText.\n")
plain = post.rsplit('/', 1)[0] + '/plain'

# ---- the layouts that already existed keep their markup
layouts(default='default')
st, cls, inner, _ = header('/about')
check('default: a plain title header', st == 200 and cls == 'single-hero' and '<h1>Σχετικά</h1>' in inner and 'single-hero-subtitle' in inner, (st, cls))
st, cls, inner, _ = header(plain)
check('default: a post shows its tags and categories above the title', 'has-image' in cls and 'class="single-hero-meta"' in inner and 'design' in inner.lower() and 'A short summary.' in inner, (cls, inner[:300]))
layouts(default='centered')
st, cls, inner, _ = header(plain)
check('centered: the byline is worded for reading across', 'single-hero-centered' in cls and 'single-hero-meta-inline' in inner and 'Ada' in inner and 'single-hero-media' in inner, (cls, inner[:300]))

# ---- split
layouts(default='split')
st, cls, inner, html = header('/about')
check('split without an image: one column of text', 'single-hero-split' in cls and 'no-media' in cls and 'single-hero-content' in inner and 'single-hero-media' not in inner, (cls, inner[:200]))
st, cls, inner, html = header(post)
check('split with an image: text beside a picture', 'single-hero-split' in cls and 'no-media' not in cls and 'single-hero-content' in inner and 'single-hero-media' in inner and '<img' in inner, (cls, inner[:300]))
check('split: the title stays the only h1', html.count('<h1') == 1, html.count('<h1'))

# ---- cover
layouts(default='cover')
st, cls, inner, html = header(post)
check('cover with an image: a full-width picture behind the text', 'single-hero-cover' in cls and 'single-hero-cover-media' in inner and '<img' in inner and '<h1>' in inner, (cls, inner[:300]))
check('cover: the picture loads first, not lazily', 'loading="lazy"' not in inner, inner[:400])
st, cls, inner, html = header('/about')
check('cover without an image falls back to the default look', cls == 'single-hero' and 'single-hero-cover-media' not in inner and '<h1>Σχετικά</h1>' in inner, (cls, inner[:200]))

# ---- minimal
st, cls, inner, html = header(plain)
check('cover: the byline sits above the title', 'single-hero-meta-inline' in inner and 'Ada' in inner, inner[:300])
layouts(default='minimal')
st, cls, inner, html = header(plain)
check('minimal: only text, even when the page has an image', 'single-hero-minimal' in cls and '<img' not in inner and 'single-hero-media' not in inner and '<h1>' in inner, (cls, inner[:300]))

# ---- per content type, and unknown values
layouts(default='default', pages='split', posts='cover', projects='minimal')
check('each content type has its own layout', [header('/about')[1].split()[1] if header('/about')[1] != 'single-hero' else '', 'single-hero-cover' in header(post)[1], 'single-hero-minimal' in header(project)[1]] == ['single-hero-split', True, True], (header('/about')[1], header(post)[1], header(project)[1]))
layouts(default='nonsense')
st, cls, inner, _ = header('/about')
check('an unknown layout shows the default one', st == 200 and cls == 'single-hero', (st, cls))
layouts(default='centered', pages='default')
st, cls, _, _ = header('/about'); check('a type set to default beats the general choice', cls == 'single-hero', cls)

# ---- the form page and the sidebar template use the same header
run("delete from system_meta where key='theme_settings'")
st, _, html = pub.get('/about'); check('with no choice made the pages still render', st == 200 and 'single-hero' in html, st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
