import json, os, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()

# ---- the editor loads the sortable script before the builder, and the builder has a grip and no arrows
st, _, edit = root.get('/admin/edit?type=pages&slug=about&lang=en')
check('the entry editor loads admin-sortable.js before admin-blocks.js', 'admin-sortable.js' in edit and edit.index('admin-sortable.js') < edit.index('admin-blocks.js'))
sortable = root.get('/assets/js/admin-sortable.js')[2]
check('the sortable module is served and has its small API', 'AdminSortable' in sortable and 'onSort' in sortable and 'group' in sortable)
js = root.get('/assets/js/admin-blocks.js')[2]
check('the builder has a grip and the "…" menu, and no up and down arrows', 'bk-grip' in js and 'Move to top' in js and 'Move to bottom' in js and "'Move block up'" not in js and "' up'" not in js)
st, _, theme = root.get('/admin/theme?tab=footer_blocks')
check('the footer blocks editor loads the module too', st != 200 or 'admin-sortable.js' in theme, st)

# ---- what the builder writes after a reorder is what is stored, in that order
made = []
def save(blocks):
    # The first save turns the About page into this one (its slug is changed); the next ones edit it where it now is.
    url = '/admin/edit?type=pages&slug=reordered&lang=en' if made else '/admin/edit?type=pages&slug=about&lang=en'
    made.append(1)
    fields = {'type': 'pages', 'slug': 'reordered', 'lang': 'en', 'title': 'reordered', 'status': 'published', 'visible': '1', 'body': 'x', 'blocks_editor': '1', 'blocks_json': json.dumps(blocks)}
    root.submit(url, has_field('body'), fields)
    for path in ('app/content/pages/reordered.en.md', 'app/content/pages/reordered.md'):
        if os.path.exists(path):
            return open(path, encoding='utf-8').read()
    return ''

def order(raw):
    return re.findall(r'heading: (\S+)', raw)

def t(heading, **extra):
    b = {'type': 'text', 'heading': heading, 'body': 'Body ' + heading}
    b.update(extra)
    return b

raw = save([t('A'), t('B'), t('C'), t('D')])
check('a page is stored in the order of its blocks', order(raw) == ['A', 'B', 'C', 'D'], order(raw))
raw = save([t('C'), t('A'), t('B'), t('D')])
check('after C is dragged to the top the stored order is C, A, B, D', order(raw) == ['C', 'A', 'B', 'D'], order(raw))
raw = save([t('C'), t('B'), t('D'), t('A')])
check('and after a second move it follows again', order(raw) == ['C', 'B', 'D', 'A'], order(raw))
raw = save([t('A', width=50), t('B', width=50), t('C')])
raw = save([t('C'), t('B', width=50), t('A', width=50)])
check('blocks that share a row keep their width when their order changes', order(raw) == ['C', 'B', 'A'] and re.findall(r'width: (\d+)', raw) == ['50', '50'], raw)
st, _, again = root.get('/admin/edit?type=pages&slug=reordered&lang=en')
data = json.loads(re.search(r'id="block-editor-data"[^>]*>(.*?)</script>', again, re.S).group(1))
check('the editor reads the new order back', [b['heading'] for b in data['blocks']] == ['C', 'B', 'A'], [b.get('heading') for b in data['blocks']])

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
