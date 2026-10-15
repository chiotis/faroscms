import glob, json, os, re, sys, urllib.request, uuid
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:240]))
    if not ok: fails.append(label)

PNG = bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360f8cf000002070101a1e7d5c80000000049454e44ae426082')
PDF = b'%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'

os.makedirs('app/content/forms', exist_ok=True)
open('app/content/forms/careers.md', 'w', encoding='utf-8').write('''---
title: Careers
status: published
visible: true
submit_label: Send
store_submissions: true
notifications:
  enabled: false
antispam:
  honeypot: website
  rate_limit_seconds: 0
fields:
  - type: text
    name: surname
    label: Surname
    required: true
  - type: text
    name: name
    label: Name
    required: true
  - type: file
    name: cv
    label: CV
    required: true
    upload:
      multiple: false
      max_size_mb: 1
      extensions: [pdf, png]
      rename: "cv-{field:surname}-{field:name}-{date}"
  - type: file
    name: extras
    label: Extra files
    upload:
      multiple: true
      min: 0
      max: 2
      max_size_mb: 1
      extensions: [pdf, png, txt]
---
Send us your CV.
''')

root = Client(); root.login()
pub = Client()

def multipart(path, fields, files, client=None):
    """Posts what a browser posts without JavaScript: a multipart form. files: [(field name, file name, bytes)]."""
    boundary = '----faros' + uuid.uuid4().hex
    body = b''
    for k, v in fields:
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode()
    for k, name, data in files:
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, k, name)).encode() + data + b'\r\n'
    body += ('--%s--\r\n' % boundary).encode()
    c = client or Client()
    req = urllib.request.Request(BASE + path, data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        resp = c.opener.open(req); st, hdrs, content = resp.status, resp.headers, resp.read()
    except urllib.error.HTTPError as e:
        st, hdrs, content = e.code, e.headers, e.read()
    return st, hdrs, content.decode('utf-8', 'replace')

def base(extra=None):
    f = [('form_slug', 'careers'), ('form_lang', 'en'), ('surname', 'Παπαδόπουλος'), ('name', 'Γιώργος'), ('website', '')]
    return f + (extra or [])

def submissions():
    return sorted(glob.glob('app/content/forms-submissions/careers/*.json'))

# ---- the public form
st, _, html = pub.get('/en/forms/careers')
check('the form page opens', st == 200, st)
check('it is a multipart form with a file control and the picker styles and script', 'enctype="multipart/form-data"' in html and 'type="file"' in html and 'form-upload.css' in html and 'form-upload.js' in html)
check('the limits are told to the visitor and to the script', 'data-max-size="1048576"' in html and 'data-extensions="pdf,png"' in html and 'Up to 1.0 MB' in html, '')
check('a single-file field is not multiple, the other one is, with [] in its name', 'name="cv"' in html and 'name="extras[]"' in html and html.count(' multiple') == 1)
check('the label, the hint and the messages are tied to the control', re.search(r'<input type="file" id="form-careers-cv"[^>]*aria-describedby="[^"]*form-careers-cv-limits', html) is not None and 'data-msg-size=' in html)
check('without JavaScript the real control is visible: the button and the drop text start hidden', 'data-file-choose' in html and re.search(r'data-file-choose[^>]*hidden', html) is not None)
st, _, other = pub.get('/en/forms/contact')
check('a form without a file field loads neither the styles nor the script', st == 200 and 'form-upload' not in other and 'multipart' not in other, st)
st, _, css = pub.get('/themes/default/assets/css/form-upload.css')
check('the stylesheet is served', st == 200 or 'form-upload.css' in html, st)

# ---- a mistake: nothing is written
c = Client()
st, _, html = multipart('/en/forms/careers', [('form_slug', 'careers'), ('form_lang', 'en'), ('surname', ''), ('name', 'Γιώργος'), ('website', '')], [('cv', 'cv.pdf', PDF)])
check('a required field missing shows the form again with the mistake', st == 200 and 'form-error' in html, st)
check('and says the files have to be chosen again', 'choose them again' in html or 'Επιλέξτε τα ξανά' in html)
check('nothing was kept: no submission, no files', submissions() == [] and not glob.glob('app/storage/form-uploads/careers/*'))
st, _, html = multipart('/en/forms/careers', base(), [])
check('a required file missing is refused', st == 200 and 'form-error' in html and submissions() == [], st)
for name, data, label in (('evil.php', b'<?php echo 1;', 'a PHP file'), ('page.html', b'<html></html>', 'an HTML file'), ('logo.svg', b'<svg xmlns="http://www.w3.org/2000/svg"/>', 'an SVG file'), ('photo.png', PDF, 'a PDF named .png'), ('cv.pdf.php', PDF, 'shell.pdf.php')):
    st, _, html = multipart('/en/forms/careers', base(), [('cv', name, data)])
    check('refused: ' + label, st == 200 and 'not an accepted kind of file' in html and submissions() == [] and not glob.glob('app/storage/form-uploads/careers/*'), st)
st, _, html = multipart('/en/forms/careers', base(), [('cv', 'big.png', PNG + b'0' * 1100000)])
check('refused: a file over the size', 'is larger than 1.0 MB' in html and submissions() == [], st)
st, _, html = multipart('/en/forms/careers', base(), [('cv', 'cv.pdf', PDF), ('extras[]', 'a.txt', b'one'), ('extras[]', 'b.txt', b'two'), ('extras[]', 'c.txt', b'three')])
check('refused: too many files', 'at most 2 files' in html and submissions() == [], st)

# ---- a valid submission, without JavaScript
st, hdrs, html = multipart('/en/forms/careers', base(), [('cv', 'My CV.pdf', PDF), ('extras[]', 'notes.txt', b'hello'), ('extras[]', 'pic.png', PNG)])
check('a valid submission is accepted', st in (200, 302, 303) and ('success' in html or st in (302, 303)), st)
subs = submissions()
check('it is kept as one submission', len(subs) == 1, subs)
rec = json.load(open(subs[0], encoding='utf-8')) if subs else {}
sid = rec.get('id', '')
today = __import__('datetime').date.today().isoformat()
check('the CV is named after the pattern, with the real extension', rec.get('fields', {}).get('cv') == ['cv-papadopoulos-giorgos-%s.pdf' % today], rec.get('fields'))
check('the other files keep their own names, and the record lists them with their size', rec.get('fields', {}).get('extras') == ['notes.txt', 'pic.png'] and rec['files']['extras'][0]['size'] == 5 and rec['files']['cv'][0]['original'] == 'My CV.pdf', rec.get('files'))
folder = 'app/storage/form-uploads/careers/' + sid
check('the files are in the private folder', sorted(os.listdir(folder)) == sorted(['cv-papadopoulos-giorgos-%s.pdf' % today, 'notes.txt', 'pic.png']) if os.path.isdir(folder) else False, os.listdir(folder) if os.path.isdir(folder) else None)
check('and the folder is shut off for the web server too', 'Require all denied' in open('app/storage/form-uploads/.htaccess').read())
check('nothing was put in the public uploads', not glob.glob('app/public/uploads/**/*.pdf', recursive=True))
cv = 'cv-papadopoulos-giorgos-%s.pdf' % today

# ---- a second one with the same name gets a number
st, _, html = multipart('/en/forms/careers', base(), [('cv', 'again.pdf', PDF), ('extras[]', 'x.txt', b'a'), ('extras[]', 'x.txt', b'b')])
subs = submissions()
second = json.load(open([s for s in subs if sid not in s][0], encoding='utf-8')) if len(subs) == 2 else {}
check('two files with the same name in one submission get -2', second.get('fields', {}).get('extras') == ['x.txt', 'x-2.txt'], second.get('fields'))

# ---- private: no direct address
for path in ('/storage/form-uploads/careers/%s/%s' % (sid, cv), '/form-uploads/careers/%s/%s' % (sid, cv), '/uploads/form-uploads/careers/%s/%s' % (sid, cv), '/content/forms-submissions/careers/%s.json' % sid, '/storage/form-uploads/.htaccess'):
    st, _, body = Client().get(path)
    check('no direct address: ' + path.split(sid)[0], st in (403, 404) and PDF[:8].decode() not in body, st)

# ---- the admin gives them out
fileurl = '/admin/form-file?' + 'slug=careers&id=%s&f=%s' % (sid, cv)
st, hdrs, body = root.get(fileurl)
check('a signed-in administrator downloads the file', st == 200 and body.startswith('%PDF-1.4'), st)
check('as an attachment, never shown, and not sniffed', 'attachment' in (hdrs.get('Content-Disposition') or '') and cv in (hdrs.get('Content-Disposition') or '') and (hdrs.get('X-Content-Type-Options') or '').lower() == 'nosniff', dict(hdrs))
st, hdrs, _ = Client().get(fileurl)
check('someone who is not signed in is sent to sign in and gets nothing', st in (302, 303) and 'login' in (hdrs.get('Location') or ''), (st, hdrs.get('Location')))
for bad in ('../../../../content/forms/careers.md', '..%2f..%2fsite.md', '.htaccess', 'missing.pdf'):
    st, _, body = root.get('/admin/form-file?slug=careers&id=%s&f=%s' % (sid, bad))
    check('no way out of the folder: ' + bad, st == 404 and 'title:' not in body, st)
st, _, _ = root.get('/admin/form-file?slug=nope&id=%s&f=%s' % (sid, cv))
check('nor through another form name', st == 404, st)
# the roles: an editor and a plain user are refused
for username, role in (('ed1', 'editor'), ('bob', 'user')):
    root.submit('/admin/users-edit', has_field('username'), {'username': username, 'email': username + '@example.test', 'display_name': username.title(), 'role': role, 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
    other = Client(); other.login(username, 'Sturdy-pass-99')
    st, _, body = other.get(fileurl)
    check('refused to the %s role' % role, st == 403 and not body.startswith('%PDF'), st)
    st, _, body = other.get('/admin/form-files-zip?slug=careers&id=' + sid)
    check('and its zip', st == 403, st)
st, hdrs, body = root.get('/admin/form-files-zip?slug=careers&id=' + sid)
check('all the files of a submission come as a ZIP', st == 200 and body[:2] == 'PK' and 'attachment' in (hdrs.get('Content-Disposition') or ''), st)

# ---- the screens
st, _, html = root.get('/admin/form-submissions?slug=careers')
check('the list shows how many files each submission has', st == 200 and re.search(r'title="3 attached files"', html) is not None, st)
check('the details list each file with its size and a link, and all as ZIP', cv in html and 'admin/form-file?slug=careers' in html and 'Download all as ZIP' in html and '5 B' in html)
check('the confirmation names the files', 'and its 3 files' in html and 'data-files="3"' in html)
st, _, html = root.get('/admin/form-submissions?slug=careers&has_files=1')
check('the filter "only with files" works', st == 200 and html.count('data-files=') >= 2 and 'checked' in html, st)
st, _, html = root.get('/admin/form-submissions?slug=contact&has_files=1')
check('and the list of a form without files has nothing to filter', st == 200, st)
st, hdrs, body = root.get('/admin/forms-export?slug=careers')
check('the CSV lists the file names', st == 200 and cv in body and 'notes.txt' in body, st)

# ---- the builder
st, _, html = root.get('/admin/edit?type=forms&slug=careers&lang=el')
m = re.search(r'<script type="application/json" id="form-builder-data">(.*?)</script>', html, re.S)
d = json.loads(m.group(1)) if m else {}
check('the builder is given the file fields with their settings', any(f['type'] == 'file' and f['upload']['rename'].startswith('cv-') for f in d.get('fields', [])), '')
check('and the limits of this server and site, the kinds, the refused ones', d.get('upload', {}).get('server_file', 0) > 0 and 'images' in d['upload']['presets'] and 'php' in d['upload']['blocked'], d.get('upload', {}).keys() if d else None)
check('the Email tab has the choice to attach files', 'form_notifications[attach_files]' in html and 'form_notifications[attach_max_mb]' in html)
# saving a form with a new file field through the builder
fields = [{'type': 'text', 'name': 'name', 'label': 'Name', 'required': True},
          {'type': 'file', 'name': 'doc', 'label': 'Document', 'upload': {'multiple': True, 'min': 1, 'max': 4, 'max_size_mb': 3, 'extensions': ['pdf', 'php', 'svg', 'docx'], 'rename': '{form}-{index}'}, 'button': 'Pick', 'error_type': 'Nope', 'translations': {'en': {'label': 'Doc', 'button': 'Choose'}}}]
st, hdrs, _ = root.submit('/admin/edit?type=forms&slug=careers&lang=el', has_field('form_fields_json'), {'form_fields_json': json.dumps(fields), 'form_notifications[enabled]': '1', 'form_notifications[to]': 'jobs@example.test', 'form_notifications[attach_files]': '1', 'form_notifications[attach_max_mb]': '4'})
saved = open('app/content/forms/careers.md', encoding='utf-8').read()
check('saving stores the settings cleaned: a refused kind never stored', st in (302, 303) and 'extensions:' in saved and 'php' not in saved.split('extensions:')[1].split('rename')[0] and 'svg' not in saved.split('extensions:')[1].split('rename')[0] and 'docx' in saved, saved[-900:])
check('the texts and their translation are stored', 'button: Pick' in saved and 'error_type: Nope' in saved and 'Choose' in saved, saved[-600:])
check('and the choice to attach files, with its size', 'attach_files: true' in saved and 'attach_max_mb: 4' in saved, saved[:600])

# ---- deleting
open('app/content/forms/careers.md', 'w', encoding='utf-8').write(open('app/content/forms/careers.md', encoding='utf-8').read())
before = len(os.listdir(folder)) if os.path.isdir(folder) else 0
tk = re.search(r'name="_csrf" value="([0-9a-f]+)"', root.get('/admin/form-submissions?slug=careers')[2]).group(1)
st, hdrs, _ = root.request('/admin/form-submissions', data=[('_csrf', tk), ('slug', 'careers'), ('submission_action', 'delete'), ('id', sid), ('return_to', '/admin/form-submissions?slug=careers')])
check('deleting a submission removes its files', before == 3 and st in (302, 303) and not os.path.isdir(folder) and not os.path.exists('app/content/forms-submissions/careers/%s.json' % sid), (before, st))
check('and says how many went', 'files=3' in (hdrs.get('Location') or ''), hdrs.get('Location'))
other_id = [s for s in submissions()][0]
other_folder = 'app/storage/form-uploads/careers/' + os.path.basename(other_id)[:-5]
tk = re.search(r'name="_csrf" value="([0-9a-f]+)"', root.get('/admin/form-submissions?slug=careers')[2]).group(1)
check('the other submission still has its files', os.path.isdir(other_folder))
st, hdrs, _ = root.request('/admin/form-submissions', data=[('_csrf', tk), ('slug', 'careers'), ('submission_action', 'bulk_delete'), ('selected_ids[]', os.path.basename(other_id)[:-5]), ('return_to', '/admin/form-submissions?slug=careers')])
check('a bulk delete removes files too', not os.path.isdir(other_folder) and submissions() == [], st)
st, _, body = root.get(fileurl)
check('a deleted file cannot be fetched', st == 404, st)

print()
print('ALL PASSED' if not fails else '%d FAILED' % len(fails))
sys.exit(1 if fails else 0)
