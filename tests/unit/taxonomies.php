<?php
/*
 * Taxonomies: terms with addresses made from their names, the layout choices, and what the admin form keeps.
 *   php tests/unit/taxonomies.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use FarosCMS\{ContentTypes, Taxonomies};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$dir = sys_get_temp_dir() . '/tax' . getmypid(); mkdir($dir, 0775, true);
$t = new Taxonomies($dir, ['el', 'en']);
$t->ensureDefaults();
check('the two built-in taxonomies exist', $t->names(), ['categories', 'tags']);
check('kind of a taxonomy names its address', [Taxonomies::kind('categories'), Taxonomies::kind('tags'), Taxonomies::kind('anything')], ['category', 'tag', 'tag']);

$row = fn(string $id, string $slug, string $el, string $en = '') => ['id' => $id, 'slug' => $slug, 'labels' => ['el' => $el, 'en' => $en]];
$term = fn(array $p, string $id) => array_values(array_filter($p['terms'], fn($x) => $x['id'] === $id))[0] ?? null;

// ---- new terms get an address from their name
$p = $t->prepare('tags', [$row('news', 'news', 'Νέα', 'News'), $row('design', 'design', 'Σχεδιασμός', 'Design'), $row('', '', 'Ελληνική κουζίνα', 'Greek cuisine')], 'el');
check('a new term gets a Latin address made from its name', [$term($p, 'elliniki-kouzina')['slug'] ?? null], ['elliniki-kouzina']);
check('the address and the id are the same to begin with', array_column($p['terms'], 'id') === array_column($p['terms'], 'slug'), true);
check('new term reported as added', $p['added'], ['elliniki-kouzina']);
check('nothing moved, nothing removed', [$p['moved'], $p['removed']], [[], []]);

$p = $t->prepare('tags', [$row('', '', '', 'Only English name')], 'el');
check('a term with only another language name still gets an address', $p['terms'][0]['slug'], 'only-english-name');
$p = $t->prepare('tags', [$row('', '', 'Νέα'), $row('', '', 'Νέα')], 'el');
check('two new terms with the same name get different addresses', array_column($p['terms'], 'slug'), ['nea', 'nea-2']);
check('and different ids', array_column($p['terms'], 'id'), ['nea', 'nea-2']);
$p = $t->prepare('tags', [$row('news', 'news', 'Νέα'), $row('', '', 'News')], 'el');
check('a new term never takes the address of an existing one', array_column($p['terms'], 'slug'), ['news', 'news-2']);
$p = $t->prepare('tags', [$row('', 'Μεγάλη Ιδέα', 'x')], 'el');
check('a typed address is converted too', $p['terms'][0]['slug'], 'megali-idea');
$p = $t->prepare('tags', [$row('', '', ''), $row('', '  ', '')], 'el');
check('empty rows are ignored', $p['terms'], []);
$p = $t->prepare('tags', [$row('', '', '日本語')], 'el');
check('a name that cannot be converted still gets a usable address', $p['terms'][0]['slug'], 'term');

// ---- existing terms
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']], ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']]]);
$p = $t->prepare('tags', [$row('news', 'nea', 'Νέα'), $row('design', '', 'Σχεδιασμός')], 'el');
check('changing an address is reported with both', $p['moved'], [['id' => 'news', 'from' => 'news', 'to' => 'nea']]);
check('the id stays when the address changes', $term($p, 'news')['id'], 'news');
check('an empty address field keeps the address', $term($p, 'design')['slug'], 'design');
$p = $t->prepare('tags', [$row('news', 'Νέα & Άρθρα', 'Νέα')], 'el');
check('a changed address typed in Greek is converted', $p['moved'][0]['to'], 'nea-arthra');
$p = $t->prepare('tags', [$row('news', 'news', 'Νέα')], 'el');
check('removing a term is reported', $p['removed'], ['design']);
$p = $t->prepare('tags', [$row('news', 'design', 'Νέα'), $row('design', 'news', 'Σχεδιασμός')], 'el');
check('swapping two addresses sends nobody away from either', $p['moved'], []);
$p = $t->prepare('tags', [$row('news', 'shared', 'A'), $row('design', 'shared', 'B')], 'el');
check('two terms cannot share an address', array_column($p['terms'], 'slug'), ['shared', 'shared-2']);
$p = $t->prepare('tags', [$row('news', 'news', 'A'), $row('news', 'news', 'again')], 'el');
check('the same term submitted twice is kept once', count($p['terms']), 1);

// ---- lookups
check('find by address', $t->findBySlug('tags', 'news')['id'] ?? null, 'news');
check('unknown address', $t->findBySlug('tags', 'nope'), null);
check('label in a language', $t->label('tags', 'news', 'en'), 'News');
check('label falls back to another language', (function () use ($t) { $t->save('tags', 'Tags', [['id' => 'x', 'slug' => 'x', 'labels' => ['el' => 'Ίξ', 'en' => '']]]); return $t->label('tags', 'x', 'en'); })(), 'Ίξ');
check('label of an unknown term is made from its id', $t->label('tags', 'some-thing', 'en'), 'Some Thing');
check('address of a term', $t->slug('tags', 'x'), 'x');

// ---- layout choices
$defaults = ContentTypes::resolveArchive([], fn($v) => $v);
check('defaults match a content type', [$defaults['layout'], $defaults['columns'], $defaults['per_page'], $defaults['order']], ['cards', '3', 12, 'date_desc']);
check('a taxonomy without choices follows the defaults', $t->archive('tags', 'el', 'el')['layout'], 'cards');
$saved = ContentTypes::archiveFromInput(['layout' => 'list', 'columns' => '3', 'per_page' => '5', 'order' => 'title_asc', 'show_image' => '1', 'show_excerpt' => '1', 'show_date' => '1', 'show_meta' => '1', 'title' => 'Articles about {term}', 'taxonomies' => ['categories', 'nope']], [], $defaults, ['categories'], false);
check('only what differs from the defaults is stored', $saved, ['layout' => 'list', 'order' => 'title_asc', 'per_page' => 5, 'taxonomies' => ['categories'], 'title' => 'Articles about {term}']);
check('an order by a field is refused where there are no fields', ContentTypes::archiveFromInput(['order' => 'field:year:asc'], [], $defaults, [], false)['order'] ?? 'default', 'default');
check('and accepted for a content type', ContentTypes::archiveFromInput(['order' => 'field:year:asc'], [], $defaults, [], true)['order'] ?? '', 'field:year:asc');
check('keys the form does not know are kept', ContentTypes::archiveFromInput(['layout' => 'cards'], ['custom_key' => 'kept'], $defaults, [], false)['custom_key'] ?? '', 'kept');
check('a nonsense layout falls back', ContentTypes::archiveFromInput(['layout' => 'wild'], [], $defaults, [], false)['layout'] ?? 'default', 'default');
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']]], $saved + ['types' => ['posts', 'x y']]);
$a = $t->archive('tags', 'el', 'el');
check('choices survive saving and loading', [$a['layout'], $a['per_page'], $a['order'], $a['title'], $a['taxonomies']], ['list', 5, 'title_asc', 'Articles about {term}', ['categories']]);
check('content types listed are cleaned', $a['types'], ['posts']);
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']]]);
check('saving terms alone keeps the layout choices', $t->archive('tags', 'el', 'el')['layout'], 'list');
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']]], []);
check('saving an empty choice list resets to the defaults', $t->archive('tags', 'el', 'el')['layout'], 'cards');
check('a broken file does not crash', (function () use ($dir, $t) { file_put_contents($dir . '/taxonomies/broken.yaml', "title: [unclosed\n"); $t->forget(); return $t->load('broken')['terms']; })(), []);

// ---- descriptions
$t->save('tags', 'Tags', [['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News'], 'descriptions' => ['el' => "Τα νέα μας\r\nκάθε μέρα ", 'en' => '']], ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']]]);
check('a description is kept per language, trimmed', $t->load('tags')['terms'][0]['descriptions'], ['el' => "Τα νέα μας\nκάθε μέρα", 'en' => '']);
check('a term without one has empty texts', $t->load('tags')['terms'][1]['descriptions'], ['el' => '', 'en' => '']);
$yaml = file_get_contents($dir . '/taxonomies/tags.yaml');
check('the file has a description only where one is written', substr_count($yaml, 'descriptions:'), 1);
check('description in the language asked', $t->description('tags', 'news', 'el', 'el'), "Τα νέα μας\nκάθε μέρα");
check('another language falls back to the default language', $t->description('tags', 'news', 'en', 'el'), "Τα νέα μας\nκάθε μέρα");
check('no description gives nothing', [$t->description('tags', 'design', 'en', 'el'), $t->description('tags', 'ghost', 'en', 'el')], ['', '']);
$p = $t->prepare('tags', [$row('news', 'news', 'Νέα'), $row('design', 'design', 'Σχεδιασμός')], 'el');
check('rows sent without descriptions keep what the terms have', $p['terms'][0]['descriptions']['el'], "Τα νέα μας\nκάθε μέρα");
$p = $t->prepare('tags', [['descriptions' => ['el' => '', 'en' => 'Only EN']] + $row('news', 'news', 'Νέα'), $row('', '', 'Νέος')], 'el');
check('rows sent with descriptions replace them', $p['terms'][0]['descriptions'], ['el' => '', 'en' => 'Only EN']);
check('a new term starts with none', $p['terms'][1]['descriptions'], []);
$t->save('tags', 'Tags', $p['terms']);
check('a new term saved without texts has empty ones', $t->load('tags')['terms'][1]['descriptions'], ['el' => '', 'en' => '']);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail ? 1 : 0);
