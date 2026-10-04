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
// ---- the kind of each block (the picker's filter)
$kinds = array_column($registry->editorDefinitions(), 'category', 'type');
check('every block the theme ships says what kind it is', array_keys(array_filter($kinds, fn($k) => $k === '')), []);
check('the kinds are the ones the picker orders', array_values(array_unique(array_filter($kinds, fn($k) => $k !== ''))) == ['Openers', 'Content', 'Media', 'Showcase', 'Convert'] || array_diff(array_unique($kinds), ['Openers', 'Content', 'Media', 'Showcase', 'Convert']) === [], true);
check('some examples', [$kinds['hero'], $kinds['text'], $kinds['gallery'], $kinds['team'], $kinds['pricing']], ['Openers', 'Content', 'Media', 'Showcase', 'Convert']);
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

// ---- the blocks of the second family: image, divider, quote, downloads, checklist, portfolio, table, marquee
foreach (['image', 'divider', 'quote', 'downloads', 'checklist', 'portfolio', 'table', 'marquee'] as $t) check("$t registered", $registry->get($t) !== null, true);
check('image variants and shape default', [array_keys($registry->get('image')['variants']), $registry->get('image')['fields']['ratio']['default']], [['wide', 'narrow', 'full'], 'original']);
check('divider variants', array_keys($registry->get('divider')['variants']), ['space', 'line', 'label']);
check('quote variants', array_keys($registry->get('quote')['variants']), ['centered', 'bar', 'photo']);
check('downloads variants, and a field that is a file', [array_keys($registry->get('downloads')['variants']), $registry->get('downloads')['fields']['items']['fields']['file']['type']], [['list', 'cards'], 'file']);
check('a file is kept as a safe address, empty or not', [FieldSchema::clean($registry->get('downloads')['fields']['items']['fields']['file'], ' /uploads/media/a.pdf '), FieldSchema::clean($registry->get('downloads')['fields']['items']['fields']['file'], 'javascript:alert(1)'), FieldSchema::clean($registry->get('downloads')['fields']['items']['fields']['file'], 'https://x.test/a b.pdf')], ['/uploads/media/a.pdf', '', '']);
check('checklist variants, and its mark', [array_keys($registry->get('checklist')['variants']), $registry->get('checklist')['fields']['icon']['default']], [['plain', 'cards', 'split'], 'check']);
check('portfolio variants, and its source takes the content types', [array_keys($registry->get('portfolio')['variants']), array_keys($registry->get('portfolio')['fields']['source']['options'])], [['grid', 'overlay'], ['manual', 'posts', 'projects']]);
check('portfolio has at most 24 entries from a content type', $registry->get('portfolio')['fields']['limit']['max'], 24);
check('table variants', array_keys($registry->get('table')['variants']), ['lines', 'striped', 'boxed']);
check('marquee variants, speed and direction', [array_keys($registry->get('marquee')['variants']), $registry->get('marquee')['fields']['speed']['default'], $registry->get('marquee')['fields']['direction']['default']], [['text', 'logos'], 'normal', 'left']);
check('pricing has a switch that is off, and a yearly price for each plan', [$registry->get('pricing')['fields']['billing_switch']['default'], isset($registry->get('pricing')['fields']['items']['fields']['yearly_price'])], [false, true]);
check('a field that matters for a layout is shown by the layout', [$registry->get('divider')['fields']['label']['when'], $registry->get('downloads')['fields']['columns']['when']], [['variant' => ['label']], ['variant' => ['cards']]]);
check('text has a layout with a contents list', array_keys($registry->get('text')['variants']), ['default', 'split', 'lead', 'contents']);
check('their kinds', [$kinds['image'], $kinds['divider'], $kinds['downloads'], $kinds['portfolio'], $kinds['marquee']], ['Media', 'Content', 'Content', 'Showcase', 'Showcase']);

// A table: one row to a line, cells divided by | or a tab; escaped; a few marks only.
$t = BlockRenderer::tableData("Plan | Users | Price", "Starter | 3 | €9\nTeam | 20 | €29\n");
check('table: header, rows, and a column of figures aligned to the right', [$t['header'], $t['body'], $t['align'], $t['columns']], [['Plan', 'Users', 'Price'], [['Starter', '3', '€9'], ['Team', '20', '€29']], ['left', 'right', 'right'], 3]);
$t = BlockRenderer::tableData('', "| a | b |\n|---|:--:|\n| c | d |\n");
check('table: the rule of a Markdown table is skipped, and the outer bars with it', [$t['header'], $t['body']], [[], [['a', 'b'], ['c', 'd']]]);
$t = BlockRenderer::tableData('A | B', "x\ty\tz\n1");
check('table: tabs divide cells, short rows are filled, the header grows to the widest row', [$t['columns'], $t['header'], $t['body']], [3, ['A', 'B', ''], [['x', 'y', 'z'], ['1', '', '']]]);
$t = BlockRenderer::tableData('', "<b>x</b> | **bold** | [go](/contact) | [bad](javascript:alert(1)) | a & b");
check('table: text is escaped, bold and safe links are the only marks', $t['body'][0], ['&lt;b&gt;x&lt;/b&gt;', '<strong>bold</strong>', '<a href="/contact">go</a>', '[bad](javascript:alert(1))', 'a &amp; b']);
$t = BlockRenderer::tableData('', "a | 10\nb | 20.5\nc | text", true);
check('table: a column is aligned only when every cell is a figure', $t['align'], ['left', 'left']);
$t = BlockRenderer::tableData('', "a | €1.200\nb | 15%\nc | −3", true);
check('table: money, percentages and signs are figures', $t['align'], ['left', 'right']);
check('table: alignment can be switched off', BlockRenderer::tableData('', "a | 1", false)['align'], ['left', 'left']);
$t = BlockRenderer::tableData('', implode("\n", array_fill(0, 150, 'a | b')));
check('table: at most 100 rows', count($t['body']), 100);
$t = BlockRenderer::tableData(implode('|', range(1, 20)), '');
check('table: at most 12 columns', [$t['columns'], count($t['header'])], [12, 12]);
check('table: nothing typed is an empty table', BlockRenderer::tableData('', '')['body'], []);

