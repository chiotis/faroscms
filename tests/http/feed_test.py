import os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
def save(overrides=None, drop=None):
    return root.submit('/admin/seo?tab=crawling', has_field('sitemap_exclude'), overrides, drop=drop)
def items(xml): return re.findall(r'<item>.*?</item>', xml, re.S)

# ---- the admin
st, _, html = root.get('/admin/seo?tab=crawling')
check('Crawling & sitemap has a Feed card, on by default', st == 200 and 'Publish a feed' in html and re.search(r'name="feed_enabled"[^>]*checked', html) is not None)
check('with the number of entries', 'name="feed_items"' in html and 'Entries in a feed' in html)

# ---- the site's feed
st, h, xml = anon.get('/feed')
check('the site has a feed, as RSS', st == 200 and 'application/rss+xml' in h.get('Content-Type', '') and xml.startswith('<?xml') and '<rss version="2.0"' in xml, (st, h.get('Content-Type')))
posts = items(xml)
check('it lists the post with its address, date and excerpt', len(posts) == 1 and '/rebrand-readiness-guide</link>' in posts[0] and '<pubDate>' in posts[0] and '<description>' in posts[0], posts)
check('and it names itself', 'rel="self"' in xml and '/feed"' in xml)
st, _, home = anon.get('/')
link = re.search(r'<link rel="alternate" type="application/rss\+xml" title="[^"]*" href="([^"]+)"', home)
check('every page announces it in the head', link is not None and link.group(1).endswith('/feed'), home[:600])
st, _, home = anon.get('/en')
link = re.search(r'<link rel="alternate" type="application/rss\+xml" title="[^"]*" href="([^"]+)"', home)
check('and a page of another language announces that language\'s', link is not None and link.group(1).endswith('/en/feed'), link and link.group(1))
st, h, xml = anon.get('/en/feed')
check('the feed of the other language lists its posts', st == 200 and '<language>en</language>' in xml and len(items(xml)) == 1, xml[:400])

# ---- feeds of a type and of a term
st, h, xml = anon.get('/posts/feed')
check('a type has a feed', st == 200 and '<rss' in xml and len(items(xml)) == 1, st)
st, h, xml = anon.get('/projects/feed')
check('a type with entries in the other language only has an empty feed', st == 200 and len(items(xml)) == 0, st)
st, h, xml = anon.get('/en/projects/feed')
check('its entries are in their language\'s feed', st == 200 and len(items(xml)) == 1 and 'Gamma' in xml, xml[:300])
st, h, xml = anon.get('/tag/design/feed')
check('a tag has a feed with what is filed under it', st == 200 and len(items(xml)) == 1 and '<title>' in xml, st)
st, h, xml = anon.get('/tag/growth/feed')
check('a tag with no entries in this language has an empty feed, not a missing one', st == 200 and len(items(xml)) == 0, (st, xml[:900]))
check('a term that does not exist is a not found', anon.get('/tag/nope/feed')[0] == 404)
check('so is the feed of a page', anon.get('/about/feed')[0] == 404)
check('and of forms', anon.get('/forms/feed')[0] == 404)

# ---- a page called feed is still its own
open('app/content/pages/feed.md', 'w', encoding='utf-8').write("---\ntitle: My feed page\nstatus: published\nvisible: true\n---\n\nThis is a page.\n")
st, h, html = anon.get('/feed')
check('a page with that name is shown instead of the feed', st == 200 and 'This is a page.' in html and '<rss' not in html, st)
os.remove('app/content/pages/feed.md')
check('and the feed is back when it goes', '<rss' in anon.get('/feed')[2])

# ---- the number of entries
for i in range(1, 8):
    open('app/content/posts/more-%d.md' % i, 'w', encoding='utf-8').write("---\ntitle: More %d\nstatus: published\nvisible: true\ndate: '2026-02-0%d'\n---\n\nText %d.\n" % (i, i, i))
st, _, xml = anon.get('/feed')
check('every post is there', len(items(xml)) == 8, len(items(xml)))
save({'feed_items': '5'})
check('and a feed is held to the number the site chose', len(items(anon.get('/feed')[2])) == 5)
check('newest first', items(anon.get('/feed')[2])[0].count('Rebrand') == 0 and 'More 7' in items(anon.get('/feed')[2])[0], items(anon.get('/feed')[2])[0])
save({'feed_items': '20'})

# ---- switched off
save({}, drop=['feed_enabled'])
check('a feed that is switched off is not there', anon.get('/feed')[0] == 404 and anon.get('/posts/feed')[0] == 404)
check('and is not announced', 'application/rss+xml' not in anon.get('/')[2])
save({'feed_enabled': '1'})
check('and it comes back', anon.get('/feed')[0] == 200 and 'application/rss+xml' in anon.get('/')[2])

# ---- the pages of a list, as WordPress wrote them
st, h, _ = anon.get('/tag/design/page/2/')
check('/tag/design/page/2/ goes to ?page=2 for good', st == 301 and h.get('Location', '').endswith('/tag/design?page=2'), (st, h.get('Location')))
st, h, _ = anon.get('/posts/page/3')
check('also for a type', st == 301 and h.get('Location', '').endswith('/posts?page=3'), (st, h.get('Location')))
st, h, _ = anon.get('/en/tag/design/page/1')
check('the first page is the list itself, in the same language', st == 301 and h.get('Location', '').endswith('/en/tag/design'), (st, h.get('Location')))
check('an address that is no list stays a not found', anon.get('/about/page/2')[0] == 404 and anon.get('/page/2')[0] == 404)

print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
