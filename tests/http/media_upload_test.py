import re, sys
sys.path.insert(0, '.')
from client import Client

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
st, _, html = root.get('/admin/media')
form = re.search(r'<form id="media-upload-form".*?</form>', html, re.S)
body = form.group(0) if form else ''
check('the media screen has its upload form', st == 200 and body != '', st)
names = re.findall(r'name="([^"]+)"', body)
check('it still sends the files, the tags, and the screen state', all(n in names for n in ('media_action', 'upload_file[]', 'upload_tags', '_state_type', '_state_page', '_csrf')), names)
check('the files field is named for assistive technology', 'aria-label="Files to upload"' in body and 'type="file"' in body and 'multiple' in body and 'required' in body)
check('the tags field keeps a label, hidden from sight', re.search(r'<span class="sr-only">Tags</span>', body) is not None and 'placeholder="Tags:' in body)
check('it is one compact row, not a tall centred box', 'p-6' not in body and 'max-w-3xl' not in body and 'px-4 py-3' in body and body.count('flex flex-wrap items-center') == 1, body[:300])
check('it says what the limit is', re.search(r'max \d+ MB per file', body) is not None)
check('files can be dropped anywhere on it', "dropzone.addEventListener('drop'" in html and 'media-dropzone' in body)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
