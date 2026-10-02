import json, re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()

def data(html):
    m = re.search(r'<script type="application/json" id="form-builder-data">(.*?)</script>', html, re.S)
    return json.loads(m.group(1)) if m else None
def read(slug, lang):
    suffix = '' if lang == 'el' else '.' + lang
    return open('app/content/forms/%s%s.md' % (slug, suffix), encoding='utf-8').read()
def save(path, fields, extra=None):
    over = {'form_fields_json': json.dumps(fields)}
    over.update(extra or {})
    return root.submit(path, has_field('form_fields_json'), over)

# ---- where a new form starts
st, _, html = root.get('/admin/forms-new?lang=en')
links = re.findall(r'href="[^"]*admin/new\?type=forms&amp;lang=en&amp;template=([a-z]+)"', html)
check('the New form screen offers every template, the blank one first', st == 200 and links == ['blank', 'contact', 'newsletter', 'quote', 'booking', 'event', 'support', 'feedback'], (st, links))
check('with what each is and how many fields it has', 'Request a quote' in html and 'Blank form' in html and re.search(r'\d+ fields', html) is not None)
st, _, html = root.get('/admin/forms-new?lang=el')
check('and in the language asked for (Greek here)', 'Επικοινωνία' in html and 'Αίτημα προσφοράς' in html)
st, _, html = root.get('/admin/forms-new?lang=zz')
check('a language the site does not have falls back to the default one', st == 200 and 'aria-current="true"' in html)
st, hdrs, _ = root.get('/admin/new?type=forms&lang=en')
check('"new form" with no template goes to that screen', st in (302, 303) and '/admin/forms-new?lang=en' in (hdrs.get('Location') or ''), hdrs.get('Location'))
st, hdrs, _ = root.get('/admin/new?type=forms&lang=en&template=quote')
check('and with a template goes to the builder with it', st in (302, 303) and 'edit?type=forms&slug=&lang=en&template=quote' in (hdrs.get('Location') or ''), hdrs.get('Location'))
st, _, html = root.get('/admin/forms')
check('the Forms list links to it', 'admin/forms-new' in html)

# ---- the builder, for a form that is not saved yet
st, _, html = root.get('/admin/edit?type=forms&slug=&lang=en&template=quote')
d = data(html)
check('the builder opens with the fields of the template', st == 200 and d is not None and len(d['fields']) == 11 and d['existing'] is False, d and len(d['fields']))
check('none of them is saved yet, and the types include a heading with a width on others', all(f['saved'] is False for f in d['fields']) and d['fields'][0]['type'] == 'heading' and d['fields'][1]['width'] == 'half', d['fields'][:2])
check('it says what it started from, and the title, button and messages are filled in', 'Started from' in html and 'value="Request a quote"' in html and 'We will send you a quote' in html, '')
check('the parts of the screen are there', all(x in html for x in ('data-fb-palette', 'data-fb-canvas', 'data-fb-inspector', 'data-fb-undo', 'data-fb-mode="preview"', 'data-fb-device="phone"', 'assets/js/admin-form-builder.js')))
check('and its tabs: build, settings, email, submissions, translations, advanced', all(('data-tab="%s"' % t) in html for t in ('build', 'settings', 'email', 'submissions', 'translations', 'advanced')) and 'data-tab="history"' not in html)
check('the old rows of fields are gone from the page', 'form_fields[' not in html and 'js-add-form-field' not in html)
check('the emails start as the template says (an automatic reply, with an answer in it)', 'name="form_notifications[auto_reply]" value="1" checked' in html and '{full-name}' in html)

# ---- saving it
fields = d['fields']
for f in fields: f.pop('saved', None)
st, hdrs, _ = save('/admin/edit?type=forms&slug=&lang=en&template=quote', fields, {'form_notifications[to]': 'sales@example.test'})
loc = hdrs.get('Location') or ''
m = re.search(r'slug=([a-z0-9-]+)&lang=en', loc)
check('the new form is saved and the editor opens again', st in (302, 303) and m is not None and 'saved=1' in loc, (st, loc))
slug = m.group(1)
text = read(slug, 'en')
check('its address was made from its title', slug == 'request-a-quote', slug)
check('the file has the fields, with headings and widths', text.count('type: heading') == 2 and 'width: half' in text and 'type: select' in text and 'name: full-name' in text, text[:300])
check('and the emails with the answer in them', 'to: sales@example.test' in text and 'auto_reply: true' in text and '{full-name}' in text)
d2 = data(root.get('/admin/edit?type=forms&slug=%s&lang=en' % slug)[2])
check('it opens again with every field saved', d2['existing'] is True and len(d2['fields']) == 11 and all(f['saved'] for f in d2['fields']) and d2['shortcode'] == '[form slug="%s"]' % slug, d2 and d2['shortcode'])
st, _, html = root.get('/admin/edit?type=forms&slug=%s&lang=en' % slug)
check('the page has the shortcode to put it on a page, and a way to copy it', ('[form slug="%s"]' % slug) in html and 'data-copy' in html)

