import json, re, sys
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:400]))
    if not ok: fails.append(label)

def graph(c, path):
    """The nodes of the page's JSON-LD, by type (the raw text is kept too, to check it cannot end the script tag)."""
    st, _, html = c.get(path)
    blocks = re.findall(r'<script type="application/ld\+json">(.*?)</script>', html, re.S)
    nodes = {}
    for raw in blocks:
        data = json.loads(raw)
        assert data['@context'] == 'https://schema.org', data['@context']
        for n in data['@graph']:
            nodes.setdefault(n['@type'], []).append(n)
    return st, nodes, blocks, html

def save(c, type_, slug, fields, lang='en'):
    f = {'title': slug.replace('-', ' ').title(), 'body': 'Text', 'blocks_editor': '0'}; f.update(fields)
    return c.submit(f'/admin/edit?type={type_}&slug={slug}&lang={lang}', has_field('body'), f)

root = Client(); root.login()
anon = Client()

# ---- the site: organization, website, search
st, n, blocks, _ = graph(anon, '/en/')
check('the home page has an organization', st == 200 and len(n.get('Organization', [])) == 1 and n['Organization'][0]['@id'].endswith('#organization'), list(n))
site = n.get('WebSite', [{}])[0]
check('and a website with the language and the publisher', site.get('inLanguage') == 'en' and site.get('publisher') == {'@id': n['Organization'][0]['@id']} and site.get('url', '').endswith('/en/'), site)
sa = site.get('potentialAction', {})
check('the website offers the site search', sa.get('@type') == 'SearchAction' and sa['target']['urlTemplate'].endswith('/en/search?q={search_term_string}') and sa.get('query-input') == 'required name=search_term_string', sa)
st, nl, _, _ = graph(anon, '/')
check('and the default language has its own address in it', nl['WebSite'][0]['url'].rstrip('/') == BASE.rstrip('/') or nl['WebSite'][0]['url'].endswith('/'), nl['WebSite'][0]['url'])
check('the search page the search action points at exists', anon.get('/en/search?q=rebrand')[0] == 200)
st, n2, _, _ = graph(anon, '/en/about')
check('the website is only offered on the home page, its pages point to it', 'WebSite' not in n2 and n2['WebPage'][0]['isPartOf']['@type'] == 'WebSite', list(n2))

# ---- a post with everything
save(root, 'posts', 'full-post', {'author': 'Jane Doe', 'date': '2026-03-04', 'excerpt': 'Short summary of the post.', 'main_image': '/uploads/media/faros-demo-lake.jpg'})
st, n, blocks, html = graph(anon, '/en/posts/full-post')
post = n.get('BlogPosting', [{}])[0]
check('a post is a BlogPosting', st == 200 and len(n.get('BlogPosting', [])) == 1, list(n))
check('with its title, address, and language', post.get('headline') == 'Full Post' and post['url'].endswith('/en/posts/full-post') and post['mainEntityOfPage'] == {'@type': 'WebPage', '@id': post['url']} and post['inLanguage'] == 'en', post)
check('the author is the person named in the editor', post.get('author') == {'@type': 'Person', 'name': 'Jane Doe'}, post.get('author'))
check('the publisher is the organization', post.get('publisher') == {'@id': n['Organization'][0]['@id']})
check('it has the day it was published', post.get('datePublished', '').startswith('2026-03-04'), post.get('datePublished'))
check('and when it was changed, never before it was published', post.get('dateModified', '') >= post.get('datePublished', 'x'), (post.get('dateModified'), post.get('datePublished')))
check('and the summary', post.get('description') == 'Short summary of the post.')
check('and the picture, as a full address', post.get('image', '').startswith('http') and post['image'].endswith('/uploads/media/faros-demo-lake.jpg'), post.get('image'))
check('the breadcrumb names where it is', [i['name'] for i in n['BreadcrumbList'][0]['itemListElement']][-1] == 'Full Post')

# ---- a post with nothing: the organization is the author, and the site's picture stands in
save(root, 'posts', 'bare-post', {})
st, n, _, _ = graph(anon, '/en/posts/bare-post')
bare = n['BlogPosting'][0]
check('with no author named, the organization is the author', bare['author'] == {'@id': n['Organization'][0]['@id']}, bare['author'])
import datetime
check('a new post the editor dated today says so', bare.get('datePublished', '').startswith(datetime.date.today().isoformat()), bare.get('datePublished'))
check('with no picture, there is none', 'image' not in bare, bare.get('image'))

