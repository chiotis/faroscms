"""
Pages and posts have their address at the root of the site (/about, /my-post); every other type keeps its name in front
(/projects/my-project). A post's old address under /posts/ leads to the new one, a page and a post never share an address,
and the list of posts is still at /posts.
"""
import re, sys, urllib.parse
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)
def front(title, extra=''): return f"---\ntitle: '{title}'\nstatus: published\nvisible: true\n{extra}---\n\nBody of {title}.\n"
def loc(h): return h.get('Location') or ''

root = Client(); root.login()
pub = Client()

write('posts/fresh-news.md', front('Fresh news', "date: '2026-02-01'\n"))
write('posts/fresh-news.en.md', front('Fresh news EN', "date: '2026-02-01'\n"))
write('pages/clash.md', front('Clash page'))
write('posts/clash.md', front('Clash post', "date: '2026-02-02'\n"))
write('projects/clean-project.md', front('Clean project', "date: '2026-02-03'\n"))

# ---- a post at the root, as a page is
st, _, html = pub.get('/fresh-news')
check('a post is at the root of the site', st == 200 and 'Fresh news' in html and 'Body of Fresh news' in html, st)
st, _, html = pub.get('/en/fresh-news')
check('and a translation under its language', st == 200 and 'Fresh news EN' in html, st)
st, h, _ = pub.get('/posts/fresh-news')
check('its old address under /posts/ leads there, for good', st == 301 and loc(h) == '/fresh-news', (st, loc(h)))
st, h, _ = pub.get('/en/posts/fresh-news')
check('also in another language', st == 301 and loc(h) == '/en/fresh-news', (st, loc(h)))
st, _, _ = pub.get('/posts/no-such-post')
check('an old address of a post that does not exist is a not found', st == 404, st)
st, _, _ = pub.get('/fresh-news/more')
check('nothing after the address is another address for it', st == 404, st)
st, _, _ = pub.get('/nothing-at-all')
check('and an address nobody has is a not found', st == 404, st)

# ---- the list of posts, and where it links
st, _, html = pub.get('/posts')
check('the list of posts is still at /posts', st == 200 and 'Fresh news' in html, st)
hrefs = re.findall(r'href="([^"]*)"', html)
check('and links to each post at its own address', '/fresh-news' in hrefs and '/posts/fresh-news' not in hrefs, [h for h in hrefs if 'fresh' in h])
st, _, html = pub.get('/search?q=Fresh')
hrefs = re.findall(r'href="([^"]*)"', html)
check('the search links to it there too', '/fresh-news' in hrefs and '/posts/fresh-news' not in hrefs, [h for h in hrefs if 'fresh' in h])

# ---- the other types keep their name
st, _, html = pub.get('/projects/clean-project')
check('a project keeps its type in the address', st == 200 and 'Clean project' in html, st)
st, _, _ = pub.get('/clean-project')
check('and is not at the root', st == 404, st)

# ---- the sitemap, and the address a page says is its own
st, _, xml = pub.get('/sitemap.xml')
locs = re.findall(r'<loc>([^<]*)</loc>', xml)
check('the sitemap lists the post at its address, and not at the old one', any(l.endswith('/fresh-news') for l in locs) and any(l.endswith('/en/fresh-news') for l in locs) and not any('/posts/fresh-news' in l for l in locs), [l for l in locs if 'fresh' in l])
check('and a project at its own', any(l.endswith('/projects/clean-project') for l in locs))
st, _, html = pub.get('/fresh-news')
canonical = re.search(r'<link rel="canonical" href="([^"]*)"', html)
check('the address the page gives as its own is the clean one', canonical is not None and canonical.group(1).endswith('/fresh-news') and '/posts/' not in canonical.group(1), canonical.group(1) if canonical else None)

# ---- a page and a post that were made with one address: the page is shown, and the post can still be read
st, _, html = pub.get('/clash')
check('when a page and a post have one address, the page is what is shown', st == 200 and 'Clash page' in html and 'Clash post' not in html, st)
st, _, html = pub.get('/posts/clash')
check('and the post, which would otherwise be hidden, stays readable where it was', st == 200 and 'Clash post' in html, st)

# ---- a new post cannot take the address of a page, or a word the site uses
def save(type_, title, slug=''):
    st, hdr, _ = root.submit(f'/admin/edit?type={type_}&slug=&lang=el', has_field('body'), {'title': title, 'body': 'Text', 'slug': slug} if slug else {'title': title, 'body': 'Text'})
    m = re.search(r'slug=([^&]+)', loc(hdr))
    return st, urllib.parse.unquote(m.group(1)) if m else None
st, slug = save('posts', 'Clash')
check('a new post cannot take the address of a page: it gets another', st in (302, 303) and slug == 'clash-2', (st, slug))
write('pages/taken.md', front('Taken'))
st, slug = save('posts', 'Taken')
check('whatever the page is called', st in (302, 303) and slug == 'taken-2', (st, slug))
write('posts/only-post.md', front('Only post', "date: '2026-02-04'\n"))
st, slug = save('pages', 'Only post')
check('and a new page cannot take the address of a post', st in (302, 303) and slug == 'only-post-2', (st, slug))
st, slug = save('posts', 'Search')
check('a new post cannot take a word the site uses', st in (302, 303) and slug == 'search-page', (st, slug))
st, slug = save('posts', 'Projects')
check('such as the name of a type', st in (302, 303) and slug == 'projects-page', (st, slug))

print('ALL PASSED' if not fails else 'FAILED: ' + ', '.join(fails))
sys.exit(1 if fails else 0)
