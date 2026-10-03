<?php
/*
 * What the Taxonomies screen does with a submitted form: reading the rows, saving terms and layout, a moved address
 * leaving redirects and updating menu links, and counting the entries filed under each term.
 *   php tests/unit/taxonomy-editor.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use FarosCMS\{ContentRepository, Menus, RedirectRepository, SystemDatabase, Taxonomies, TaxonomyEditor};
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/taxed' . getmypid();
foreach (['pages', 'posts', 'projects', 'forms'] as $d) { mkdir("$dir/content/$d", 0775, true); }
mkdir("$dir/storage/db", 0775, true);
$settings = ['title' => 'Site', 'languages' => ['default' => 'el', 'available' => ['el', 'en']], 'home_page' => 'index'];
$environment = new Environment([]);
$environment->addExtension(new CommonMarkCoreExtension());
$content = new ContentRepository("$dir/content", new MarkdownConverter($environment), $settings);
$db = new SystemDatabase("$dir/storage");
$db->initialize();
$redirects = new RedirectRepository($db);
$store = new Taxonomies("$dir/content", ['el', 'en']);
$store->ensureDefaults();
$store->save('tags', 'Tags', [
    ['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']],
    ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']],
    ['id' => 'unused', 'slug' => 'unused', 'labels' => ['el' => 'Άχρηστο', 'en' => 'Unused']],
]);
$menus = new Menus("$dir/content", fn() => $settings, fn(string $k, ?string $f = null) => $f ?? $k, fn() => []);
$menus->write('main', ['title' => 'Main', 'items' => [['label' => 'News', 'url' => 'tag/news'], ['label' => 'Home', 'url' => 'index']]]);
$editor = new TaxonomyEditor($store, $redirects, $content, fn() => $menus);

file_put_contents("$dir/content/posts/one.md", "---\ntitle: One\nstatus: published\ntags: [news, design]\ncategories: [insights]\n---\nBody\n");
file_put_contents("$dir/content/posts/one.en.md", "---\ntitle: One EN\nstatus: draft\ntags: [news]\n---\nBody\n");
file_put_contents("$dir/content/projects/p.md", "---\ntitle: P\nstatus: published\ntags: news\n---\nBody\n");
file_put_contents("$dir/content/pages/about.md", "---\ntitle: About\nstatus: published\ntags: [news]\n---\nBody\n");
file_put_contents("$dir/content/forms/contact.md", "---\ntitle: Contact\ntags: [news]\n---\nBody\n");

// ---- counting
$u = $editor->usage(['tags', 'categories']);
check('every language and draft is counted, forms are not', $u['tags'], ['news' => 4, 'design' => 1]);
check('one pass covers every taxonomy', $u['categories'], ['insights' => 1]);
$by = $editor->usage(['tags'], true)['tags'];
check('and can be split by content type', $by['news'], ['pages' => 1, 'posts' => 2, 'projects' => 1]);
check('types that can be listed leave out pages and forms', $editor->listableTypes(), ['posts', 'projects']);

// ---- reading the form
$rows = $editor->rowsFromPost(['term_id' => ['news', ''], 'term_slug' => ['news', ''], 'term_label' => ['el' => ['Νέα', 'Νέο'], 'en' => ['News', ' ']], 'term_description' => ['el' => ['Περιγραφή', ''], 'en' => ['', '']]], ['el', 'en']);
check('a row per term, in order, with trimmed labels', [$rows[0]['id'], $rows[1]['id'], $rows[1]['labels']], ['news', '', ['el' => 'Νέο', 'en' => '']]);
check('descriptions are read per language', [$rows[0]['descriptions'], $rows[1]['descriptions']], [['el' => 'Περιγραφή', 'en' => ''], ['el' => '', 'en' => '']]);
$rows = $editor->rowsFromPost(['term_id' => ['news'], 'term_slug' => ['news'], 'term_label' => ['el' => ['Νέα'], 'en' => ['News']]], ['el', 'en']);
check('no description boxes at all means leave them alone', $rows[0]['descriptions'], null);
check('junk in the form is harmless', $editor->rowsFromPost(['term_id' => 'x', 'term_label' => 'y'], ['el']), []);

// ---- saving
$post = [
    'taxonomy_title' => 'Topics',
    'term_id' => ['news', 'design', ''], 'term_slug' => ['nea', 'design', ''],
    'term_label' => ['el' => ['Νέα', 'Σχεδιασμός', 'Νέος όρος'], 'en' => ['News', 'Design', '']],
    'term_description' => ['el' => ['Όλα τα νέα', '', ''], 'en' => ['', '', '']],
    'archive' => ['layout' => 'list', 'per_page' => '5', 'types' => ['posts']],
];
$r = $editor->apply('tags', $post, ['el', 'en'], 'el', 'tester');
check('the summary counts what happened', $r, ['title' => 'Topics', 'terms' => 3, 'moved' => 1, 'removed' => 1, 'added' => 1, 'orphaned' => 0]);
$saved = $store->load('tags');
check('title and terms are stored, in the order sent', [$saved['title'], array_column($saved['terms'], 'id')], ['Topics', ['news', 'design', 'neos-oros']]);
check('the description is stored', $saved['terms'][0]['descriptions']['el'], 'Όλα τα νέα');
check('the layout is stored, only what differs from the defaults', [$saved['archive']['layout'], $saved['archive']['types']], ['list', ['posts']]);
$rd = [];
foreach ($redirects->all() as $row) { $rd[$row['source']] = $row['target']; }
check('a moved address leaves a redirect in each language', [$rd['tag/news'] ?? null, $rd['en/tag/news'] ?? null], ['/tag/nea', '/en/tag/nea']);
check('the menu link follows', $menus->load('main')['items'][0]['url'], 'tag/nea');

// ---- removing a term that entries use reports them
$r = $editor->apply('tags', ['taxonomy_title' => 'Topics', 'term_id' => ['design'], 'term_slug' => ['design'], 'term_label' => ['el' => ['Σχεδιασμός'], 'en' => ['Design']], 'archive' => ['types' => ['posts', 'projects']]], ['el', 'en'], 'el', 'tester');
check('removed terms and the entries still filed under them', [$r['removed'], $r['orphaned']], [2, 4]);
check('choosing every type stores no type list', isset($store->load('tags')['archive']['types']), false);
$editor->apply('tags', ['taxonomy_title' => 'Topics', 'term_id' => ['design'], 'term_slug' => ['design'], 'term_label' => ['el' => ['Σχεδιασμός'], 'en' => ['Design']], 'archive' => ['layout' => 'magazine', 'per_page' => '12']], ['el', 'en'], 'el', 'tester');
$editor->apply('tags', ['taxonomy_title' => 'Topics', 'term_id' => ['design'], 'term_slug' => ['design'], 'term_label' => ['el' => ['Σχεδιασμός'], 'en' => ['Design']]], ['el', 'en'], 'el', 'tester');
check('a form without the layout part (it is edited in Theme > Archive Layouts) leaves it alone', $store->load('tags')['archive']['layout'] ?? null, 'magazine');
check('a form without a title keeps the title', $editor->apply('tags', ['term_id' => ['design'], 'term_slug' => ['design'], 'term_label' => ['el' => ['Σχεδιασμός'], 'en' => ['Design']]], ['el', 'en'], 'el', 't')['title'], 'Topics');

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