# ---- a date the YAML reader turned into a number, and a long title
save(root, 'posts', 'stamp-post', {'date': '1768867200', 'title': 'T' * 200})
st, n, _, _ = graph(anon, '/en/posts/stamp-post')
stamp = n['BlogPosting'][0]
check('a date that is a Unix time is understood', stamp.get('datePublished', '').startswith('2026-01-20'), stamp.get('datePublished'))
check('a title is cut at 110 characters for the headline', len(stamp['headline']) == 110, len(stamp['headline']))

# ---- the picture falls back: SEO image, then the first in the blocks, then the site's
save(root, 'posts', 'seo-post', {'seo_og_image': '/uploads/media/faros-demo-path.jpg', 'blocks_editor': '1', 'blocks_json': json.dumps([{'type': 'hero', 'heading': 'Hi', 'image': '/uploads/media/faros-demo-rocks.jpg'}])})
st, n, _, _ = graph(anon, '/en/posts/seo-post')
img = n['BlogPosting'][0].get('image', '')
check('the SEO share image comes before the blocks', img.endswith('faros-demo-path.jpg'), img)
save(root, 'posts', 'block-post', {'blocks_editor': '1', 'blocks_json': json.dumps([{'type': 'hero', 'heading': 'Hi', 'image': '/uploads/media/faros-demo-rocks.jpg'}])})
st, n, _, _ = graph(anon, '/en/posts/block-post')
check('otherwise the first picture in its blocks', n['BlogPosting'][0].get('image', '').endswith('faros-demo-rocks.jpg'), n['BlogPosting'][0].get('image'))

# ---- projects are articles
save(root, 'projects', 'full-project', {'author': 'The Team', 'date': '2025-11-02', 'excerpt': 'What we did.', 'main_image': '/uploads/media/faros-demo-trees.jpg'})
st, n, _, _ = graph(anon, '/en/projects/full-project')
proj = n.get('Article', [{}])[0]
check('a project is an Article', st == 200 and len(n.get('Article', [])) == 1 and 'BlogPosting' not in n, list(n))
check('with author, date, picture, and summary', proj.get('author', {}).get('name') == 'The Team' and proj.get('datePublished', '').startswith('2025-11-02') and proj.get('image', '').endswith('faros-demo-trees.jpg') and proj.get('description') == 'What we did.', proj)
check('and a breadcrumb through Projects', [i['name'] for i in n['BreadcrumbList'][0]['itemListElement']][1:] == ['Projects', 'Full Project'], n['BreadcrumbList'][0]['itemListElement'])

# ---- pages
save(root, 'pages', 'plain-page', {'excerpt': 'A plain page.'})
st, n, _, _ = graph(anon, '/en/plain-page')
wp = n.get('WebPage', [{}])[0]
check('a page is a WebPage with its name, address, language, and description', wp.get('name') == 'Plain Page' and wp['url'].endswith('/en/plain-page') and wp['inLanguage'] == 'en' and wp.get('description') == 'A plain page.' and wp['isPartOf']['url'].endswith('/en/'), wp)
check('a page is not an article', 'BlogPosting' not in n and 'Article' not in n)

# ---- text in a title cannot break out of the script tag
save(root, 'posts', 'evil-post', {'title': 'Bad </script><script>alert(1)</script> & "quotes"', 'author': "O'Neil </script>"})
st, n, blocks, html = graph(anon, '/en/posts/evil-post')
ld = ''.join(blocks)
check('markup in a title or author cannot end the script tag', st == 200 and '</script>' not in ld and '<script>' not in ld and n['BlogPosting'][0]['headline'].startswith('Bad </script>'), ld[:300])

# ---- contact point from the footer settings
before = anon.get('/en/')[2]
check('no contact point until the footer has a phone or an email', 'ContactPoint' not in before)
root.submit('/admin/theme', lambda f: any(x[0].startswith('theme_settings') for x in f['fields']), {'theme_settings[footer][phone]': '+30 210 0000000', 'theme_settings[footer][email]': 'hello@example.test'})
st, n, _, _ = graph(anon, '/en/')
cp = n['Organization'][0].get('contactPoint', {})
check('then the organization has one', cp.get('telephone') == '+30 210 0000000' and cp.get('email') == 'hello@example.test' and cp.get('@type') == 'ContactPoint', cp)

# ---- every page of the site still gives valid JSON-LD
bad = []
for path in ['/', '/en/', '/en/about', '/en/posts', '/en/posts/full-post', '/en/projects', '/en/search?q=x', '/en/tag/strategy', '/en/nothing-here']:
    try: graph(anon, path)
    except Exception as e: bad.append((path, repr(e)[:80]))
check('every kind of public page gives JSON-LD that parses', not bad, bad)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
