import re, sys, urllib.request
sys.path.insert(0, '.')
from client import Client, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:200]))
    if not ok: fails.append(label)

root = Client(); root.login()
pages = ['/admin', '/admin/media?success=Saved+it', '/admin/media?error=Nope', '/admin/menus', '/admin/menus?saved=1', '/admin/import?saved=1', '/admin/updates', '/admin/updates?checked=1', '/admin/settings?saved=1', '/admin/theme?saved=1',
         '/admin/content?type=pages', '/admin/redirects', '/admin/users', '/admin/roles', '/admin/backups', '/admin/system', '/admin/activity-logs']
old = []
for path in pages:
    st, _, html = root.get(path)
    if st != 200: old.append((path, st)); continue
    if 'data-toast-host' in html or re.search(r'class="toast[ "]', html) or 'toast-close' in html or 'media-copy-toast' in html:
        old.append((path, 'old toast markup'))
check('no screen has the old corner toasts any more', old == [], old)

st, _, html = root.get('/admin/media?success=Saved+it')
check('a media message is sent as a flash for the bar', '<template data-flash data-type="success">Saved it</template>' in html)
st, _, html = root.get('/admin/media?error=Nope')
check('a media error too, as an error', '<template data-flash data-type="error">Nope</template>' in html)
st, _, html = root.get('/admin/menus?saved=1')
check('menus say so through the bar', 'data-flash data-type="success">Menu saved.' in html)
st, _, html = root.get('/admin/updates?checked=1')
check('so does "Check status" on Updates', 'data-flash data-type="success">Update status refreshed' in html, re.findall(r'data-flash[^>]*>[^<]*', html))
check('and that screen has no bar of its own to carry it (the script makes one)', 'data-action-bar' not in html.replace('data-action-bar-', ''))

js = urllib.request.urlopen(BASE + '/assets/js/admin.js').read().decode()
check('the script always shows messages in a bar, making one when the screen has none', 'function messageBar()' in js and 'data-action-bar-floating' in js and 'showBarMessage(messageBar()' in js)
check('and no longer builds corner toasts', 'data-toast' not in js and 'TOAST_' not in js)
css = urllib.request.urlopen(BASE + '/assets/css/app.css').read().decode()
check('a bar made for messages stays out of sight until it has one', '[data-action-bar-floating]' in css and 'translateY(100%)' in css)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
