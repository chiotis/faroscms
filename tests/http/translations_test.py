import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
pub = Client()

def is_tr(f): return 'translations' in (f['attrs'].get('class') or '')
def screen(lang):
    st, _, html = root.get('/admin/translations?lang=' + lang)
    return st, html, next(f for f in root.forms(html=html) if is_tr(f))
def rows(html):
    """key -> (status, custom, value) of every string on the screen."""
    out = {}
    for m in re.finditer(r'<div class="tr-row" data-key="([^"]+)" data-status="([a-z]+)" data-custom="([01])".*?name="values\[\]" (?:value="([^"]*)"|[^>]*>([^<]*))', html, re.S):
        out[m.group(1)] = (m.group(2), m.group(3) == '1', m.group(4) if m.group(4) is not None else (m.group(5) or ''))
    return out
def save(lang, changes=None, reset=(), add=None):
    """Post the form as the browser would, with some values changed, some strings reset, and a string added."""
    st, html, form = screen(lang)
    fields, key = [], None
    for name, value in form['fields']:
        if name == 'keys[]':
            key = value
            if add is not None and value == '':
                value = add[0]; key = add[0]
        elif name == 'values[]':
            if add is not None and key == add[0]:
                value = add[1]
            elif key in (changes or {}):
                value = changes[key]
        fields.append((name, value))
    for r in reset:
        fields.append(('reset[]', r))
    return root.request(form['attrs'].get('action') or '/admin/translations?lang=' + lang, data=fields)

# ---- the screen
st, html, form = screen('en')
check('the screen opens on a language of the site', st == 200 and 'English' in html and 'Greek' in html)
check('with each language to pick, how far it is translated, and which is the source', len(re.findall(r'data-tr-lang', html)) == 2 and 'source' in html and re.search(r'English</b><small>\d+%', html) is not None, re.findall(r'<small>[^<]*</small>', html)[:3])
check('a search, filters with their counts, areas', 'data-tr-search' in html and 'data-tr-filter="all"' in html and 'data-tr-area' in html and re.search(r'All <em>\d+</em>', html) is not None)
r = rows(html)
check('the strings are in areas, each a box to type in with its source beside it', len(r) > 50 and html.count('class="tr-group"') >= 5 and html.count('class="tr-src"') == len(r), (len(r), html.count('class="tr-group"')))
check('menu labels are not here', not any(k.startswith('nav.') for k in r))
check('a form posts a pair of key and text for every string, and one more for a new one', [f[0] for f in form['fields']].count('keys[]') == len(r) + 1 and [f[0] for f in form['fields']].count('values[]') == len(r) + 1)
check('there is a place to add a string of your own', 'Add a string of your own' in html and 'shop.buy_now' in html)
st, html, _ = screen('xx')
check('a language the site does not have falls back to the default one', st == 200 and re.search(r'data-tr-lang[^>]*aria-current|aria-current="true"[^>]*data-tr-lang', html) is not None and 'Greek</b><small>source' in html)
check('an editor cannot open it', ed.get('/admin/translations')[0] == 403)

# ---- change a string, and the site uses it
before = r['archive.read_more']
check('a string starts as the theme says', before[0] in ('translated', 'source') and not before[1], before)
card_page = next((p for p in ('/en/posts', '/en/projects', '/en/') if 'content-item-more' in pub.get(p)[2]), '/en/posts')
check('a page of the site shows the string (the "read more" of a card)', 'Read more' in pub.get(card_page)[2], card_page)
save('en', {'archive.read_more': 'Keep reading'})
st, html, _ = screen('en')
r2 = rows(html)
check('a change is saved and shown as customized', r2['archive.read_more'] == ('translated', True, 'Keep reading'), r2['archive.read_more'])
check('with a way back to the theme text', 'Back to the theme text' in html and 'value="archive.read_more"' in html)
check('and the site says it', 'Keep reading' in pub.get(card_page)[2] and 'Read more' not in pub.get(card_page)[2].replace('Read more about', ''), card_page)
check('the other language is not touched', rows(screen('el')[1])['archive.read_more'][1] is False)
save('en', reset=['archive.read_more'])
check('a string given back to the theme is no longer customized', rows(screen('en')[1])['archive.read_more'][1] is False)

# ---- missing and the same as the source
save('en', {'archive.empty': ''})
st, html, _ = screen('en')
r3 = rows(html)
check('a string with no text is missing, and the source can be copied into it', r3['archive.empty'][0] == 'missing' and 'data-tr-copy=' in html and re.search(r'data-tr-filter="missing"[^>]*>Missing <em>1</em>', html) is not None, r3['archive.empty'])
save('en', reset=['archive.empty'])
st, html, _ = screen('el')
check('the source language has no "same as the source" filter', 'data-tr-filter="source"' not in html)

# ---- a string of your own
save('en', add=('shop.buy_now', 'Buy now'))
st, html, _ = screen('en')
r4 = rows(html)
check('a string that was added is in its own area, marked as added by you', r4.get('shop.buy_now', (None,))[2:] == ('Buy now',) and 'Added by you' in html and 'data-tr-group="shop"' in html, r4.get('shop.buy_now'))
save('en', reset=['shop.buy_now'])
check('and can be deleted', 'shop.buy_now' not in rows(screen('en')[1]))
st, _, logs = root.get('/admin/activity-logs')
check('a save is in the activity log', 'Translations updated' in logs)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
