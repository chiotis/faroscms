<?php
/*
 * Menus: reading and writing the YAML files, checking items, nesting from the admin form, which menu is in which
 * place, labels in the right language, and which item is active for a page.
 *   php tests/unit/menus.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use FarosCMS\Menus;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/menus' . getmypid();
mkdir($dir, 0775, true);
$settings = ['languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index', 'base_url' => 'https://site.test', 'menu_locations' => ['header' => 'main', 'footer' => 'footer', 'sidebar' => 'side']];
$strings = ['nav.main.about' => 'About us', 'nav.main.home' => 'Home'];
$translate = fn(string $key, ?string $fallback = null): string => $strings[$key] ?? ($fallback ?? $key);
$locations = ['sidebar' => ['default' => 'side']];
$m = new Menus($dir, fn() => $settings, $translate, fn() => $locations);

// ---- checking items
$items = $m->normalize([
    ['label' => 'About', 'url' => 'about', 'class' => ' nav-cta ', 'target' => '_blank'],
    ['label' => '', 'label_key' => '', 'url' => ''],
    'not an item',
    ['labels' => ['en' => 'Services'], 'url' => 'services', 'children' => [
        ['label' => 'Design', 'url' => 'design', 'children' => [['label' => 'Deep', 'url' => 'deep', 'children' => [['label' => 'Too deep', 'url' => 'x']]]]],
    ]],
]);
check('empty rows and non-items are dropped', count($items), 2);
check('a plain label is the default language label, other languages are empty', $items[0]['labels'], ['el' => 'About', 'en' => '']);
check('class and target are trimmed', [$items[0]['class'], $items[0]['target']], ['nav-cta', '_blank']);
check('a language label alone keeps the item', [$items[1]['labels']['en'], $items[1]['url']], ['Services', 'services']);
check('items nest three levels', $items[1]['children'][0]['children'][0]['label'], 'Deep');
check('and no deeper', isset($items[1]['children'][0]['children'][0]['children']), false);
check('anything that is not a list gives no items', $m->normalize('nope'), []);

// ---- the admin form: rows with depths become a tree
$rows = $m->fromAdminRows(['', '', '', '', ''], ['el' => ['A', 'B', 'C', 'D', ''], 'en' => ['', '', '', '', '']], ['a', 'b', 'c', 'd', ''], ['', '', '', '', ''], ['', '', '', '', ''], ['1', '2', '3', '2', '1'], ['el', 'en']);
check('depths nest rows under the row above', [count($rows), $rows[0]['label'] ?? '', count($rows[0]['children'])], [1, '', 2]);
check('a level under a level nests again', $rows[0]['children'][0]['children'][0]['labels']['el'], 'C');
check('a second child goes next to the first', $rows[0]['children'][1]['labels']['el'], 'D');
check('an empty row is skipped', count($rows), 1);
$jump = $m->fromAdminRows([''], ['el' => ['Only']], ['x'], [''], [''], ['3'], ['el', 'en']);
check('a first row that claims to be deep is brought up to the top', count($jump), 1);
$flat = $m->flatten($rows, ['el', 'en']);
check('flattening gives one row per item with its depth', array_map(fn($r) => $r['depth'] . $r['labels']['el'], $flat), ['1A', '2B', '3C', '2D']);

// ---- writing and reading
$m->write('main', ['title' => 'Main Menu', 'items' => $items]);
check('a menu is written to content/menus', is_file($dir . '/menus/main.yaml'), true);
$read = $m->load('main');
check('and read back, checked', [$read['title'], count($read['items']), $read['items'][0]['url']], ['Main Menu', 2, 'about']);
check('a menu that does not exist is empty, with a title from its key', $m->load('side-bar'), ['title' => 'Side Bar', 'items' => []]);
file_put_contents($dir . '/menus/main.yaml', "title: Changed\nitems: []\n");
check('what was read is kept until told the files changed', $m->load('main')['title'], 'Main Menu');
$m->forget();
check('and read again after that', $m->load('main')['title'], 'Changed');
check('a menu key is made safe for a file name', basename($m->path('../Evil Menu')), 'evil-menu.yaml');
check('an empty key is "menu"', basename($m->path('')), 'menu.yaml');

// ---- defaults and the list
$fresh = sys_get_temp_dir() . '/menus-fresh' . getmypid();
$m2 = new Menus($fresh, fn() => $settings, $translate, fn() => $locations);
$m2->ensureDefaults();
check('a new site gets a main and a footer menu', [is_file($fresh . '/menus/main.yaml'), is_file($fresh . '/menus/footer.yaml')], [true, true]);
check('the main menu starts with the home item', $m2->load('main')['items'][0]['label_key'], 'nav.main.home');
$before = file_get_contents($fresh . '/menus/main.yaml');
$m2->write('side', ['title' => 'Side', 'items' => []]);
$m2->ensureDefaults();
check('defaults do not overwrite what exists', file_get_contents($fresh . '/menus/main.yaml'), $before);
check('the list has the default menus and the custom one, sorted', $m2->keys(), ['footer', 'main', 'side']);
file_put_contents($fresh . '/menus/legacy.en.yaml', "title: x\n");
check('a file with a language suffix is not a menu', in_array('legacy', $m2->keys(), true) || in_array('legacy.en', $m2->keys(), true), false);
check('the admin list has a title for each', array_column($m2->listForAdmin(), 'title'), ['Footer Menu', 'Main Menu', 'Side']);

// ---- which menu is where, with labels and the active item
$m->write('main', ['title' => 'Main', 'items' => [
    ['label_key' => 'nav.main.home', 'url' => ''],
    ['label_key' => 'nav.main.about', 'url' => 'about'],
    ['labels' => ['el' => 'Υπηρεσίες', 'en' => 'Services'], 'url' => 'services', 'children' => [['labels' => ['el' => 'Σχεδιασμός'], 'url' => 'services/design']]],
    ['label' => 'External', 'url' => 'https://other.test/services'],
    ['label' => 'Mail', 'url' => 'mailto:a@b.test'],
    ['label' => 'Own domain', 'url' => 'https://site.test/en/about'],
]]);
$m->write('footer', ['title' => 'Footer', 'items' => [['label' => 'Terms', 'url' => 'terms']]]);
$menus = $m->forTheme('en', 'about');
check('every place has its menu', array_keys($menus), ['header', 'footer', 'sidebar']);
check('the header is the main menu, a place not set falls to its default', [count($menus['header']), count($menus['sidebar'])], [6, 0]);
check('a label with no text in the language comes from the theme strings', $menus['header'][1]['label'], 'About us');
check('a language label is used in that language', $menus['header'][2]['label'], 'Services');
$el = $m->forTheme('el', 'about');
check('and the other language has its own', $el['header'][2]['label'], 'Υπηρεσίες');
check('a child with no label in the language uses the default language', $m->forTheme('en', '')['header'][2]['children'][0]['label'], 'Σχεδιασμός');
check('the item for the page shown is active', [$menus['header'][1]['is_active'], $menus['header'][0]['is_active'], $menus['header'][2]['is_active']], [true, false, false]);
check('the home item is active only on the home page', [$m->forTheme('en', '')['header'][0]['is_active'], $m->forTheme('en', 'about')['header'][0]['is_active']], [true, false]);
$on = $m->forTheme('en', 'services/design')['header'][2];
check('a parent is on the trail, and active too, when a child is the page', [$on['is_active'], $on['is_trail'], $on['children'][0]['is_active']], [true, true, true]);
check('a page below an item keeps it active', $m->forTheme('en', 'about/team')['header'][1]['is_active'], true);
check('the language prefix in an address is ignored', [$m->forTheme('en', 'en/about')['header'][1]['is_active'], $menus['header'][5]['is_active']], [true, true]);
check('another site and a mail link are never active', [$menus['header'][3]['is_active'], $menus['header'][4]['is_active']], [false, false]);

// ---- addresses that change
$m->relink('about', 'about-us');
check('an address change is followed in every menu', [$m->load('main')['items'][1]['url'], $m->load('footer')['items'][0]['url']], ['about-us', 'terms']);
$m->relink('services/design', 'services/planning');
check('and in children', $m->load('main')['items'][2]['children'][0]['url'], 'services/planning');

exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($fresh));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
