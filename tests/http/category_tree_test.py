import os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()
def loc(h): return h.get('Location') or ''
def is_tax_form(f): return any(x[0] == 'taxonomy_title' for x in f['fields'])
def save_terms(taxonomy, rows, extra=None):
    """rows: (id, slug, parent, el, en)"""
    data = {'term_id[]': [r[0] for r in rows], 'term_slug[]': [r[1] for r in rows], 'term_parent[]': [r[2] for r in rows],
            'term_label[el][]': [r[3] for r in rows], 'term_label[en][]': [r[4] for r in rows],
            'term_description[el][]': ['' for r in rows], 'term_description[en][]': ['' for r in rows]}
    data.update(extra or {})
    return root.submit('/admin/taxonomies?taxonomy=' + taxonomy, is_tax_form, data)
def post(slug, categories, date='2026-02-01', lang=''):
    open('app/content/posts/%s%s.md' % (slug, '.' + lang if lang else ''), 'w', encoding='utf-8').write(
        "---\ntitle: %s\nstatus: published\nvisible: true\ndate: '%s'\ncategories: [%s]\n---\n\nText.\n" % (slug, date, ', '.join(categories)))
def items(xml): return re.findall(r'<item>.*?</item>', xml, re.S)

NEWS = ('news', 'news', '', 'Νέα', 'News')
CASES = ('case-studies', 'case-studies', 'news', 'Μελέτες Περίπτωσης', 'Case Studies')
BOOKS = ('books', 'books', '', 'Βιβλία', 'Books')

# ---- the screen
st, _, html = root.get('/admin/taxonomies?taxonomy=categories')
check('categories are a tree by default: rows carry a parent, the dialog picks one', st == 200 and 'name="term_parent[]"' in html and 'data-dialog-parent' in html and 'data-hierarchical="1"' in html, st)
check('and the settings can turn the tree off', 'name="taxonomy_hierarchical"' in html)
st, _, html = root.get('/admin/taxonomies?taxonomy=tags')
check('tags are not: no parent anywhere', 'name="term_parent[]"' not in html and 'data-dialog-parent' not in html and 'data-hierarchical' not in html, st)

# ---- a flat site is unchanged
post('flat-post', ['case-studies'])
check('before any parent: the address is the slug', root.get('/category/case-studies')[0] == 200 and pub.get('/category/news/case-studies')[0] == 404)

# ---- giving a term a parent
post('tree-post', ['case-studies'], '2026-03-01')
post('news-post', ['news'], '2026-01-01')
st, hdr, _ = save_terms('categories', [NEWS, CASES])
check('saving goes back to the screen', st == 302 and 'saved=1' in loc(hdr) and 'moved=1' in loc(hdr), loc(hdr))
check('the child is at its address in the tree', pub.get('/category/news/case-studies')[0] == 200)
st, h, _ = pub.get('/category/case-studies')
check('its old address leads to the new one for good', st == 301 and loc(h).endswith('/category/news/case-studies'), (st, loc(h)))
st, h, _ = pub.get('/en/category/case-studies')
check('and so does the other language\'s', st == 301 and loc(h).endswith('/en/category/news/case-studies'), (st, loc(h)))
st, _, html = pub.get('/category/news/case-studies')
check('the child lists its own entries', 'tree-post' in html and 'flat-post' in html and 'news-post' not in html, st)
st, _, html = pub.get('/category/news')
check('the parent lists its children\'s entries too', 'tree-post' in html and 'news-post' in html, st)
check('a child is not at the top, nor under a name that is not its parent', pub.get('/category/books/case-studies')[0] == 404 and pub.get('/category/news/case-studies/x')[0] == 404 and pub.get('/category/news/nothing')[0] == 404)
st, _, html = root.get('/admin/taxonomies?taxonomy=categories')
rows = re.findall(r'<tr data-term-row[^>]*>', html)
check('the screen lists the tree: the child after its parent, indented', len(rows) >= 2 and 'data-depth="1"' in rows[1] and 'margin-left: 1.25rem' in html and 'value="news" data-field="parent"' in html, [len(rows), 'data-depth="1"' in rows[1], 'margin-left: 1.25rem' in html, 'value="news" data-field="parent"' in html, re.findall(r'<input[^>]*term_parent[^>]*>', html)])
check('with the child\'s whole address', '/category/news/case-studies' in html)
st, _, html = root.get('/admin/redirects')
check('the redirect is in the Redirects list', '/category/case-studies' in html and '/category/news/case-studies' in html, st)

