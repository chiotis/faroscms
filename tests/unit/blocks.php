<?php
/*
 * Blocks: registry, defaults, tampered values, video links, and storage sanitising.
 *   php tests/unit/blocks.php
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
use FarosCMS\{Theme, BlockRegistry, BlockRenderer, FieldSchema};
use Symfony\Component\Yaml\Yaml;
$fail = 0;
function check(string $label, $actual, $expected) { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . var_export($actual, true) . ' expected ' . var_export($expected, true)) . "\n"; }

$theme = new Theme($root, 'default');
$registry = new BlockRegistry($theme, ['content_types' => fn() => ['posts' => 'Posts', 'projects' => 'Projects'], 'forms' => fn() => ['' => '—']]);
check('at least the 23 shipped blocks', count($registry->all()) >= 23, true);
foreach (['banner', 'latest', 'pricing', 'slider', 'tabs', 'video'] as $t) check("$t registered", $registry->get($t) !== null, true);
check('latest variants', array_keys($registry->get('latest')['variants']), ['cards', 'list', 'compact', 'overlay', 'strip', 'featured', 'magazine', 'editorial']);
check('latest source options', array_keys($registry->get('latest')['fields']['source']['options']), ['posts', 'projects']);
check('latest source default', $registry->get('latest')['fields']['source']['default'], 'posts');
check('video default play', $registry->get('video')['fields']['play']['default'], 'lightbox');
check('slider autoplay default off', $registry->get('slider')['fields']['autoplay']['default'], 'off');
check('banner default tone', $registry->get('banner')['common']['tone']['default'], 'accent');
check('banner icon default', $registry->get('banner')['fields']['icon']['default'], 'megaphone');
check('at least the 34 shipped icons', count($theme->iconNames()) >= 34, true);
foreach (['play', 'pause', 'chevron-left', 'chevron-right', 'info', 'megaphone', 'x'] as $i) check("icon $i", $theme->icon($i) !== '', true);

// Tampered values
$r = FieldSchema::resolve($registry->get('slider')['fields'], ['autoplay' => '1', 'per_view' => '9']);
check('slider bad autoplay -> off', $r['autoplay'], 'off');
check('slider bad per_view -> 3', $r['per_view'], '3');
$r = FieldSchema::resolve($registry->get('latest')['fields'], ['limit' => 99, 'source' => 'evil', 'term' => "a\nb", 'show_image' => 'no']);
check('latest limit capped', $r['limit'], 12);
check('latest source invalid -> posts', $r['source'], 'posts');
check('latest term single line', $r['term'], 'a b');
$r = FieldSchema::resolve($registry->get('video')['fields'], ['videos' => [['url' => 'javascript:alert(1)', 'poster' => 'javascript:x'], ['url' => 'https://youtu.be/dQw4w9WgXcQ']]]);
check('video unsafe url cleared', $r['videos'][0]['url'], '');
check('video unsafe poster cleared', $r['videos'][0]['poster'], '');
check('video info rejects empty', BlockRenderer::videoInfo($r['videos'][0]['url']), null);
check('video info youtu.be', BlockRenderer::videoInfo($r['videos'][1]['url'])['provider'], 'youtube');
check('video lookalike host rejected', BlockRenderer::videoInfo('https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ'), null);
check('video vimeo hash', str_contains(BlockRenderer::videoInfo('https://vimeo.com/76979871/abcdef123456')['embed_url'], '&h=abcdef123456'), true);
check('video file relative', BlockRenderer::videoInfo('/uploads/media/a.mp4')['provider'], 'file');
check('video non-video file', BlockRenderer::videoInfo('/uploads/media/a.docx'), null);

// Round trip of the showcase page
$showcase = $root . '/tests/fixtures/content/pages/blocks.md';
if (!is_file($showcase)) { echo "skip showcase round trip (no fixture)\n"; echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n"; exit($fail ? 1 : 0); }
$md = file_get_contents($showcase);
preg_match('/^---\n(.*?)\n---/s', $md, $m);
$blocks = Yaml::parse($m[1])['blocks'];
$once = $registry->sanitizeForStorage($blocks);
check('showcase block count kept', count($once), count($blocks));
check('sanitize idempotent', $registry->sanitizeForStorage($once), $once);
$video = array_values(array_filter($once, fn($b) => $b['type'] === 'video'))[0];
check('video url kept', $video['videos'][0]['url'], 'https://www.youtube.com/watch?v=aqz-KE-bpKQ');
$pricing = array_values(array_filter($once, fn($b) => $b['type'] === 'pricing'))[1];
check('pricing flow-map commas kept', $pricing['items'][2]['description'], 'Σχέδια, υλικά και προϋπολογισμός.');
$lat = array_values(array_filter($once, fn($b) => $b['type'] === 'latest'));
check('latest default variant omitted from storage', array_key_exists('variant', $lat[0]), false);
check('latest posts source omitted', array_key_exists('source', $lat[0]), false);
$edit = array_map(fn($d) => $d['type'], $registry->editorDefinitions());
check('editor gets new blocks', count(array_intersect($edit, ['banner', 'latest', 'pricing', 'slider', 'tabs', 'video'])), 6);
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
