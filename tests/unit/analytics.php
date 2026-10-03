<?php
/*
 * The analytics: the choices of the settings and the code they put in the pages, what a visit is made into (and what is refused), and
 * the store that counts days: rows, a day summed into its counts, a range of days, the hours, who is on the site now.
 *   php tests/unit/analytics.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use FarosCMS\{AnalyticsCollector as C, AnalyticsSettings as S, AnalyticsStore, SystemDatabase, SystemMetaRepository};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

// ---- the settings
$none = S::from([]);
check('a site that never opened the screen is not tracked', [$none['mode'], $none['skip_signed_in'], $none['respect_dnt'], $none['keep_months']], ['off', true, true, 24]);
check('and gets nothing in its pages', [S::head($none, false, '/a.js', '/_a/collect'), S::body($none, false)], ['', '']);
check('a mode that is not one is off', S::from(['analytics' => ['mode' => 'evil']])['mode'], 'off');
check('a hand-edited file is read safely', S::from(['analytics' => ['mode' => ['x'], 'tag_id' => ['x'], 'head_code' => ['x'], 'ignore_paths' => 'no', 'keep_months' => 'forever']])['mode'], 'off');
check('tag IDs: Google tags and Tag Manager', [S::tag('g-abc123def4'), S::tag('GTM-ABC123'), S::tag('AW-123456789'), S::tag('G-"><script>'), S::tag('UA-123'), S::tag('')], ['G-ABC123DEF4', 'GTM-ABC123', 'AW-123456789', '', '', '']);

$p = S::apply([], ['mode' => 'platform', 'respect_dnt' => '1', 'ignore_paths' => "/thank-you\nnonsense", 'keep_months' => '60'], true);
check('platform: the boxes not sent are off, the paths are cleaned', [$p['mode'], $p['respect_dnt'], $p['skip_signed_in'], $p['track_links'], $p['ignore_paths'], $p['keep_months']], ['platform', true, false, false, ['/thank-you'], 60]);
$tag = S::tag('G-ABCD1234');
$gtag = S::from(['analytics' => S::apply([], ['mode' => 'custom', 'tag_id' => 'G-ABCD1234', 'head_code' => '<script>x()</script>', 'body_code' => '<b>y</b>', 'skip_signed_in' => '1'], true)]);
$head = S::head($gtag, false, '', '');
check('a Google tag is made into its code', [str_contains($head, 'googletagmanager.com/gtag/js?id=G-ABCD1234'), str_contains($head, "gtag('config','G-ABCD1234')"), str_ends_with($head, '<script>x()</script>')], [true, true, true]);
check('and the code of the owner goes after it, as written; the body code in the body', [S::body($gtag, false)], ['<b>y</b>']);
check('a person who is signed in gets no tracking at all', [S::head($gtag, true, '', ''), S::body($gtag, true)], ['', '']);
$gtm = S::from(['analytics' => ['mode' => 'custom', 'tag_id' => 'GTM-ABC123']]);
check('Tag Manager has a head part and a noscript part', [str_contains(S::head($gtm, false, '', ''), "'GTM-ABC123'"), str_contains(S::body($gtm, false), 'ns.html?id=GTM-ABC123')], [true, true]);
check('the code can only be changed by who may write it', [S::apply(['mode' => 'custom', 'head_code' => '<i>old</i>'], ['mode' => 'custom', 'head_code' => '<script>evil()</script>', 'tag_id' => 'G-EVIL1234'], false)['head_code'], isset(S::apply(['mode' => 'custom'], ['tag_id' => 'G-EVIL1234'], false)['tag_id'])], ['<i>old</i>', false]);
check('but the choice can', S::apply(['mode' => 'custom', 'head_code' => '<i>old</i>'], ['mode' => 'off'], false)['mode'], 'off');
check('the code is cut to its length', mb_strlen(S::from(['analytics' => ['head_code' => str_repeat('a', 30000)]])['head_code']), S::CODE_LIMIT);
$plat = S::from(['analytics' => ['mode' => 'platform', 'respect_dnt' => false, 'track_links' => false]]);
$tagHtml = S::head($plat, false, '/assets/js/faros-analytics.js?v=1', '/_a/collect');
check('the platform adds its script with what it needs', [$tagHtml, S::body($plat, false)], ['<script defer src="/assets/js/faros-analytics.js?v=1" data-api="/_a/collect" data-links="0" data-dnt="0"></script>', '']);
check('and not for a person who is signed in', S::head(S::from(['analytics' => ['mode' => 'platform']]), true, 'a', 'b'), '');
check('unless the site counts them too', S::head(S::from(['analytics' => ['mode' => 'platform', 'skip_signed_in' => false]]), true, 'a', 'b') !== '', true);

// ---- what a visit is made into
check('robots are known', [C::isBot('Googlebot/2.1'), C::isBot(''), C::isBot('curl/8.0'), C::isBot('Mozilla/5.0 (X11; Linux) HeadlessChrome/120'), C::isBot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36')], [true, true, true, true, false]);
check('a path is a page of the site', [C::path('/about'), C::path('/about/?x=1#top'), C::path('/en/caf%C3%A9'), C::path('//evil.test/x'), C::path('about'), C::path('/admin/users'), C::path('/_a/collect'), C::path("/a\x00b"), C::path('/'), C::path('/a//b/')], ['/about', '/about', '/en/café', '', '', '', '', '/ab', '/', '/a/b']);
check('an event is a kind and a value', [C::event('outbound', 'example.com'), C::event('download', "a|b\nc.pdf"), C::event('hack', 'x'), C::event('form', '<b>contact</b>'), C::event('tel', '')], ['outbound|example.com', 'download|a b c.pdf', '', 'form|contact', 'tel|']);
check('a visitor is the same for the same address and browser that day, and for nothing else', [C::visitor('s1', '1.2.3.4', 'UA', 'h') === C::visitor('s1', '1.2.3.4', 'UA', 'h'), C::visitor('s1', '1.2.3.4', 'UA', 'h') === C::visitor('s2', '1.2.3.4', 'UA', 'h'), C::visitor('s1', '1.2.3.4', 'UA', 'h') === C::visitor('s1', '1.2.3.5', 'UA', 'h'), strlen(C::visitor('s', 'a', 'b', 'c'))], [true, false, false, 16]);
check('devices: by the width of the window, then by the browser', [C::device(390, ''), C::device(820, ''), C::device(1440, ''), C::device(0, 'Mozilla/5.0 (iPhone) Mobile'), C::device(0, 'Mozilla/5.0 (iPad)'), C::device(0, 'Mozilla/5.0 (Windows NT 10.0)')], ['mobile', 'tablet', 'desktop', 'mobile', 'tablet', 'desktop']);
check('browsers', [C::browser('Mozilla/5.0 Chrome/120 Safari/537 Edg/120'), C::browser('Mozilla/5.0 Chrome/120 Safari/537'), C::browser('Mozilla/5.0 Version/17 Safari/605'), C::browser('Mozilla/5.0 Firefox/121'), C::browser('weird')], ['Edge', 'Chrome', 'Safari', 'Firefox', 'Other']);
check('systems', [C::os('Mozilla/5.0 (iPhone; CPU iPhone OS 17) Mac OS X'), C::os('Mozilla/5.0 (Linux; Android 14)'), C::os('Mozilla/5.0 (Windows NT 10.0)'), C::os('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)'), C::os('x')], ['iOS', 'Android', 'Windows', 'macOS', 'Other']);
check('the country is only what the host says', [C::country(['HTTP_CF_IPCOUNTRY' => 'gr']), C::country(['HTTP_CF_IPCOUNTRY' => 'XX']), C::country(['HTTP_CF_IPCOUNTRY' => 'Greece']), C::country([])], ['GR', '', '', '']);
check('the language of the browser', [C::language('el-GR,el;q=0.9,en;q=0.8'), C::language('en'), C::language('')], ['el', 'en', '']);
check('where a visit came from: no page, the same site', [C::referrer('', 'site.test'), C::referrer('https://www.site.test/about', 'site.test')], [['Direct', 'direct'], ['Direct', 'direct']]);
check('a search engine, a social network, another site', [C::referrer('https://www.google.gr/', 'site.test'), C::referrer('https://duckduckgo.com/', 'site.test'), C::referrer('https://l.facebook.com/l.php?u=x', 'site.test'), C::referrer('https://t.co/abc', 'site.test'), C::referrer('https://blog.example.org/post', 'site.test')], [['Google', 'search'], ['DuckDuckGo', 'search'], ['Facebook', 'social'], ['X', 'social'], ['blog.example.org', 'referral']]);
check('a name that only looks like one is another site', [C::referrer('https://notgoogle.com/', 'site.test')[1], C::referrer('https://google.evil.test/', 'site.test')[1]], ['referral', 'referral']);
check('a tagged campaign wins over the page it came from', [C::origin('https://www.google.com/', '?utm_source=news&utm_medium=email&utm_campaign=Spring+2026', 'site.test'), C::origin('', '?utm_medium=cpc&utm_campaign=x', 'site.test')[1], C::origin('https://www.google.com/', '?q=1', 'site.test')], [['news', 'email', 'Spring 2026'], 'paid', ['Google', 'search', '']]);
check('tags that are not text are cleaned', C::origin('', '?utm_source=%3Cscript%3Ea&utm_campaign=' . str_repeat('x', 200), 'site.test')[0], 'scripta');

// ---- the store
$dir = sys_get_temp_dir() . '/an' . getmypid();
mkdir("$dir/storage/db", 0775, true);
$db = new SystemDatabase("$dir/storage"); $db->initialize();
$store = new AnalyticsStore($db, new SystemMetaRepository($db), 12);
$settings = ['analytics' => ['mode' => 'platform']];
$collector = new C(fn() => $store, fn() => $settings);
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
$srv = fn(string $ip, array $more = []) => $more + ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua, 'HTTP_HOST' => 'site.test', 'HTTP_ACCEPT_LANGUAGE' => 'el-GR', 'HTTP_CF_IPCOUNTRY' => 'GR'];
$t0 = strtotime('2026-03-10 12:00:00');
$day = date('Y-m-d', $t0);
check('a view is counted', $collector->collect(['p' => '/', 'r' => 'https://www.google.com/', 'w' => 1440], $srv('1.1.1.1'), false, $t0), 'ok');
check('and a second page of the same visitor', $collector->collect(['p' => '/about', 'r' => 'https://site.test/', 'w' => 1440], $srv('1.1.1.1'), false, $t0 + 40), 'ok');
check('a visitor who leaves after one page', $collector->collect(['p' => '/about', 'r' => 'https://l.facebook.com/', 'w' => 390], $srv('2.2.2.2', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17 like Mac OS X) Safari/605', 'HTTP_CF_IPCOUNTRY' => 'DE']), false, $t0 + 60), 'ok');
check('an event of the page', $collector->collect(['p' => '/about', 'e' => 'outbound', 'v' => 'example.com'], $srv('1.1.1.1'), false, $t0 + 70), 'ok');
check('what is refused', [
    $collector->collect(['p' => '/x'], $srv('3.3.3.3', ['HTTP_USER_AGENT' => 'Googlebot']), false, $t0),
    $collector->collect(['p' => '/x'], $srv('3.3.3.3'), true, $t0),
    $collector->collect(['p' => '/x'], $srv('3.3.3.3', ['HTTP_DNT' => '1']), false, $t0),
    $collector->collect(['p' => '/x'], $srv('3.3.3.3', ['HTTP_ORIGIN' => 'https://evil.test']), false, $t0),
    $collector->collect(['p' => '/admin/users'], $srv('3.3.3.3'), false, $t0),
    $collector->collect(['p' => '/x', 'e' => 'nonsense'], $srv('3.3.3.3'), false, $t0),
    $collector->collect(['p' => '/x'], $srv('3.3.3.3', ['HTTP_ORIGIN' => 'https://site.test']), false, $t0) ,
], ['bot', 'signed-in', 'dnt', 'origin', 'path', 'event', 'ok']);
$off = new C(fn() => $store, fn() => ['analytics' => ['mode' => 'custom']]);
check('nothing is counted when the platform is not chosen', $off->collect(['p' => '/x'], $srv('4.4.4.4'), false, $t0), 'off');
$ign = new C(fn() => $store, fn() => ['analytics' => ['mode' => 'platform', 'ignore_paths' => ['/private/']]]);
check('nor a page that is ignored', [$ign->collect(['p' => '/private/a'], $srv('4.4.4.4'), false, $t0), $ign->collect(['p' => '/public'], $srv('4.4.4.4'), false, $t0)], ['path', 'ok']);
$count = (int)$db->connection()->query('SELECT COUNT(*) FROM analytics_hits')->fetchColumn();
check('the rows are what was counted', $count, 6);
check('and hold no address', $db->connection()->query("SELECT COUNT(*) FROM analytics_hits WHERE visitor LIKE '%.%' OR source LIKE '%1.1.1.1%'")->fetchColumn(), 0);

$d = $store->compute($day);
check('the day: views, visitors, one left after one page, the time of the other visit', $d['total'], ['views' => 5, 'visitors' => 4, 'bounces' => 3, 'seconds' => 40]);
check('pages with their views and visitors', [$d['kinds']['page']['/about'], $d['kinds']['page']['/']], [[2, 2], [1, 1]]);
check('where visitors came from is read from their first visit', [$d['kinds']['channel']['search'] ?? null, $d['kinds']['channel']['social'] ?? null, $d['kinds']['source']['Google'] ?? null, $d['kinds']['entry']['/'] ?? null], [[2, 1], [1, 1], [2, 1], [1, 1]]);
check('with what they came: the device, the browser, the country, the language', [array_keys($d['kinds']['device']), $d['kinds']['browser']['Chrome'][1], $d['kinds']['country']['DE'] ?? null, $d['kinds']['lang']['el'][1]], [['desktop', 'mobile'], 3, [1, 1], 4]);
check('events apart from views', $d['kinds']['event'], ['outbound|example.com' => [1, 1]]);

$other = date('Y-m-d', $t0 + 86400 * 2);
$collector->collect(['p' => '/'], $srv('1.1.1.1'), false, $t0 + 86400 * 2);
check('the next day, a visitor is another one', $store->compute($other)['total']['visitors'], 1);
check('and the salt of the first day is gone', str_starts_with((string)(new SystemMetaRepository($db))->get('analytics_salt'), $other . ':'), true);
check('the day that is over is summed, its rows gone, nothing lost', [(int)$db->connection()->query("SELECT COUNT(*) FROM analytics_hits WHERE day = '$day'")->fetchColumn(), (int)$db->connection()->query("SELECT views FROM analytics_daily WHERE day = '$day' AND kind = 'total'")->fetchColumn(), (int)$db->connection()->query("SELECT visitors FROM analytics_daily WHERE day = '$day' AND kind = 'page' AND key = '/about'")->fetchColumn()], [0, 5, 2]);
$r = $store->range($day, $other, $other);
check('a range: each day, the days with no visits as zero, the totals of a kind from the days and from today', [count($r['days']), $r['days'][$day]['views'], date('Y-m-d', strtotime($day . ' +1 day')) === array_keys($r['days'])[1] ? $r['days'][array_keys($r['days'])[1]]['views'] : -1, $r['days'][$other]['views'], $r['kinds']['page']['/'], $r['kinds']['page']['/about']], [3, 5, 0, 1, [2, 2], [2, 2]]);
$h = $store->hours($other);
check('the hours of a day', [count($h), $h[12]['views'], array_sum(array_column($h, 'views'))], [24, 1, 1]);
$store->record(['ts' => time(), 'day' => date('Y-m-d'), 'visitor' => 'aaaa', 'kind' => 'view', 'path' => '/now', 'name' => '', 'source' => '', 'channel' => 'direct', 'campaign' => '', 'device' => 'desktop', 'browser' => 'Chrome', 'os' => 'Linux', 'country' => '', 'lang' => '']);
check('who is on the site now', $store->now(5), ['visitors' => 1, 'pages' => ['/now' => 1]]);
for ($i = 0; $i < 420; $i++) { $store->record(['ts' => time(), 'day' => date('Y-m-d'), 'visitor' => 'spam', 'kind' => 'view', 'path' => '/s', 'name' => '', 'source' => '', 'channel' => 'direct', 'campaign' => '', 'device' => '', 'browser' => '', 'os' => '', 'country' => '', 'lang' => '']); }
check('a visitor cannot leave more than a few hundred rows a day', (int)$db->connection()->query("SELECT COUNT(*) FROM analytics_hits WHERE visitor = 'spam'")->fetchColumn(), 400);
$store->rollup($day); $store->rollup($day);
check('summing a day twice gives the same', (int)$db->connection()->query("SELECT COUNT(*) FROM analytics_daily WHERE day = '$day' AND kind = 'total'")->fetchColumn(), 1);
check('the stats', [$store->stats()['days'] >= 1, $store->stats()['first']], [true, $day]);
$store->maintain(date('Y-m-d', strtotime($day . ' +20 months')), 12);
check('days older than the time they are kept go', (int)$db->connection()->query('SELECT COUNT(*) FROM analytics_daily')->fetchColumn(), 0);
$store->clear();
check('everything counted can be deleted', [(int)$db->connection()->query('SELECT COUNT(*) FROM analytics_hits')->fetchColumn(), (int)$db->connection()->query('SELECT COUNT(*) FROM analytics_daily')->fetchColumn()], [0, 0]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