# ---- feeds
st, h, xml = pub.get('/category/news/case-studies/feed')
check('a child has a feed', st == 200 and 'application/rss+xml' in h.get('Content-Type', '') and len(items(xml)) == 2 and 'tree-post' in xml, (st, xml[:300]))
check('its feed names its own address', '/category/news/case-studies</link>' in xml)
st, _, xml = pub.get('/category/news/feed')
check('the parent\'s feed carries its children\'s entries', st == 200 and len(items(xml)) == 4, re.findall(r'<title>(.*?)</title>', xml))
check('a feed of a child that is not there is a not found', pub.get('/category/news/ghost/feed')[0] == 404)

# ---- pages of a list
for i in range(1, 15):
    post('bulk-%02d' % i, ['case-studies'], '2025-01-%02d' % i)
st, _, html = pub.get('/category/news/case-studies?page=2')
check('a child paginates with ?page=', st == 200 and 'bulk-' in html, st)
st, h, _ = pub.get('/category/news/case-studies/page/2')
check('and the WordPress /page/2 leads to it', st == 301 and loc(h).endswith('/category/news/case-studies?page=2'), (st, loc(h)))
st, h, _ = pub.get('/en/category/news/case-studies/page/3')
check('in another language too', st == 301 and loc(h).endswith('/en/category/news/case-studies?page=3'), (st, loc(h)))

# ---- the sitemap
st, _, xml = pub.get('/sitemap.xml')
check('the sitemap lists the child and the parent', '/category/news/case-studies<' in xml and '/category/news<' in xml and '/category/case-studies<' not in xml, st)

