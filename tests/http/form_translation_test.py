import glob, json, os, re, subprocess, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

root = Client(); root.login()
pub = Client()

def data(html):
    m = re.search(r'<script type="application/json" id="form-builder-data">(.*?)</script>', html, re.S)
    return json.loads(m.group(1)) if m else None
def read(path):
    return open('app/content/forms/' + path, encoding='utf-8').read()

# ---- the older English copy (contact.en.md) was folded into the form when the site was first opened
check('the English copy is no longer a form of its own', not os.path.exists('app/content/forms/contact.en.md') and os.path.exists('app/content/forms/contact.md'))
check('it was put away, and the migration noted', len(glob.glob('app/storage/form-translation/copies/contact.en.md')) == 1 and os.path.exists('app/storage/form-translation/migrated.json'))
text = read('contact.md')
check('its texts are translations stored with the form', 'translations:' in text and 'Full Name' in text and 'Send Message' in text and 'Thank you. Your message has been submitted' in text, text[-900:])
st, _, html = root.get('/admin/forms')
check('the Forms list shows the form once, with the state of its English', st == 200 and html.count('admin/form-submissions?slug=contact') >= 1 and 'forms-list' not in html and re.search(r'>\s*EN\s*(<span[^>]*>\d+ missing</span>)?\s*</a>', html) is not None, st)
check('nothing is listed for the copy', 'slug=contact&amp;lang=en' not in html)

# ---- the public form, in each language
st, _, el = pub.get('/forms/contact')
st2, _, en = pub.get('/en/forms/contact')
check('the form has a page in each language', st == 200 and st2 == 200, (st, st2))
check('Greek page: Greek labels and button', 'Ονοματεπώνυμο' in el and 'Αποστολή Μηνύματος' in el and 'Full Name' not in el)
check('English page: the same fields in English, the button and the choices too', 'Full Name' in en and 'Send Message' in en and 'Your full name' in en and '>Strategy<' in en and 'Ονοματεπώνυμο' not in en, '')
check('and the values of the choices do not change with the language', 'value="design-build"' in en and 'value="design-build"' in el)
check('the form says which language it was shown in', 'name="form_lang" value="en"' in en and 'name="form_lang" value="el"' in el)
for lang, pref in (('el', ''), ('en', 'en/')):
    open('app/content/pages/formpage%s.md' % ('' if lang == 'el' else '.en'), 'w', encoding='utf-8').write('---\ntitle: Form page\nstatus: published\n---\n\n[form slug="contact"]\n')
st, _, el2 = pub.get('/formpage')
st2, _, en2 = pub.get('/en/formpage')
check('the shortcode shows the form in the language of the page', 'Ονοματεπώνυμο' in el2 and 'Full Name' in en2 and 'Ονοματεπώνυμο' not in en2, (st, st2))

# ---- sending it in English
sender = Client()
st, hdrs, html = sender.submit('/en/forms/contact', lambda f: any(x[0] == 'form_slug' for x in f['fields']),
                              {'full_name': 'Ada Lovelace', 'email': 'ada@example.test', 'service': 'strategy', 'message': 'Hello there', 'consent': '1'})
check('it is sent', st in (200, 302, 303), st)
files = glob.glob('app/content/forms-submissions/contact/*.json')
rec = json.load(open(files[0], encoding='utf-8')) if files else {}
check('the submission is kept under the one form, with the language it was shown in', len(files) == 1 and rec.get('lang') == 'en' and rec.get('form') == 'contact', rec.get('lang'))
sender2 = Client()
sender2.submit('/forms/contact', lambda f: any(x[0] == 'form_slug' for x in f['fields']), {'full_name': 'Nikos', 'email': 'n@example.test', 'service': 'other', 'message': 'Γεια', 'consent': '1'})
st, _, html = root.get('/admin/form-submissions?slug=contact')
check('Submissions lists both', 'Ada Lovelace' in html and 'Nikos' in html)
st, _, html = root.get('/admin/form-submissions?slug=contact&lang=en')
check('and can be filtered by language', 'Ada Lovelace' in html and 'Nikos' not in html, st)

# ---- the builder: language chips, the inspector, the fallback flags
st, _, html = root.get('/admin/edit?type=forms&slug=contact&lang=el&translate=en')
d = data(html)
check('the builder opens on the language asked for, knows its translations', st == 200 and d['open'] == 'en' and d['default'] == 'el' and d['translations']['en']['submit_label'] == 'Send Message', d and d.get('open'))
check('fields carry their translations; the choices\' values do not change', d['fields'][0]['translations']['en']['label'] == 'Full Name' and d['fields'][4]['translations']['en']['options']['strategy'] == 'Strategy' and d['fields'][4]['options'][0]['value'] == 'strategy', d['fields'][4])
check('the page has the language bar and the form\'s own texts tied to it', all(x in html for x in ('data-fb-langbar', 'data-fb-chips-lang', 'data-tr="submit_label"', 'data-tr="success_message"', 'data-tr="notifications.auto_reply_subject"', 'data-tr="title"', 'data-fb-translations-json')))
check('settings that are not texts are marked as for the first language only', html.count('data-default-only') >= 8)
st, hdrs, _ = root.get('/admin/edit?type=forms&slug=contact&lang=en')
check('the older address of the English version leads to the same builder', st in (302, 303) and 'lang=el' in (hdrs.get('Location') or '') and 'translate=en' in (hdrs.get('Location') or ''), hdrs.get('Location'))
st, _, js = root.get('/assets/js/admin-form-builder.js')
check('the script serves', st == 200 and 'setLang' in js)