# ---- what the builder sends is cleaned
before = read(slug, 'en')
st, hdrs, _ = save('/admin/edit?type=forms&slug=%s&lang=en' % slug, [
    {'type': 'text', 'name': 'email', 'label': 'One', 'width': 'half'}, {'type': 'text', 'name': 'email', 'label': 'Two', 'width': 'bogus'},
    {'type': 'select', 'name': 'size', 'label': 'Size', 'options': [{'value': 's', 'label': 'Small, cheap'}, {'value': 'l', 'label': 'Large'}]},
    {'type': 'paragraph', 'name': '', 'label': 'Words'}])
text = read(slug, 'en')
check('two fields with one name are told apart', 'name: email\n' in text and 'name: email-2' in text, text[:400])
check('a choice with a comma in its label is kept whole, and a text part gets a name of its kind', 's|Small, cheap' in text and 'name: paragraph-1' in text, text[:600])
check('a width that is not one is dropped', 'width: bogus' not in text and text.count('width:') == 1, text[:600])
after_bad = None
for bad in ('not json', '{"a":1}'):
    save('/admin/edit?type=forms&slug=%s&lang=en' % slug, [], {'form_fields_json': bad})
    now = read(slug, 'en')
    check('a field list that cannot be read leaves the fields as they were: ' + bad, 'name: email-2' in now and 'name: size' in now, now[:200])
save('/admin/edit?type=forms&slug=%s&lang=en' % slug, [], {'form_fields_json': '[]'})
check('an empty list takes the fields out', 'fields:' not in read(slug, 'en'))

# ---- on the site: widths, headings, paragraphs
rows = [
    {'type': 'heading', 'name': 'part', 'label': 'About you'},
    {'type': 'text', 'name': 'full-name', 'label': 'Name', 'required': True, 'width': 'half'},
    {'type': 'email', 'name': 'email', 'label': 'Email', 'width': 'half'},
    {'type': 'paragraph', 'name': 'why', 'label': 'We only use it to reply.'},
    {'type': 'radio', 'name': 'size', 'label': 'Size', 'width': 'third', 'options': [{'value': 's', 'label': 'S'}, {'value': 'l', 'label': 'L'}]},
    {'type': 'textarea', 'name': 'message', 'label': 'Message'}]
save('/admin/edit?type=forms&slug=contact&lang=el', rows)
st, _, page = root.get('/blocks')  # visible to signed-in people only
check('the form on a page has the heading, the paragraph and the widths of the fields', st == 200 and '<h3 class="form-heading">About you</h3>' in page and '<p class="form-text">We only use it to reply.</p>' in page and page.count('form-w-half') >= 2 and 'form-w-third' in page, st)
check('a heading and a paragraph take no answer (no input of their name)', 'name="part"' not in page and 'name="why"' not in page and 'name="full-name"' in page)
css = pub.get('/_themes/default/css/site.css')[2]
check('the theme styles the widths', 'form-w-half' in css and 'form-w-two-thirds' in css and 'form-heading' in css)

# ---- a new translation starts as a copy
support = data(root.get('/admin/edit?type=forms&slug=&lang=el&template=support')[2])['fields']
for f in support: f.pop('saved', None)
st, hdrs, _ = save('/admin/edit?type=forms&slug=&lang=el&template=support', support)
loc = hdrs.get('Location') or ''
m = re.search(r'slug=([a-z0-9-]+)&lang=el', loc)
check('a form is made in the default language', st in (302, 303) and m is not None, (st, loc))
gslug = m.group(1)
st, _, html = root.get('/admin/edit?type=forms&slug=%s&lang=en' % gslug)
d3 = data(html)
check('its translation opens as a copy of it, and says so', d3['existing'] is False and len(d3['fields']) == 5 and 'copied from the EL version' in html and all(f['saved'] is False for f in d3['fields']), (d3 and len(d3['fields'])))

# ---- who may
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
for path in ('/admin/forms-new', '/admin/edit?type=forms&slug=contact&lang=en', '/admin/new?type=forms'):
    st, hdrs, _ = ed.get(path)
    check('an editor cannot open ' + path, st in (302, 303, 403), st)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
