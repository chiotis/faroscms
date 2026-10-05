<?php
/*
 * Taking backups: when one is due, a snapshot with retention and the remote upload, the run history, the
 * notification, and the words for a result. The remote storage is a stand-in, so nothing leaves the machine.
 *   php tests/unit/backup-manager.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use FarosCMS\BackupManager;
use FarosCMS\BackupRunRepository;
use FarosCMS\BackupService;
use FarosCMS\NotificationRepository;
use FarosCMS\SystemDatabase;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/backupmgr' . getmypid();
mkdir($dir . '/storage', 0775, true);
mkdir($dir . '/content/pages', 0775, true);
file_put_contents($dir . '/content/pages/a.md', "---\ntitle: A\n---\nHello\n");
$db = new SystemDatabase($dir . '/storage');
$db->initialize();
$service = new BackupService($dir, $db);
$runs = new BackupRunRepository($db);
$notes = new NotificationRepository($db);

$settings = ['title' => 'My Site!', 'backup' => ['auto' => ['enabled' => false, 'schedule' => 'daily', 'last_run' => ''], 'local' => ['keep' => 20], 'remote' => ['enabled' => false]]];
$updates = new class { function currentVersion(): string { return '9.9.9'; } function currentGitCommit(): string { return 'abc123'; } };
$lastRuns = [];
$remoteCalls = [];
$remoteBehaviour = ['upload' => ['ok' => true, 'message' => 'sent', 'object_key' => 'site/x.zip'], 'prune' => ['ok' => true, 'deleted' => 2], 'test' => ['ok' => true, 'message' => 'connected']];
$storage = function (array $config) use (&$remoteCalls, &$remoteBehaviour) {
    return new class($config, $remoteCalls, $remoteBehaviour) {
        function __construct(public array $config, private array &$calls, private array $behaviour) {}
        function upload(string $path, string $filename): array { $this->calls[] = ['upload', $filename, is_file($path)]; return $this->behaviour['upload']; }
        function prune(int $keep): array { $this->calls[] = ['prune', $keep]; return $this->behaviour['prune']; }
        function testConnection(): array { $this->calls[] = ['test']; return $this->behaviour['test']; }
    };
};
$m = new BackupManager($service, $db, $runs, $notes, function () use (&$settings) { return $settings; }, fn() => $updates, function (string $iso) use (&$lastRuns, &$settings) { $lastRuns[] = $iso; $settings['backup']['auto']['last_run'] = $iso; }, $storage);

// ---- when a backup is due
check('never run: due', BackupManager::isDue(0, 'daily'), true);
check('daily, an hour ago: not due', BackupManager::isDue(time() - 3600, 'daily'), false);
check('daily, 25 hours ago: due', BackupManager::isDue(time() - 90000, 'daily'), true);
check('weekly, 3 days ago: not due', BackupManager::isDue(time() - 3 * 86400, 'weekly'), false);
check('weekly, 8 days ago: due', BackupManager::isDue(time() - 8 * 86400, 'weekly'), true);
check('monthly, 20 days ago: not due', BackupManager::isDue(time() - 20 * 86400, 'monthly'), false);
check('monthly, 31 days ago: due', BackupManager::isDue(time() - 31 * 86400, 'monthly'), true);
check('an unknown schedule is never due', BackupManager::isDue(time() - 999999999, 'hourly'), false);

// ---- words for a result
check('failed', BackupManager::status(['ok' => false]), 'failed');
check('success', BackupManager::status(['ok' => true, 'status' => 'success']), 'success');
check('warning', BackupManager::status(['ok' => true, 'status' => 'warning']), 'warning');
check('no status means success', BackupManager::status(['ok' => true]), 'success');
check('log levels', [BackupManager::logLevel(['ok' => false]), BackupManager::logLevel(['ok' => true, 'status' => 'warning']), BackupManager::logLevel(['ok' => true])], ['error', 'warning', 'info']);
check('query statuses', [BackupManager::queryStatus(['ok' => false]), BackupManager::queryStatus(['ok' => true, 'status' => 'warning']), BackupManager::queryStatus(['ok' => true])], ['fail', 'warn', 'ok']);

// ---- a local snapshot
$r = $m->createSnapshot();
check('a snapshot is made', [$r['ok'], $r['status']], [true, 'success']);
check('the archive exists, named after the site', is_file($dir . '/storage/backups/' . $r['filename']) && str_starts_with($r['filename'], 'my-site'), true);
check('no remote is not a failure', [$r['remote'], $remoteCalls], [null, []]);
$list = $service->list();
check('the archive knows its reason and version', [$list[0]['reason'], $list[0]['version']], ['manual', '9.9.9']);
$m->recordRun($r);
$recent = $runs->recent(5);
check('the run is recorded with its size', [$recent[0]['filename'] === $r['filename'], $recent[0]['status'], $recent[0]['size_bytes'] > 0], [true, 'success', true]);
$d = $m->createDatabaseSnapshot();
check('a database snapshot is made', [$d['ok'], str_contains($d['filename'], 'database')], [true, true]);

// ---- retention (only after a snapshot; a safety snapshot never deletes)
sleep(1);
$settings['backup']['local']['keep'] = 1;
$kept = $m->createSnapshot('pre-restore', false, false);
check('a safety snapshot is not pruned', count($service->list()) >= 3, true);
sleep(1); // the newest is decided by the time of change, in whole seconds
$last = $m->createSnapshot();
check('with keep = 1 only the newest stays', array_column($service->list(), 'filename'), [$last['filename']]);
$settings['backup']['local']['keep'] = 20;

// ---- remote upload
$settings['backup']['remote'] = ['enabled' => true, 'keep' => 5, 'bucket' => 'b'];
sleep(1);
$r = $m->createSnapshot();
check('upload succeeds', [$r['status'], $r['remote']['ok']], ['success', true]);
check('the message says so, with the object and the pruned count', str_contains($r['message'], 'Remote upload completed.') && str_contains($r['message'], 'Object: site/x.zip.') && str_contains($r['message'], 'Pruned 2 remote backup(s).'), true);
check('the file was there when uploading, and the remote keep was used', array_slice($remoteCalls, 0, 2), [['upload', $r['filename'], true], ['prune', 5]]);
$remoteCalls = [];
sleep(1);
$skip = $m->createSnapshot('pre-restore', false, false);
check('a safety snapshot is not uploaded', [$skip['remote'], $remoteCalls], [null, []]);
$remoteBehaviour['upload'] = ['ok' => false, 'message' => 'no route'];
sleep(1);
$w = $m->createSnapshot();
check('a failed upload is a warning, the archive is kept', [$w['ok'], $w['status'], is_file($dir . '/storage/backups/' . $w['filename'])], [true, 'warning', true]);
check('and says why', str_contains($w['message'], 'Saved locally, but remote upload failed: no route'), true);
check('testing the remote asks the storage', $m->testRemote(), ['ok' => true, 'message' => 'connected']);
$settings['backup']['remote'] = ['enabled' => false];

// ---- the notification
$m->notify(['ok' => true, 'status' => 'success', 'message' => 'done', 'filename' => 'x.zip'], true);
$m->notify(['ok' => true, 'status' => 'warning', 'message' => 'careful'], false);
$m->notify(['ok' => false, 'message' => 'broke'], false);
$types = array_column($notes->recent(10), 'type');
sort($types);
check('one notification per outcome', $types, ['backup.failed', 'backup.remote_failed', 'backup.success']);
$m->notify(['ok' => true, 'status' => 'success', 'message' => 'done', 'filename' => 'x.zip'], true);
check('the same one is not repeated', count($notes->recent(10)), 3);

// ---- the schedule
$before = count($service->list());
$m->runIfDue();
check('off: nothing runs', [count($service->list()), $lastRuns], [$before, []]);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'hourly', 'last_run' => ''];
$m->runIfDue();
check('an unknown schedule does not run', count($service->list()), $before);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'daily', 'last_run' => ''];
sleep(1);
$m->runIfDue();
check('due: a scheduled backup is made, the time stored, the run recorded', [count($service->list()) > 0, count($lastRuns), $runs->recent(1)[0]['status']], [true, 1, 'success']);
check('and it says it was scheduled', $service->list()[0]['reason'], 'scheduled');
$count = count($service->list());
$m->runIfDue();
check('just ran: not due again', [count($service->list()), count($lastRuns)], [$count, 1]);
$settings['backup']['auto']['last_run'] = date('c', time() - 2 * 86400);
$lock = fopen($dir . '/storage/backups/.auto-backup.lock', 'c');
flock($lock, LOCK_EX);
$m->runIfDue();
check('while another request holds the lock nothing runs', [count($service->list()), count($lastRuns)], [$count, 1]);
flock($lock, LOCK_UN);
fclose($lock);

// ---- the status line for the system check
$settings['backup']['auto'] = ['enabled' => false];
check('off with backups made: ok', $m->scheduleStatus(), ['label' => 'Scheduled backups', 'value' => 'off', 'status' => 'ok']);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'daily', 'last_run' => date('c')];
$s = $m->scheduleStatus();
check('on and recent: ok', [$s['status'], str_starts_with($s['value'], 'daily, last ')], ['ok', true]);
$settings['backup']['auto'] = ['enabled' => true, 'schedule' => 'daily', 'last_run' => date('c', time() - 5 * 86400)];
$s = $m->scheduleStatus();
check('on but overdue: warning', [$s['status'], $s['value']], ['warning', 'overdue (daily)']);

// ---- a file of the site named like the archive's own manifest does not break the check
file_put_contents($dir . '/' . BackupService::MANIFEST_NAME, '{"stray":"a backup unzipped in the project"}');
$stray = $m->createSnapshot('pre-restore', false, false);
$v = $service->verify((string)$stray['filename']);
check('a stray manifest-named file: the snapshot still verifies', [$v['ok'], $v['errors']], [true, []]);
unlink($dir . '/' . BackupService::MANIFEST_NAME);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