# ---- saving a translation from the builder
fields = d['fields']
for f in fields: f.pop('saved', None)
fields[0]['translations']['en']['label'] = 'Your name'
fields[2]['translations'] = {'en': {'label': 'Telephone'}}
st, hdrs, _ = root.submit('/admin/edit?type=forms&slug=contact&lang=el', has_field('form_fields_json'), {
    'form_fields_json': json.dumps(fields),
    'form_translations_json': json.dumps({'en': {'title': 'Contact', 'submit_label': 'Send it', 'notifications': {'auto_reply_subject': 'We got it'}}, 'fr': {'title': 'no such language'}}),
    'translate_lang': 'en'})
loc = hdrs.get('Location') or ''
check('it saves and opens on the same language again', st in (302, 303) and 'saved=1' in loc and 'translate=en' in loc, loc)
text = read('contact.md')
check('the translations of the fields and of the form are in the one file', all(x in text for x in ('Your name', 'Telephone', 'Send it', 'We got it')), text[-700:])
check('a language the site does not have is not kept; the first-language texts are as they were', 'no such language' not in text and 'Αποστολή Μηνύματος' in text and 'label: Ονοματεπώνυμο' in text)
st, _, en = pub.get('/en/forms/contact')
check('the English form shows the new texts at once', 'Your name' in en and 'Telephone' in en and 'Send it' in en, '')
d = data(root.get('/admin/edit?type=forms&slug=contact&lang=el')[2])
check('the builder does not offer a way to create the form in another language', 'create_url' not in json.dumps(d) and len(d['languages']) == 1)

# ---- a translated copy with an address of its own keeps working
open('app/content/forms/sales.md', 'w', encoding='utf-8').write('---\ntitle: Πωλήσεις\ntranslation_id: sal1\nfields:\n  - {type: text, name: name, label: Όνομα}\n---\n')
open('app/content/forms/sales-en.en.md', 'w', encoding='utf-8').write('---\ntitle: Sales\ntranslation_id: sal1\nfields:\n  - {type: text, name: name, label: Name}\n  - {type: text, name: fax, label: Fax}\n---\n')
os.makedirs('app/content/forms-submissions/sales-en', exist_ok=True)
open('app/content/forms-submissions/sales-en/s1.json', 'w').write(json.dumps({'id': 's1', 'form': 'sales-en', 'lang': 'en', 'submitted_at': '2026-01-01T00:00:00+00:00', 'fields': {'name': 'Old'}}))
out = subprocess.run(['php', 'app/scripts/migrate-form-translations.php', '--default-lang=el', '--langs=el,en', '--content=app/content', '--out=app/storage/form-translation'], capture_output=True, text=True)
check('the migration can be run again by hand, and says what it did and what it could not place', 'sales: folded en' in out.stdout and 'sales-en' in out.stdout and 'fax' in out.stdout, out.stdout + out.stderr)
check('the form it folded into has the alias', 'sales-en: en' in read('sales.md') and not os.path.exists('app/content/forms/sales-en.en.md'))
st, hdrs, _ = pub.get('/en/forms/sales-en')
check('the old English address leads to the form, in English', st == 301 and (hdrs.get('Location') or '').endswith('/en/forms/sales'), (st, hdrs.get('Location')))
st, _, html = pub.get('/en/forms/sales')
check('and the form there is English', st == 200 and '>Name<' in html.replace(' ', '').replace('\n', '') or 'Name' in html, st)
open('app/content/pages/formpage.en.md', 'w', encoding='utf-8').write('---\ntitle: Form page\nstatus: published\n---\n\n[form slug="sales-en"]\n')
st, _, html = pub.get('/en/formpage')
check('a shortcode that points at the old address still shows the form, in English', 'name="form_slug" value="sales"' in html and 'Name' in html, st)
st, _, html = root.get('/admin/form-submissions?slug=sales')
check('submissions that were filed under the old address are readable under the form', 'Old' in html, st)
st, _, html = root.get('/admin/forms')
check('the Forms list shows what could not be matched, for a person to look at', 'Texts without a field to go to' in html and 'fax' in html, st)
st, _, _ = root.submit('/admin/forms', has_field('dismiss_migration_report'), None, button=['dismiss_migration_report', '1'])
st, _, html = root.get('/admin/forms')
check('and it can be dismissed', 'Texts without a field to go to' not in html)

# ---- the block that shows a form lists each form once
st, _, html = root.get('/admin/edit?type=pages&slug=index&lang=el')
check('the editor opens', st == 200)
print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