// The page layouts: every block is one the theme has, in both languages, and no value is lost when the editor stores it.
$formStub = ['content_types' => fn() => ['posts' => 'Posts', 'projects' => 'Projects'], 'forms' => fn() => ['' => '—', 'contact' => 'Contact']];
$withForms = new BlockRegistry($theme, $formStub);
$layouts = glob($root . '/themes/default/presets/page-*.yaml');
check('at least 18 page layouts', count($layouts) >= 18, true);
foreach ($layouts as $file) {
    $preset = Yaml::parseFile($file);
    $name = basename($file, '.yaml');
    check("$name: kind, a label and a description in both languages", [$preset['kind'], array_keys($preset['label']), array_keys($preset['description'])], ['page', ['el', 'en'], ['el', 'en']]);
    check("$name: the same blocks in both languages", array_column($preset['blocks']['el'], 'type'), array_column($preset['blocks']['en'], 'type'));
    foreach (['el', 'en'] as $lang) {
        $stored = $withForms->sanitizeForStorage($preset['blocks'][$lang]);
        $lost = [];
        foreach ($preset['blocks'][$lang] as $i => $block) {
            $definition = $withForms->get($block['type']);
            if ($definition === null) { $lost[] = $block['type'] . ' unknown'; continue; }
            foreach ($block as $key => $value) {
                $field = $definition['fields'][$key] ?? $definition['common'][$key] ?? null;
                if ($key !== 'type' && (!isset($stored[$i]) || (!array_key_exists($key, $stored[$i]) && ($field === null || $field['default'] !== $value)))) {
                    $lost[] = $block['type'] . '.' . $key;
                }
            }
        }
        check("$name ($lang): every block and value is kept when stored", $lost, []);
    }
}

// Round trip of the showcase page
$showcase = $root . '/tests/fixtures/content/pages/blocks.md';
if (!is_file($showcase)) { echo "skip showcase round trip (no fixture)\n"; // ---- a video behind the hero
$fields = $registry->get('hero')['fields'];
check('hero has a choice of picture and a video with its still image', [isset($fields['background']), $fields['video']['type'], $fields['video_poster']['type'], array_keys($fields['background']['options'])], [true, 'video', 'image', ['image', 'video']]);
check('and they show by "when" of the picture', [$fields['image']['when'], $fields['video']['when'], $fields['video_poster']['when']], [['background' => ['image']], ['background' => ['video']], ['background' => ['video']]]);
check('a hero without the choice is an image hero', FieldSchema::defaults($fields)['background'], 'image');
check('a video field is kept as a safe address, empty or not', [FieldSchema::clean($fields['video'], ' /uploads/media/a.mp4 '), FieldSchema::clean($fields['video'], 'javascript:alert(1)'), FieldSchema::clean($fields['video'], 'https://x.test/a b.mp4')], ['/uploads/media/a.mp4', '', '']);
check('a field says when it matters: a map of other fields to values, tidied', FieldSchema::normalize(['a' => ['type' => 'text', 'when' => ['b' => 'x', 'c' => ['y', 'z'], 'Bad Key' => 'q', 'd' => '']]])['a']['when'], ['b' => ['x'], 'c' => ['y', 'z']]);
check('and has none by default', isset(FieldSchema::normalize(['a' => ['type' => 'text']])['a']['when']), false);
check('a file behind the hero', BlockRenderer::backgroundVideo('/uploads/media/loop.mp4'), ['provider' => 'file', 'src' => '/uploads/media/loop.mp4', 'type' => 'video/mp4']);
check('on another site, other types', [BlockRenderer::backgroundVideo('https://cdn.test/a.webm?x=1')['type'], BlockRenderer::backgroundVideo('https://cdn.test/a.m4v')['type'], BlockRenderer::backgroundVideo('https://cdn.test/a.ogv')['type']], ['video/webm', 'video/mp4', 'video/ogg']);
check('YouTube in the privacy host, silent, no controls, in a loop', BlockRenderer::backgroundVideo('https://youtu.be/dQw4w9WgXcQ'), ['provider' => 'youtube', 'type' => '', 'src' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&mute=1&controls=0&loop=1&playlist=dQw4w9WgXcQ&playsinline=1&rel=0&disablekb=1&modestbranding=1&iv_load_policy=3']);
check('a YouTube link of any kind', array_map(fn($u) => BlockRenderer::backgroundVideo($u)['provider'] ?? null, ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://m.youtube.com/embed/dQw4w9WgXcQ']), ['youtube', 'youtube', 'youtube']);
check('Vimeo in background mode', str_contains(BlockRenderer::backgroundVideo('https://vimeo.com/76979871')['src'], 'player.vimeo.com/video/76979871?dnt=1&background=1&autoplay=1&loop=1&muted=1'), true);
check('what is not a video, or not safe, is refused', [BlockRenderer::backgroundVideo(''), BlockRenderer::backgroundVideo('https://example.test/page'), BlockRenderer::backgroundVideo('javascript:alert(1)'), BlockRenderer::backgroundVideo('https://evil.test/embed/dQw4w9WgXcQ'), BlockRenderer::backgroundVideo('https://www.youtube.com/watch?v=short')], [null, null, null, null, null]);
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n"; exit($fail ? 1 : 0); }
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
