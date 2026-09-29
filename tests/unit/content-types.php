<?php
/*
 * Content types: definitions, merging with the site's file, values, and archive ordering.
 *   php tests/unit/content-types.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use FarosCMS\{Theme, ContentTypes, FieldSchema};
// A throwaway site whose theme is the real one, so the test can write custom/ files.
$root = sys_get_temp_dir() . '/faros-ct-' . getmypid();
mkdir($root . '/custom', 0775, true);
symlink($repo . '/themes', $root . '/themes');
register_shutdown_function(function () use ($root) { @exec('rm -rf ' . escapeshellarg($root)); });
$fail = 0;
function check(string $label, $actual, $expected) { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . var_export($actual, true) . ' expected ' . var_export($expected, true)) . "\n"; }
@unlink($root . '/custom/content-types/projects.yaml'); @rmdir($root . '/custom/content-types');

$ct = new ContentTypes(new Theme($root, 'default'));
check('theme defines projects and posts', array_values(array_intersect($ct->defined(), ['projects', 'posts'])), ['posts', 'projects']);
$el = $ct->definition('projects', 'el', 'el');
$en = $ct->definition('projects', 'en', 'el');
check('label el', $el['label'], 'Έργα');
check('label en', $en['label'], 'Projects');
check('field label el', $el['fields']['client']['label'], 'Πελάτης');
check('select option label en', $en['fields']['sector']['options']['retail'], 'Retail');
check('select empty option', $en['fields']['sector']['options'][''], '—');
check('filterable only on select', [$en['fields']['sector']['filterable'], $en['fields']['client']['filterable']], [true, false]);
check('card flag', [$en['fields']['client']['card'], $en['fields']['location']['card']], [true, false]);
check('archive defaults', [$en['archive']['layout'], $en['archive']['per_page'], $en['archive']['order'], $en['archive']['taxonomies']], ['cards', 12, 'date_desc', ['categories']]);
$none = $ct->definition('events', 'en', 'en');
check('undefined type is usable', [$none['label'], $none['fields'], $none['archive']['layout'], $none['origin']], ['Events', [], 'cards', '']);

// Values and display
$meta = ['custom_fields' => ['client' => 'ACME', 'sector' => 'retail', 'year' => '2024', 'location' => '', 'duration' => 12, 'junk' => 'x']];
$values = $ct->values('projects', $meta, 'en', 'el');
check('values typed and pruned', $values, ['client' => 'ACME', 'sector' => 'retail', 'year' => 2024, 'duration' => '12']);
check('invalid select dropped', $ct->values('projects', ['custom_fields' => ['sector' => 'nope']], 'en', 'el'), []);
$show = $ct->display('projects', $meta, 'page', fn($v) => "D($v)", 'en', 'el');
check('display order', array_column($show, 'key'), ['client', 'sector', 'year', 'duration']);
check('display select label', $show[1]['display'], 'Retail');
check('card context', array_column($ct->display('projects', $meta, 'card', fn($v) => $v, 'en', 'el'), 'key'), ['client', 'sector']);

// fromInput
$stored = $ct->fromInput('projects', ['client' => '  Bob <b>&</b> ', 'sector' => 'evil', 'year' => 'abc', 'location' => 'Paris', 'duration' => ''], ['duration' => 'kept?'], 'en', 'el');
check('input: text kept raw, invalid select/number omitted', $stored, ['client' => 'Bob <b>&</b>', 'location' => 'Paris']);
$stored = $ct->fromInput('projects', ['year' => '2030', 'sector' => 'office'], [], 'en', 'el');
check('input: number is int, select kept', $stored, ['sector' => 'office', 'year' => 2030]);
check('input: number clamped to range', $ct->fromInput('projects', ['year' => '5'], [], 'en', 'el')['year'], 1990);

// Custom file merged
$ok = $ct->saveCustom('projects', [
    'archive' => ['layout' => 'magazine', 'per_page' => 500, 'order' => 'field:budget:desc', 'title' => 'Our work'],
    'fields' => [
        'duration' => ['hidden' => true],
        'client' => ['card' => false],
        'budget' => ['type' => 'decimal', 'label' => 'Budget'],
        'starts' => ['type' => 'date', 'label' => ['en' => 'Starts', 'el' => 'Έναρξη']],
        'tags_x' => ['type' => 'repeater', 'label' => 'Nope'],
    ],
]);
check('saveCustom writes', $ok, true);
$ct2 = new ContentTypes(new Theme($root, 'default'));
$m = $ct2->definition('projects', 'el', 'el');
check('origin merged', $m['origin'], 'theme+custom');
check('archive merged', [$m['archive']['layout'], $m['archive']['per_page'], $m['archive']['title'], $m['archive']['columns']], ['magazine', 60, 'Our work', '3']);
check('taxonomies kept from theme', $m['archive']['taxonomies'], ['categories']);
check('order by declared field kept', $m['archive']['order'], 'field:budget:desc');
check('theme field flag overridden', $m['fields']['client']['card'], false);
check('theme field retired', $m['fields']['duration']['hidden'], true);
check('retired field not editable', array_key_exists('duration', $ct2->editableFields('projects', 'el', 'el')), false);
check('new field type decimal', $m['fields']['budget']['type'], 'decimal');
check('per-language label from site file', $m['fields']['starts']['label'], 'Έναρξη');
check('repeater not supported', array_key_exists('tags_x', $m['fields']), false);
check('theme-only definition unaffected', $ct2->themeDefinition('projects', 'en', 'el')['archive']['layout'], 'cards');
check('retired value kept on save', $ct2->fromInput('projects', ['client' => 'A'], ['duration' => '10 weeks'], 'en', 'el'), ['client' => 'A', 'duration' => '10 weeks']);
check('date input valid', $ct2->fromInput('projects', ['starts' => '2026-02-30'], [], 'en', 'el'), []);
check('date input ok', $ct2->fromInput('projects', ['starts' => '2026-02-28'], [], 'en', 'el'), ['starts' => '2026-02-28']);
check('unquoted yaml date (timestamp) read', $ct2->values('projects', ['custom_fields' => ['starts' => 1772236800]], 'en', 'el'), ['starts' => '2026-02-28']);
check('order options include fields', array_key_exists('field:starts:asc', ContentTypes::orderOptions($m['fields'])) && !array_key_exists('field:client:asc', array_flip(array_keys(ContentTypes::orderOptions([])))), true);

// order pointing at a missing / unsortable field falls back
$ct2->saveCustom('projects', ['archive' => ['order' => 'field:nothing:asc']]);
check('unknown order field falls back', (new ContentTypes(new Theme($root, 'default')))->definition('projects')['archive']['order'], 'date_desc');
$ct2->saveCustom('projects', ['archive' => ['order' => 'field:sector:asc']]);
check('select field not sortable', (new ContentTypes(new Theme($root, 'default')))->definition('projects')['archive']['order'], 'date_desc');

// bad names, empty definition removes the file, broken YAML ignored
check('bad type name refused', $ct2->saveCustom('../etc', ['label' => 'x']), false);
check('empty definition removes file', [$ct2->saveCustom('projects', []), is_file($root . '/custom/content-types/projects.yaml')], [true, false]);
file_put_contents($root . '/custom/content-types/broken.yaml', "fields: [unclosed\n  - : :");
check('broken yaml ignored', (new ContentTypes(new Theme($root, 'default')))->has('broken'), false);
@unlink($root . '/custom/content-types/broken.yaml'); @rmdir($root . '/custom/content-types');

// date field in FieldSchema
$f = FieldSchema::normalize(['d' => ['type' => 'date']])['d'];
check('date valid', FieldSchema::clean($f, '2026-12-31'), '2026-12-31');
check('date invalid', FieldSchema::clean($f, '31/12/2026'), '');
check('date empty', FieldSchema::clean($f, ''), '');
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
