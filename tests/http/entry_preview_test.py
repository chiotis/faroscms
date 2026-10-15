import hashlib, json, os, re, sqlite3, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()

# an editor (may not add raw HTML) and a plain user (may not edit content at all)
for username, role in (('prev_ed', 'editor'), ('prev_user', 'user')):
    root.submit('/admin/users-edit', has_field('username'),
        {'username': username, 'email': username + '@example.test', 'display_name': username, 'role': role, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('prev_ed', 'Sturdy-pass-99')
user = Client(); user.login('prev_user', 'Sturdy-pass-99')

EDIT = '/admin/edit?type=pages&slug=about&lang=en'
ENDPOINT = '/admin/edit?preview=entry'

def snapshot():
    """Every file of the content folder (and its checksum), and the number of rows in the history."""
    files = {}
    for base, _, names in os.walk('app/content'):
        for n in names:
            p = os.path.join(base, n)
            files[p] = hashlib.md5(open(p, 'rb').read()).hexdigest()
    db = sqlite3.connect('app/storage/db/app.sqlite')
    rows = db.execute('select count(*) from content_revisions').fetchone()[0]
    redirects = sorted(glob for glob in os.listdir('app/content') if 'redirect' in glob)
    return files, rows, redirects

def preview(client, overrides=None, edit=EDIT, drop=None):
    """POST the editor form of `edit` (with the changes in `overrides`) to the preview, as the page does."""
    form = next(f for f in client.forms(edit) if f['attrs'].get('id') == 'entry-form' or any(x[0] == 'blocks_json' for x in f['fields']))
    fields = {k: v for k, v in form['fields']}
    fields.update(overrides or {})
    for k in drop or []:
        fields.pop(k, None)
    st, hdr, text = client.request(ENDPOINT, data=list(fields.items()))
    try:
        return st, json.loads(text)
    except ValueError:
        return st, {'raw': text}

def blocks(*items): return {'blocks_editor': '1', 'blocks_json': json.dumps(list(items))}

# ---- the screen
st, _, html = root.get(EDIT)
check('the editor screen has the toggle, the panel (hidden) and the scripts', 'data-entry-preview-toggle' in html and 'aria-expanded="false"' in html and re.search(r'data-entry-preview[^>]*data-mode="entry"[^>]*hidden', html) and 'js/admin-entry-preview.js' in html and 'js/admin-preview.js' in html, st)
check('the frame has a title and the endpoint is the entry preview', 'title="Preview of this page, as it is now"' in html and 'admin/edit?preview=entry' in html)

# ---- unsaved blocks, front matter, title and body are drawn
before = snapshot()
st, a = preview(root, dict(title='Unsaved title', body='Unsaved **body** text', **blocks(
    {'type': 'hero', 'heading': 'Unsaved hero'},
    {'type': 'text', 'heading': 'Hidden one', 'hidden': True},
    {'type': 'text', 'heading': 'Second block', 'body': 'Unsaved words', 'width': '50'},
    {'type': 'text', 'heading': 'Third block', 'body': 'More words', 'width': '50'})))
html = a.get('html', '')
check('the preview answers 200 with the page', st == 200 and html.startswith('<!DOCTYPE html>') or '<html' in html[:400], (st, list(a)))
check('it draws the unsaved blocks through the theme', 'Unsaved hero' in html and 'Second block' in html and 'Unsaved words' in html and 'block-row' in html, html[:200])
check('a hidden block is not drawn', 'Hidden one' not in html)
check('the stored page is not what is drawn', 'Unsaved hero' not in anon.get('/about')[2])
check('each block says where it stands in the editor list, hidden ones counted', [int(x) for x in re.findall(r'data-bk-block="(\d+)"', html)] == [0, 2, 3], re.findall(r'data-bk-block="(\d+)"', html))
check('the public page has no such marks', 'data-bk-block' not in anon.get('/about')[2])
check('the page title is the unsaved one', 'Unsaved title' in html)

# ---- the Markdown body shows when there are no blocks
st, b = preview(root, dict(title='Only text', body='A paragraph with **strong** words'), drop=['blocks_editor', 'blocks_json'])
check('without blocks the Markdown body is drawn', '<strong>strong</strong>' in b.get('html', ''), st)

# ---- front matter typed in Advanced, and a template
st, c = preview(root, dict(frontmatter="seo:\n  title: 'Preview SEO title'\n"), drop=['seo_title', 'seo_description', 'seo_canonical', 'seo_og_title', 'seo_og_description', 'seo_og_image', 'seo_noindex'])
check('the front matter of the form is read', 'Preview SEO title' in c.get('html', ''), st)
st, d = preview(root, dict(frontmatter="title: [unclosed\n  - x: : :"))
check('front matter that cannot be read gives an error and no page', st == 200 and 'error' in d and 'html' not in d, d)

# ---- a new entry that does not exist yet
st, n = preview(root, dict(title='Brand new idea', slug='', body='Fresh text', original_slug='', original_lang=''), edit='/admin/edit?type=pages&slug=&lang=en', drop=['blocks_editor', 'blocks_json'])
check('an entry that is not saved yet can be previewed', 'Fresh text' in n.get('html', '') and 'Brand new idea' in n.get('html', ''), (st, str(n)[:150]))
st, p = preview(root, dict(title='A post, unsaved', body='Post body here', original_slug='', original_lang='', slug=''), edit='/admin/edit?type=posts&slug=&lang=en', drop=['blocks_editor', 'blocks_json'])
check('so can a post', 'Post body here' in p.get('html', ''), (st, str(p)[:150]))

# ---- nothing is written
after = snapshot()
check('nothing was written: not a file, not a history row, not a redirect', before == after, [k for k in set(before[0]) | set(after[0]) if before[0].get(k) != after[0].get(k)] or (before[1:], after[1:]))
check('no file for the new entries', not [f for f in after[0] if 'brand-new' in f or 'a-post-unsaved' in f])
st, _, edit_html = root.get(EDIT)
check('the stored page still has its own title', 'Unsaved title' not in edit_html)

# ---- permissions
def token(client): return re.search(r'name="_csrf" value="([^"]+)"', client.get('/admin')[2]).group(1)
st, _, text = Client().request(ENDPOINT, data=[('type', 'pages'), ('title', 'x')])
check('signed out: refused', st in (302, 303, 401, 403, 419) and '"html":' not in text, st)
st, _, text = user.request(ENDPOINT, data=[('_csrf', token(user)), ('type', 'pages'), ('title', 'x')])
check('a user who may not edit content is refused', st in (302, 303, 403) and '"html"' not in text, (st, text[:100]))
st, _, text = ed.request(ENDPOINT, data=[('_csrf', token(ed)), ('type', 'forms'), ('title', 'x')])
check('forms are not previewed (they need the forms right and have their own builder)', '"html"' not in text, (st, text[:100]))
st, e = preview(ed)
check('an editor may preview', st == 200 and '<html' in e.get('html', ''), (st, str(e)[:120]))
st, _, text = root.request(ENDPOINT, data=[('title', 'x')])
check('a post without the CSRF token is refused', st in (302, 303, 400, 403, 419) and '"html":' not in text, st)

# ---- an editor does not get raw HTML through the preview; an administrator does
raw = '<script>window.__x=1</script><iframe src="https://example.test/embed"></iframe>'
mine = dict(title='Raw', body=raw, **blocks({'type': 'text', 'heading': 'H', 'body': raw}))
st, ee = preview(ed, mine)
eh = ee.get('html', '')
check('for an editor raw HTML is shown as text, not run', '<script>window.__x=1</script>' not in eh and '<iframe src="https://example.test/embed"' not in eh and '&lt;script&gt;' in eh, eh[eh.find('window.__x') - 40: eh.find('window.__x') + 80] if 'window.__x' in eh else 'not in page')
st, aa = preview(root, mine)
check('an administrator sees what Save would keep', '<iframe src="https://example.test/embed"' in aa.get('html', ''), st)

print('ALL PASSED' if not fails else 'FAILED: %d' % len(fails))
sys.exit(1 if fails else 0)
