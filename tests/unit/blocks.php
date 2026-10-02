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
check('at least the 25 shipped blocks', count($registry->all()) >= 25, true);
foreach (['banner', 'latest', 'pricing', 'slider', 'tabs', 'video', 'compare', 'before-after'] as $t) check("$t registered", $registry->get($t) !== null, true);
check('latest variants', array_keys($registry->get('latest')['variants']), ['cards', 'list', 'compact', 'overlay', 'strip', 'featured', 'magazine', 'editorial']);
check('latest source options', array_keys($registry->get('latest')['fields']['source']['options']), ['posts', 'projects']);
check('latest source default', $registry->get('latest')['fields']['source']['default'], 'posts');
check('video default play', $registry->get('video')['fields']['play']['default'], 'lightbox');
// ---- the wireframe each block shows in the picker (preview.svg)
$withoutPreview = array_filter($registry->editorDefinitions(), fn($d) => $d['origin'] === 'theme' && ($d['preview'] ?? '') === '');
check('every block the theme ships has a wireframe', array_column($withoutPreview, 'type'), []);
$svg = static fn(string $inner): string => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 48">' . $inner . '</svg>';
check('plain shapes are kept', BlockRegistry::previewMarkup($svg('<rect x="1" y="2" width="3" height="4" rx="1" fill="currentColor" fill-opacity=".1"/><path d="M1 1h4"/>')), '<rect x="1" y="2" width="3" height="4" rx="1" fill="currentColor" fill-opacity=".1"/><path d="M1 1h4"/>');
check('a group keeps its shapes', BlockRegistry::previewMarkup($svg('<g stroke-width="3"><path d="M0 0h2"/></g>')), '<g stroke-width="3"><path d="M0 0h2"/></g>');
check('script, links and images never get in', BlockRegistry::previewMarkup($svg('<script>alert(1)</script><a href="x"><path d="M0 0"/></a><image href="x.png"/><foreignObject><div/></foreignObject><path d="M1 1"/>')), '<path d="M1 1"/>');
check('event handlers and styles are dropped from a shape', BlockRegistry::previewMarkup($svg('<path d="M1 1" onload="x()" style="fill:red" class="a" id="b"/>')), '<path d="M1 1"/>');
check('a colour other than the current one is dropped', BlockRegistry::previewMarkup($svg('<path d="M1 1" fill="red" stroke="url(#x)"/><path d="M2 2" fill="none" stroke="currentColor"/>')), '<path d="M1 1"/><path d="M2 2" fill="none" stroke="currentColor"/>');
check('a value with markup or a script address is dropped', BlockRegistry::previewMarkup($svg('<path d="M1 1" transform="javascript:x"/><rect width="&lt;b&gt;"/>')), '<path d="M1 1"/><rect/>');
check('not an svg, broken, empty or too large gives nothing', [BlockRegistry::previewMarkup('<html/>'), BlockRegistry::previewMarkup('<svg><path'), BlockRegistry::previewMarkup(''), BlockRegistry::previewMarkup($svg(str_repeat('<path d="M0 0"/>', 600)))], ['', '', '', '']);
check('a file that points at another file is not read', BlockRegistry::previewMarkup('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/hostname">]><svg xmlns="http://www.w3.org/2000/svg"><path d="M1 1" stroke-width="&x;"/></svg>'), '');
check('the editor gets the wireframe with the definition', str_contains((string)array_column($registry->editorDefinitions(), 'preview', 'type')['hero'], '<rect'), true);

check('slider is contained with its arrows below by default', [$registry->get('slider')['fields']['width']['default'], $registry->get('slider')['fields']['navigation']['default']], ['contained', 'below']);
check('slider width and arrows only take their own values', [FieldSchema::resolve($registry->get('slider')['fields'], ['width' => 'huge', 'navigation' => 'left'])['width'], FieldSchema::resolve($registry->get('slider')['fields'], ['width' => 'full', 'navigation' => 'inside'])['navigation']], ['contained', 'inside']);
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
$compare = array_values(array_filter($once, fn($b) => $b['type'] === 'compare'))[0];
check('compare keeps its columns and rows', [count($compare['columns']), count($compare['rows']), $compare['rows'][1]['v1']], [3, 6, 'ναι']);
check('compare has at most four columns', $registry->get('compare')['fields']['columns']['max'], 4);
$tooMany = $registry->sanitizeForStorage([['type' => 'compare', 'columns' => array_map(fn($n) => ['name' => "c$n"], range(1, 9)), 'rows' => [['label' => 'x', 'v1' => 'y']]]]);
check('compare drops columns beyond four', count($tooMany[0]['columns']), 4);
$ba = array_values(array_filter($once, fn($b) => $b['type'] === 'before-after'));
check('before and after keeps both images', [$ba[0]['before'], $ba[0]['after']], ['/uploads/media/faros-demo-rocks.jpg', '/uploads/media/faros-demo-lake.jpg']);
check('before and after variants', array_keys($registry->get('before-after')['variants']), ['slider', 'side']);
check('before and after shape falls back', FieldSchema::resolve($registry->get('before-after')['fields'], ['image_ratio' => 'huge'])['image_ratio'], 'landscape');
check('hero has a steps layout', array_keys($registry->get('hero')['variants']), ['split', 'centered', 'cover', 'steps', 'minimal']);
$heroSteps = array_values(array_filter($once, fn($b) => $b['type'] === 'hero' && ($b['variant'] ?? '') === 'steps'))[0];
check('hero steps keep their steps', [count($heroSteps['items']), $heroSteps['items'][0]['title']], [4, 'Ανάλυση']);
check('hero has at most four steps', $registry->get('hero')['fields']['items']['max'], 4);
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
