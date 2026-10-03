import json, re, sqlite3, sys, urllib.request
sys.path.insert(0, '.')
from client import Client, has_field, BASE

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
root.submit('/admin/users-edit', has_field('username'), {'username': 'ed1', 'email': 'ed1@example.test', 'display_name': 'ed1', 'role': 'editor', 'status': 'active', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'})
ed = Client(); ed.login('ed1', 'Sturdy-pass-99')
anon = Client()
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'

def settings_form(f): return any(x[0] == 'ignore_paths' for x in f['fields']) and any(x[0] == 'keep_months' for x in f['fields'])
def save(overrides, drop=None): return root.submit('/admin/analytics?tab=settings', settings_form, overrides, drop=drop)
def rows(where=''):
    db = sqlite3.connect('app/storage/db/app.sqlite')
    return db.execute('select count(*) from analytics_hits ' + where).fetchone()[0]
def collect(payload, ua=UA, headers=None, client=None):
    h = {'User-Agent': ua, 'Content-Type': 'text/plain'}; h.update(headers or {})
    req = urllib.request.Request(BASE + '/_a/collect', data=json.dumps(payload).encode(), headers=h, method='POST')
    if client is not None:
        client.jar.add_cookie_header(req)
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *a, **k): return None
    try:
        r = urllib.request.build_opener(NoRedirect).open(req); return r.status
    except urllib.error.HTTPError as e:
        return e.code

# ---- the screen, and who may
st, _, html = root.get('/admin/analytics')
check('the screen opens, with the three choices', st == 200 and 'No tracking' in html and 'Your own tracking code' in html and 'FarosCMS analytics' in html, st)
check('and Analytics is in the System menu', 'href="/admin/analytics"' in html)
check('an editor cannot open it, nor see it in the menu', ed.get('/admin/analytics')[0] == 403 and 'href="/admin/analytics"' not in ed.get('/admin')[2])
check('a visitor is sent to sign in', anon.get('/admin/analytics')[0] in (302, 303))

# ---- by default nothing is added, and nothing is counted
st, html = anon.get('/')[0], anon.last_html
check('a page has no tracking by default', 'faros-analytics' not in html and 'googletagmanager' not in html)
check('the report address answers, and counts nothing', collect({'p': '/'}) == 204 and rows() == 0, rows())
check('the address takes only POST', anon.get('/_a/collect')[0] == 405)
check('the script is served', anon.get('/assets/js/faros-analytics.js')[0] == 200 and 'sendBeacon' in anon.last_html)

# ---- your own tracking code
save({'mode': 'custom', 'tag_id': 'g-abcd123456', 'head_code': '<script defer src="https://plausible.test/js.js"></script>', 'body_code': '<!-- body-code -->'})
st, _, html = anon.get('/')
check('a Google tag is made into its code, in the head', 'googletagmanager.com/gtag/js?id=G-ABCD123456' in html and "gtag('config','G-ABCD123456')" in html and html.index('gtag/js') < html.index('</head>'), html[:200])
check('with the code of the owner after it, as written', '<script defer src="https://plausible.test/js.js"></script>' in html and html.index("gtag('config'") < html.index('plausible.test'))
check('and the code for the body after the opening tag', re.search(r'<body[^>]*>\s*<!-- body-code -->', html) is not None)
check('a person who is signed in gets none of it', 'plausible.test' not in root.get('/')[2] and 'gtag' not in root.last_html)
check('nothing is counted by the platform in this mode', collect({'p': '/'}) == 204 and rows() == 0)
st, _, shown = root.get('/admin/analytics?tab=settings')
check('the code is shown back as written', 'plausible.test/js.js' in shown and 'value="G-ABCD123456"' in shown)
save({'mode': 'custom', 'tag_id': 'GTM-ABC1234', 'head_code': '', 'body_code': ''})
st, _, html = anon.get('/')
check('Tag Manager has a head part and a noscript part in the body', "'GTM-ABC1234'" in html and 'ns.html?id=GTM-ABC1234' in html)
save({'mode': 'custom', 'tag_id': '"><script>alert(1)</script>', 'head_code': '', 'body_code': ''})
check('a tag ID that is not one is not used', 'alert(1)' not in anon.get('/')[2])
save({'mode': 'custom', 'tag_id': '', 'head_code': '<i>x</i>', 'body_code': '', 'skip_signed_in': None})
check('people who are signed in can be counted too', '<i>x</i>' in root.get('/')[2])
save({'mode': 'off'})
check('off adds nothing again', '<i>x</i>' not in anon.get('/')[2])

# ---- the platform's analytics
save({'mode': 'platform', 'skip_signed_in': '1'})
st, _, html = anon.get('/')
check('the script of the platform is in the pages, with where to report', re.search(r'<script defer src="[^"]*/assets/js/faros-analytics\.js\?v=[^"]*" data-api="[^"]*/_a/collect"></script>', html) is not None, re.findall(r'<script[^>]*analytics[^>]*>', html))
check('and not for a person who is signed in', 'faros-analytics' not in root.get('/')[2])
check('a view is counted', collect({'p': '/about', 'r': 'https://www.google.com/', 'q': '?utm_campaign=Spring', 'w': 1440}) == 204 and rows() == 1, rows())
row = sqlite3.connect('app/storage/db/app.sqlite').execute('select path, source, channel, campaign, device, browser, os, visitor from analytics_hits').fetchone()
check('as what it is, with no address in it', row[:7] == ('/about', 'Google', 'campaign', 'Spring', 'desktop', 'Chrome', 'Windows') and '127.0.0.1' not in ' '.join(row) and len(row[7]) == 16, row)
check('a second page of the same visitor and an event', collect({'p': '/services', 'r': BASE + '/about', 'w': 1440}) == 204 and collect({'p': '/services', 'e': 'download', 'v': 'brochure.pdf'}) == 204 and rows() == 3 and rows("where kind = 'event'") == 1)
check('robots are not counted', collect({'p': '/x'}, ua='Googlebot/2.1') == 204 and rows() == 3)
check('nor visitors who ask not to be tracked', collect({'p': '/x'}, headers={'DNT': '1'}) == 204 and collect({'p': '/x'}, headers={'Sec-GPC': '1'}) == 204 and rows() == 3)
check('nor a page of another site, or of the admin', collect({'p': '//evil.test/x'}) == 204 and collect({'p': '/admin/users'}) == 204 and rows() == 3)
check('nor a report from another site', collect({'p': '/x'}, headers={'Origin': 'https://evil.test'}) == 204 and rows() == 3)
check('nor a person who is signed in (the report carries the session)', collect({'p': '/x'}, client=root) == 204 and rows() == 3)
check('garbage is answered as the rest and counts nothing', collect({'p': ['x'], 'e': 5}) == 204 and rows() == 3)
save({'mode': 'platform', 'ignore_paths': '/private/'})
check('a page that is ignored is not counted', collect({'p': '/private/a'}) == 204 and rows() == 3 and collect({'p': '/public'}) == 204 and rows() == 4)
save({'mode': 'platform', 'respect_dnt': None})
check('Do Not Track can be left aside', collect({'p': '/dnt'}, headers={'DNT': '1'}) == 204 and rows("where path = '/dnt'") == 1)
st, _, html = anon.get('/')
check('and the script is told', 'data-dnt="0"' in html)

# ---- what is kept can be deleted
st, _, shown = root.get('/admin/analytics?tab=settings')
check('the settings say what is kept', 'visits' in shown and 'Delete everything counted' in shown)
root.submit('/admin/analytics?tab=settings', lambda f: any(x == ['do', 'clear'] for x in f['fields']))
check('everything counted can be deleted', rows() == 0)
st, _, logs = root.get('/admin/activity-logs')
check('and the changes are in the activity log', 'Analytics settings saved' in logs and 'Analytics data deleted' in logs)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
