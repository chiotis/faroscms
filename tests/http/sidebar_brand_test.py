import re, sys
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
def head(html):
    m = re.search(r'<aside.*?<nav', html, re.S)
    return m.group(0) if m else ''
st, _, html = root.get('/admin')
top = head(html)
check('the sidebar top has no logo square', st == 200 and '>FC<' not in top and 'bg-slate-900 text-xs font-bold' not in top, top[:300])
name = re.search(r'<span class="[^"]*truncate[^"]*"[^>]*>([^<]*)</span>', top)
check('it shows the site name from Settings', name is not None and name.group(1).strip() != '', top[:300])
check('and the version reads "FarosCMS v…"', re.search(r'>\s*(?:<span[^>]*></span>)?FarosCMS v[^<]+<', top) is not None, top[-400:])

# change the name in Settings and see it here
form = next(f for f in root.forms('/admin/settings') if any(x[0] == 'title' for x in f['fields']))
fields = [tuple(x) for x in form['fields'] if x[0] != 'title'] + [('title', 'Acme Studio')]
root.request(form['attrs'].get('action') or '/admin/settings', data=fields)
st, _, html = root.get('/admin/content?type=pages')
top = head(html)
check('a new site name shows on every screen', 'Acme Studio' in top, top[:400])
check('the name has a tooltip for when it is cut off', 'title="Acme Studio"' in top)
check('the browser tab title is unchanged', '- FarosCMS</title>' in html)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
