import json, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
for u, r in (('ed1', 'editor'), ('usr1', 'user')):
    root.submit('/admin/users-edit', has_field('username'), {'username': u, 'email': u + '@example.test', 'display_name': u, 'role': r, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed, usr, anon = Client(), Client(), Client()
ed.login('ed1', 'Sturdy-pass-99'); usr.login('usr1', 'Sturdy-pass-99')

def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)
def read(path): return open('app/content/' + path, encoding='utf-8').read()
def token(c): return re.search(r'name="_csrf" value="([0-9a-f]+)"', c.get('/admin')[2]).group(1)
def draw(c, body, csrf=True, method=None):
    data = ([('_csrf', token(c))] if csrf else []) + [('body', body)]
    st, hdr, text = c.request('/admin/markdown-visual', data=data)
    try: return st, json.loads(text)
    except ValueError: return st, None

BODY = "## Intro\n\nSome **bold** and *italic* text.\n\n- one\n- two\n\n<iframe src=\"https://video.test/embed\" width=\"100%\"></iframe>\n\nLast paragraph.\n"
write('pages/visual.md', "---\ntitle: 'Visual'\nstatus: published\nvisible: true\n---\n\n" + BODY)

# ---- the screen
st, _, html = root.get('/admin/edit?type=pages&slug=visual&lang=el')
check('the editor screen opens, on the content', st == 200 and 'data-tab="basics"' in html and '>Content</button>' in html and 'Main content' in html)
check('the text is a visual editor over the textarea that is saved', 'data-ed-surface' in html and 'contenteditable="true"' in html and re.search(r'<textarea name="body"[^>]*data-md-body', html) is not None)
check('the textarea holds the Markdown as it is stored', '## Intro' in html and 'Some **bold** and *italic* text.' in html and '&lt;iframe src=&quot;https://video.test/embed&quot;' in html)
check('with a choice of Visual or Markdown, and the words saying what is saved', 'data-ed-mode="visual"' in html and 'data-ed-mode="markdown"' in html and 'Saved as Markdown' in html)
check('the visual editor draws through the server', 'data-endpoint="/admin/markdown-visual"' in html and 'js/admin-editor.js' in html and 'js/admin-entry.js' in html)
check('an administrator may write HTML, so the editor offers underline and snippets', 'data-raw-html="1"' in html and 'data-action="underline"' in html and 'data-action="snippet"' in html)
st, _, ed_html = ed.get('/admin/edit?type=pages&slug=visual&lang=el')
check('an editor may not, so the editor offers neither', st == 200 and 'data-raw-html="0"' in ed_html and 'data-action="underline"' not in ed_html and 'data-action="snippet"' not in ed_html)
check('the other parts of the screen are still there', all(w in html for w in ('name="title"', 'name="excerpt"', 'name="status"', 'name="visible"', 'name="seo_title"', 'name="main_image"', 'name="frontmatter"', 'data-panel="translations"', 'data-panel="custom"')))
check('the search result is shown as it will look', 'data-serp' in html and 'data-serp-title' in html)

# ---- the endpoint
st, data = draw(root, BODY)
check('it draws the Markdown block by block, with the lines of each', st == 200 and data and data['ok'] and [b['source'] for b in data['blocks']][:2] == ['## Intro', 'Some **bold** and *italic* text.'], (st, data))
check('the lines account for the text', data and data['verbatim'] is True and data['tail'] == '')
raw = [b for b in data['blocks'] if 'md-raw' in b['html']]
check('HTML is a box that is shown, not run', len(raw) == 1 and '<iframe' not in raw[0]['html'] and raw[0]['source'].startswith('<iframe'), raw)
st, data = draw(ed, BODY)
check('an editor can use it', st == 200 and data and data['ok'])
st, data = draw(usr, BODY)
check('a basic user cannot', st in (302, 403), st)
st = anon.request('/admin/markdown-visual', data=[('body', 'x')])[0]
check('nobody signed out can', st != 200, st)
st, hdr, text = root.get('/admin/markdown-visual')
check('it takes only POST', st == 405, st)
st, _ = draw(root, 'x', csrf=False)
check('and a token', st in (302, 403, 419), st)
st, data = draw(root, 'x' * 2_100_000)
check('a text that is too long is refused', st == 413 and data and data['ok'] is False, st)
st, data = draw(root, '')
check('an empty text is nothing', st == 200 and data['blocks'] == [])
st, data = draw(root, "Line\r\nbreaks\r\n\r\nSecond")
check('Windows line ends are read as lines', data and [b['source'] for b in data['blocks']] == ['Line\nbreaks', 'Second'], data)

# ---- what is written is Markdown, and what is there stays
st, hdr, _ = ed.submit('/admin/edit?type=pages&slug=visual&lang=el', has_field('body'), {'body': BODY.rstrip('\n')})
check('an editor saves the text as it came back from the visual editor', st in (302, 303), st)
check('and the HTML an administrator put in it is still there as it was', '<iframe src="https://video.test/embed" width="100%"></iframe>' in read('pages/visual.md') and '## Intro' in read('pages/visual.md'), read('pages/visual.md'))
st, _, shown = anon.get('/visual')
check('the page shows the text and the embed', st == 200 and '<h2>Intro</h2>' in shown and '<iframe src="https://video.test/embed"' in shown)

# ---- strikethrough, which the editor writes
write('pages/struck.md', "---\ntitle: 'Struck'\nstatus: published\nvisible: true\n---\n\nPrice ~~100~~ 80, and ~5 km is not struck.\n")
st, _, shown = anon.get('/struck')
check('~~text~~ is struck on the page', '<del>100</del>' in shown and '~5 km' in shown, re.findall(r'<p>Price[^<]*<[^>]*>[^<]*', shown))

# ---- a link that opens in a new tab: [text](address){target=_blank}
write('pages/newtab.md', "---\ntitle: 'Newtab'\nstatus: published\nvisible: true\n---\n\nSee [elsewhere](https://example.org/x){target=_blank} and [here](/visual), use {name} as is, and {.big #id onclick=x} gets nothing.\n")
st, _, shown = anon.get('/newtab')
check('a link marked {target=_blank} opens in a new tab, with rel noopener', 'target="_blank"' in shown and 'rel="noopener noreferrer"' in shown and '>elsewhere</a>' in shown, re.findall(r'<a [^>]*example\.org[^>]*>', shown))
check('an ordinary link does not', re.search(r'<a href="/visual"[^>]*>here</a>', shown) is not None and 'target="_blank"' not in re.search(r'<a href="/visual"[^>]*>', shown).group(0))
check('plain braces stay as text, and no other attribute gets in', '{name}' in shown and 'onclick' not in shown)
st, data = draw(root, 'A [new tab](https://x.test){target=_blank} link.')
check('the visual editor draws it with its target', data and 'target="_blank"' in data['blocks'][0]['html'] and data['blocks'][0]['source'] == 'A [new tab](https://x.test){target=_blank} link.', data)
st, _, html = root.get('/admin/edit?type=pages&slug=newtab&lang=el')
check('and the Markdown of the entry is as written', '[elsewhere](https://example.org/x){target=_blank}' in html)

# ---- text alignment: {align=center} on the line before a paragraph or a heading
write('pages/aligned.md', "---\ntitle: 'Aligned'\nstatus: published\nvisible: true\n---\n\n{align=center}\nA centered paragraph.\n\n{align=right}\n## On the right\n\n{align=justify}\nJustified.\n\n{class=btn onclick=x}\nNot a choice.\n\nPlain.\n")
st, _, shown = anon.get('/aligned')
check('a paragraph and a heading are aligned on the site', '<p align="center">A centered paragraph.</p>' in shown and '<h2 align="right">On the right</h2>' in shown and '<p align="justify">Justified.</p>' in shown, re.findall(r'<(?:p|h2)[^>]*>', shown)[:8])
check('no other attribute gets in', 'onclick' not in shown and 'class="btn"' not in shown.split('Not a choice')[0][-80:])
st, _, css = anon.get(re.search(r'href="([^"]*site\.css[^"]*)"', shown).group(1))
check('the theme draws them', 'p[align="center"]' in css and 'p[align="right"]' in css and 'p[align="justify"]' in css)
st, data = draw(root, "{align=center}\nCentered\n\nPlain\n\n{align=right}\n## Right")
check('the visual editor gets the line with its block, and the text is accounted for', data and [b['source'] for b in data['blocks']] == ["{align=center}\nCentered", 'Plain', "{align=right}\n## Right"] and data['verbatim'] is True and 'align="center"' in data['blocks'][0]['html'], data)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
