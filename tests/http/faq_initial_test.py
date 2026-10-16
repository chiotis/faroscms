import json, os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
anon = Client()
def write(path, text): open('app/content/' + path, 'w', encoding='utf-8').write(text)

def page(slug, *blocks):
    write('pages/%s.md' % slug, "---\ntitle: '%s'\nstatus: published\nvisible: true\nblocks: %s\n---\n" % (slug, json.dumps(list(blocks), ensure_ascii=False)))
    return anon.get('/' + slug)[2]

def faq(**extra):
    b = {'type': 'faq', 'heading': 'Questions', 'items': [
        {'question': 'One?', 'answer': 'First answer'},
        {'question': 'Two?', 'answer': 'Second answer'},
        {'question': 'Three?', 'answer': 'Third answer'}]}
    b.update(extra)
    return b

def details(h): return re.findall(r'<details class="faq-item"[^>]*>', h)

# ---- default: all closed, in every layout, and no new markup
for variant in ('stacked', 'split'):
    h = page('f-' + variant, faq(variant=variant))
    d = details(h)
    check('%s: three items, all closed by default' % variant, len(d) == 3 and all(' open' not in i for i in d), d)
check('FAQPage data is there', 'FAQPage' in h and h.count('"@type":"Question"') == 3 or h.count('"@type": "Question"') == 3, h[:0])
default_html = page('f-d1', faq())
check('"closed" written out renders the same as nothing', details(page('f-d2', faq(initial='closed'))) == details(default_html))

# ---- first open, every layout
for variant in ('stacked', 'split'):
    d = details(page('f-first-' + variant, faq(variant=variant, initial='first')))
    check('%s: First item open opens only the first' % variant, len(d) == 3 and ' open' in d[0] and ' open' not in d[1] and ' open' not in d[2], d)
fi = page('f-first-ld', faq(initial='first'))
check('the structured data is the same either way', re.findall(r'"@type":\s*"FAQPage".*?\]', fi, re.S) == re.findall(r'"@type":\s*"FAQPage".*?\]', default_html, re.S) and 'FAQPage' in fi)
check('no script is needed: the open state is in the markup', not re.search(r'<script[^>]+src="[^"]*faq', fi))

# ---- old pages keep working
d = details(page('f-old', faq(open_first=True)))
check('a page saved with open_first: true still opens the first answer', ' open' in d[0] and ' open' not in d[1], d)
d = details(page('f-old-false', faq(open_first=False)))
check('open_first: false stays closed', all(' open' not in i for i in d), d)
d = details(page('f-both', faq(open_first=True, initial='closed')))
check('a stored initial wins over open_first', all(' open' not in i for i in d), d)

# ---- the editor
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit, re.S).group(1))
defs = {x['type']: x for x in data['definitions']}
fk = {f['key']: f for f in defs['faq']['fields']}
ak = {f['key']: f for f in defs['accordion']['fields']}
check('the FAQ has the Accordion\'s field: same label, options and default, and no old switch', 'open_first' not in fk and fk['initial']['label'] == ak['initial']['label'] and fk['initial']['default'] == 'closed' and fk['initial']['options'] == ak['initial']['options'], fk.get('initial'))
check('it is a Content tab field like the Accordion\'s', 'initial' not in defs['faq']['design_fields'] and 'initial' not in defs['accordion']['design_fields'])

blocks = [faq(initial='first', variant='split')]
fields = {'type': 'pages', 'slug': 'f-saved', 'lang': 'en', 'title': 'f-saved', 'status': 'published', 'visible': '1', 'body': 'x', 'blocks_editor': '1', 'blocks_json': json.dumps(blocks)}
root.submit('/admin/edit?type=pages&slug=about&lang=en', has_field('body'), fields)
def saved(slug):
    p = 'app/content/pages/%s.en.md' % slug
    return open(p if os.path.exists(p) else 'app/content/pages/%s.md' % slug, encoding='utf-8').read()
raw = saved('f-saved')
check('saved, First item open is written', 'initial: first' in raw and 'open_first' not in raw, raw)
st, _, edit2 = root.get('/admin/edit?type=pages&slug=f-saved&lang=en')
back = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit2, re.S).group(1))
check('and the editor reads it back', back['blocks'][0]['initial'] == 'first' and len(back['blocks'][0]['items']) == 3, back['blocks'][0])

fields.update({'slug': 'f-defaults', 'title': 'f-defaults', 'blocks_json': json.dumps([faq(initial='closed')])})
root.submit('/admin/edit?type=pages&slug=about&lang=en', has_field('body'), fields)
check('All closed (the default) writes nothing', not re.search(r'^\s+(initial|open_first):', saved('f-defaults'), re.M), saved('f-defaults'))

write('pages/f-legacy.en.md', "---\ntitle: l\nstatus: published\nvisible: true\nblocks:\n  - type: faq\n    open_first: true\n    items:\n      - { question: Q, answer: A }\n---\n")
st, _, edit3 = root.get('/admin/edit?type=pages&slug=f-legacy&lang=en')
leg = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', edit3, re.S).group(1))['blocks'][0]
check('an old page opens in the editor as First item open', leg.get('initial') == 'first' and 'open_first' not in leg, leg)
fields.update({'slug': 'f-legacy-new', 'title': 'f-legacy-new', 'blocks_json': json.dumps([leg])})
root.submit('/admin/edit?type=pages&slug=about&lang=en', has_field('body'), fields)
check('and saves as initial: first', 'initial: first' in saved('f-legacy-new') and 'open_first' not in saved('f-legacy-new'), saved('f-legacy-new'))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
