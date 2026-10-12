"""The scheduled backup does not depend on someone opening the admin: the cron script takes it when it is due (and says what it did),
the Backups screen tells how to set that up, and a backup is not found early by a job that runs at the same time every day."""
import glob, os, re, subprocess, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + ' '.join(str(detail).split())[:300]))
    if not ok: fails.append(label)

root = Client(); root.login()
def cron():
    r = subprocess.run(['php', '-d', 'disable_functions=set_time_limit,ignore_user_abort,disk_free_space,opcache_reset,curl_exec', 'app/src/cron-backup.php'], capture_output=True, text=True)
    return r.stdout.strip(), r.returncode, r.stderr.strip()
def archives(): return sorted(glob.glob('app/storage/backups/*.zip'))

check('the script does nothing while there is no schedule', cron()[:2] == ('off', 0), cron())
st, _, html = root.get('/admin/backups')
check('and the Backups screen has no cron note then', 'src/cron-backup.php' not in html)

root.submit('/admin/settings', has_field('backup_schedule'), {'backup_auto_enabled': '1', 'backup_schedule': 'daily', 'backup_remote_enabled': None}, drop=('backup_remote_enabled',))
before = len(archives())
out, code, err = cron()
check('with a daily schedule that has never run, the script takes the backup', out == 'ok' and code == 0 and len(archives()) == before + 1, (out, code, err, before, len(archives())))
out, code, _ = cron()
check('and says it is not due when run again', out == 'not due' and code == 0 and len(archives()) == before + 1, (out, len(archives())))
st, _, html = root.get('/admin/backups')
check('the Backups screen shows the run, and the cron line to add', 'scheduled' in html.lower() and re.search(r'0 3 \* \* \* php [^<]*src/cron-backup\.php', html) is not None, re.findall(r'0 3[^<]*', html))
st, _, _ = Client().get('/admin/backups')
check('the script cannot be reached from the web', Client().get('/src/cron-backup.php')[0] == 404)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
sys.exit(1 if fails else 0)
