"""A new site has no accounts: the first visit to the sign-in page makes the first administrator, once."""
import os, sqlite3, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' + label) if ok else ('FAIL ' + label + '  ' + str(detail)))
    if not ok: fails.append(label)

APP = os.path.abspath('app')
# A site as it is when freshly installed: no account, and no file that would bring the demo one. (The first request makes the database.)
Client().get('/admin/login')
os.remove(os.path.join(APP, 'content/users/users.yaml'))
db = lambda: sqlite3.connect(os.path.join(APP, 'storage/db/app.sqlite'))
con = db(); con.execute('DELETE FROM users'); con.commit(); con.close()
users = lambda: [r for r in db().execute('select username, role, status, source from users')]

visitor = Client()
st, h, html = visitor.get('/admin')
check('the admin sends a visitor to the sign-in page', st in (301, 302) and '/admin/login' in (h.get('Location') or ''))
st, h, html = visitor.get('/admin/login')
check('which asks for the first administrator', st == 200 and 'Welcome' in html and 'name="password_confirm"' in html and 'Create the administrator' in html)
check('and has no sign-in form, so nothing can be guessed', 'Sign in with Google' not in html and 'Use your administrator credentials' not in html)
check('its fields have labels', all(('for="%s"' % n) in html for n in ['username', 'email', 'password', 'password_confirm']))

def setup(client, **override):
    fields = {'username': 'owner', 'display_name': 'The Owner', 'email': 'owner@example.test', 'password': 'Sturdy-pass-99', 'password_confirm': 'Sturdy-pass-99'}
    fields.update(override)
    return client.submit('/admin/login', has_field('password_confirm'), fields)

st, h, html = setup(visitor, password='short', password_confirm='short')
check('a short password is refused with the reason, and what was typed is kept', st == 200 and 'at least 8 characters' in html and 'value="owner"' in html and users() == [], (st, users()))
st, h, html = setup(visitor, password_confirm='Different-pass-99')
check('passwords that differ are refused', st == 200 and 'not the same' in html and users() == [])
st, h, html = setup(visitor, email='nope')
check('so is an email that is not one', st == 200 and 'valid email' in html and users() == [])
check('the password is never put back in the page', 'Sturdy-pass-99' not in html and 'short' not in html)

st, h, html = setup(visitor)
check('a good form makes the administrator and goes to the admin', st in (301, 302) and (h.get('Location') or '').endswith('/admin'), (st, h.get('Location')))
check('as a super admin made by the setup', users() == [('owner', 'superadmin', 'active', 'setup')], users())
st, h, html = visitor.get('/admin')
check('who is signed in already', st == 200 and 'Dashboard' in html or st == 200 and 'owner' in html.lower(), st)

other = Client()
st, h, html = other.get('/admin/login')
check('the page is a sign-in page now, the first visit is over', st == 200 and 'Sign in' in html and 'Welcome' not in html and 'password_confirm' not in html)
st, h, html = other.login('owner', 'Sturdy-pass-99')
check('and the new account signs in with the password chosen', st in (301, 302) and other.get('/admin')[0] == 200)
st, h, html = other.submit('/admin/login', has_field('password'), {'username': 'intruder', 'password': 'Another-pass-1', 'password_confirm': 'Another-pass-1'}) if False else Client().request('/admin/login', data=[('username', 'mallory'), ('password', 'Another-pass-1'), ('password_confirm', 'Another-pass-1'), ('email', 'm@x.test')])
check('sending the setup form again makes nothing', len(users()) == 1, users())

print()
print('ALL PASSED' if not fails else 'FAILED: %d' % len(fails))
sys.exit(1 if fails else 0)
