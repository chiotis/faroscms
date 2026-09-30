import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

def write(path): return open(path, 'rb').read()

root = Client(); root.login()

# A browser sends a text box's line breaks as CRLF. The file keeps LF, so it does not change every line in Git.
st, hdr, _ = root.submit('/admin/edit?type=pages&slug=crlf-page&lang=en', has_field('body'),
    {'title': 'CRLF page', 'body': "First line\r\n\r\nSecond paragraph\r\nwith a break\r\n", 'blocks_editor': '0'})
data = write('app/content/pages/crlf-page.en.md')
check('the page is saved', st == 302, st)
check('its file has no carriage returns', b'\r' not in data, data[:200])
check('and the text is all there', b'First line\n\nSecond paragraph\nwith a break' in data, data)

# The same for the raw front matter typed into the Advanced tab.
st, hdr, _ = root.submit('/admin/edit?type=pages&slug=crlf-raw&lang=en', has_field('body'),
    {'title': 'CRLF raw', 'body': 'x', 'blocks_editor': '0', 'frontmatter': "blocks:\r\n  - type: text\r\n    body: hi\r\n"})
data = write('app/content/pages/crlf-raw.en.md')
check('raw front matter is saved without carriage returns too', st == 302 and b'\r' not in data and b'body: hi' in data, (st, data[:200]))

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