# ---- breadcrumbs follow the tree
st, _, html = pub.get('/category/news/case-studies')
check('no trail until a list asks for one', 'class="crumbs"' not in html)
form = next(f for f in root.forms('/admin/theme?tab=archive_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
check('the archive card has the sub-terms chip for categories only', 'name="archive_taxonomies[categories][include_children]"' in root.get('/admin/theme?tab=archive_layouts')[2] and 'name="archive_taxonomies[tags][include_children]"' not in root.get('/admin/theme?tab=archive_layouts')[2])
def theme_save(overrides, drop=()):
    form = next(f for f in root.forms('/admin/theme?tab=archive_layouts') if any(x[0] == 'active_tab' for x in f['fields']))
    fields = [tuple(x) for x in form['fields'] if x[0] not in overrides and x[0] not in drop and x[0] != 'active_tab']
    for k, v in overrides.items():
        for item in (v if isinstance(v, list) else [v]):
            fields.append((k, item))
    fields.append(('active_tab', 'archive_layouts'))
    return root.request('/admin/theme', data=fields)
theme_save({'archive_taxonomies[categories][breadcrumbs]': '1'})
st, _, html = pub.get('/category/news/case-studies')
trail = re.search(r'<nav class="crumbs"[^>]*>(.*?)</nav>', html, re.S)
check('the trail has Home, the parent with its link, then the term', trail is not None and len(re.findall(r'<li>', trail.group(1))) == 3 and 'href="/category/news"' in trail.group(1), trail and trail.group(1))
st, _, html = pub.get('/en/category/news/case-studies')
trail = re.search(r'<nav class="crumbs"[^>]*>(.*?)</nav>', html, re.S)
check('in another language the links keep the prefix', trail is not None and 'href="/en/category/news"' in trail.group(1), trail and trail.group(1))

# ---- the archive choice
theme_save({}, drop=['archive_taxonomies[categories][include_children]'])
st, _, html = pub.get('/category/news')
check('with the chip off the parent lists only its own entries', st == 200 and 'news-post' in html and 'tree-post' not in html, st)
fx = pub.get('/category/news/feed')[2]
check('and so does its feed', len(items(fx)) == 2, re.findall(r'<title>(.*?)</title>', fx))
theme_save({'archive_taxonomies[categories][include_children]': '1'})
check('and back on', 'tree-post' in pub.get('/category/news')[2])

# ---- a loop is refused
st, hdr, _ = save_terms('categories', [('news', 'news', 'case-studies', 'Νέα', 'News'), CASES])
check('a parent that would make a loop is refused and said', st == 302 and 'refused=1' in loc(hdr), loc(hdr))
check('nothing moved', pub.get('/category/news/case-studies')[0] == 200 and pub.get('/category/news')[0] == 200)
st, _, html = root.get(loc(hdr))
check('the screen says why', 'cannot be placed under itself' in html, st)

# ---- the same name under two parents
st, hdr, _ = save_terms('categories', [NEWS, CASES, BOOKS, ('books-case-studies', 'case-studies', 'books', 'Μελέτες', 'Case Studies')])
check('a slug can be used under two parents', st == 302 and pub.get('/category/books/case-studies')[0] == 200 and pub.get('/category/news/case-studies')[0] == 200, loc(hdr))
st, _, html = pub.get('/category/books/case-studies')
check('and they are different terms', 'tree-post' not in html, st)

# ---- moving a parent moves what is below it
st, hdr, _ = save_terms('categories', [('news', 'headlines', '', 'Νέα', 'News'), CASES, BOOKS, ('books-case-studies', 'case-studies', 'books', 'Μελέτες', 'Case Studies')])
check('renaming a parent changes the address of its children', pub.get('/category/headlines/case-studies')[0] == 200, loc(hdr))
st, h, _ = pub.get('/category/news/case-studies')
check('and the old address of the child leads to the new', st == 301 and loc(h).endswith('/category/headlines/case-studies'), (st, loc(h)))
st, h, _ = pub.get('/category/news')
check('as does the parent\'s', st == 301 and loc(h).endswith('/category/headlines'), (st, loc(h)))

# ---- deleting a parent moves its children up
st, hdr, _ = save_terms('categories', [CASES[:2] + ('',) + CASES[3:], BOOKS, ('books-case-studies', 'case-studies', 'books', 'Μελέτες', 'Case Studies')])
check('deleting a parent keeps its children, one level up', st == 302 and 'removed=1' in loc(hdr) and pub.get('/category/case-studies')[0] == 200, loc(hdr))
st, h, _ = pub.get('/category/headlines/case-studies')
check('and the child\'s address in the tree leads to where it is now', st == 301 and loc(h).endswith('/category/case-studies'), (st, loc(h)))
check('the entries stay filed under the child', 'tree-post' in pub.get('/category/case-studies')[2])

# ---- the tree can be turned off
st, hdr, _ = save_terms('categories', [CASES[:2] + ('',) + CASES[3:], BOOKS, ('books-case-studies', 'case-studies', 'books', 'Μελέτες', 'Case Studies')], {'taxonomy_hierarchical': ['0']})
st, _, html = root.get('/admin/taxonomies?taxonomy=categories')
check('with the tree off the parent field goes and each term has a flat address', 'name="term_parent[]"' not in html and pub.get('/category/books/case-studies')[0] == 301 and pub.get('/category/case-studies-2')[0] == 200, [st, 'name="term_parent[]"' not in html, pub.get('/category/books/case-studies')[0], pub.get('/category/case-studies-2')[0], re.findall(r'data-original-path="[^"]*"', html)])

# ---- a taxonomy of the site's own declares it
st, _, html = root.get('/admin/taxonomies')
token = next(x[1] for f in root.forms(html=html) for x in f['fields'] if x[0] == '_csrf')
check('the new taxonomy form offers a tree', 'name="new_hierarchical"' in html)
st, hdr, _ = root.request('/admin/taxonomies', data=[('_csrf', token), ('taxonomy', 'tags'), ('new_taxonomy', '1'), ('new_title', 'Regions'), ('new_name', 'regions'), ('new_hierarchical', '1')])
check('a taxonomy made as a tree is one', 'taxonomy=regions' in loc(hdr) and 'hierarchical: true' in open('app/content/taxonomies/regions.yaml').read(), loc(hdr))
st, hdr, _ = save_terms('regions', [('europe', 'europe', '', 'Ευρώπη', 'Europe'), ('greece', 'greece', 'europe', 'Ελλάδα', 'Greece')])
open('app/content/posts/trip.md', 'w', encoding='utf-8').write("---\ntitle: Trip\nstatus: published\nvisible: true\ndate: '2026-04-01'\nregions: [greece]\n---\n\nText.\n")
st, _, html = pub.get('/regions/europe/greece')
check('its pages follow the tree', st == 200 and 'trip' in html.lower(), st)
check('and the parent lists the child\'s entries', 'trip' in pub.get('/regions/europe')[2].lower())
check('the feed of a child term of a taxonomy of its own', len(items(pub.get('/regions/europe/greece/feed')[2])) == 1)

print('\nALL PASSED' if not fails else '\n%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
