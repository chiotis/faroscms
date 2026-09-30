<?php
/*
 * Form fields: the kinds that exist, how a stored field is read back (for the form on the site and for the editor),
 * how options are written, and how what the editor sends is cleaned before it is stored.
 *   php tests/unit/form-fields.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use FarosCMS\FormFields;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

check('sixteen kinds of field', count(FormFields::types()), 16);
check('a name is an address-safe word', [FormFields::sanitizeName(' Your Name '), FormFields::sanitizeName('e-mail'), FormFields::sanitizeName('  ')], ['your-name', 'e-mail', '']);
check('options are split on lines and commas', FormFields::parseOptions("a, b\nc,, d"), ['a', 'b', 'c', 'd']);

// ---- reading a stored field for the site
$fields = FormFields::normalize([
    ['name' => 'Full Name', 'type' => 'text', 'required' => 'yes'],
    ['name' => 'kind', 'type' => 'select', 'label' => 'Kind', 'options' => ['a|Alpha', 'b:Beta', 'plain', '']],
    ['name' => 'note', 'type' => 'nonsense', 'rows' => '6', 'default' => 'hi', 'help' => 'H', 'placeholder' => 'P', 'min' => '1', 'max' => '9', 'step' => '2'],
    ['name' => '', 'type' => 'text'],
    'not a field',
]);
check('rows without a name and non-rows are skipped', count($fields), 3);
check('the name is made safe and the label comes from it', [$fields[0]['name'], $fields[0]['label']], ['full-name', 'Full Name']);
check('"yes" is required', $fields[0]['required'], true);
check('an unknown kind is a text field', $fields[2]['type'], 'text');
check('options have a value and a label ("|" or ":" split them)', $fields[1]['options'], [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta'], ['value' => 'plain', 'label' => 'plain']]);
check('the other settings are kept as text', [$fields[2]['rows'], $fields[2]['default'], $fields[2]['help'], $fields[2]['placeholder'], $fields[2]['min'], $fields[2]['max'], $fields[2]['step']], [6, 'hi', 'H', 'P', '1', '9', '2']);
check('anything but a list gives no fields', [FormFields::normalize('x'), FormFields::normalize(null)], [[], []]);
check('options can be typed as text', FormFields::normalizeOptions("x|One\ny, z"), [['value' => 'x', 'label' => 'One'], ['value' => 'y', 'label' => 'y'], ['value' => 'z', 'label' => 'z']]);
check('an option with an empty label uses its value', FormFields::normalizeOptions('v|'), [['value' => 'v', 'label' => 'v']]);

// ---- reading it for the editor
$admin = FormFields::forAdmin([
    ['name' => 'kind', 'type' => 'select', 'options' => ['a', 'b'], 'required' => true, 'rows' => 3],
    ['name' => 'other', 'options' => 'x, y'],
]);
check('the editor gets a row id and options as one text', [$admin[0]['id'], $admin[0]['options'], $admin[1]['id'], $admin[1]['options']], ['field_0', 'a, b', 'field_1', 'x, y']);
check('and numbers as text', [$admin[0]['rows'], $admin[0]['required']], ['3', true]);

// ---- what the editor sends
$stored = FormFields::parseInput([
    ['name' => 'Email', 'type' => 'email', 'label' => 'Your email', 'required' => '1', 'placeholder' => ' you@x.test '],
    ['name' => 'form_secret', 'type' => 'text'],
    ['name' => 'kind', 'type' => 'select', 'options' => "a\nb", 'default' => 'a'],
    ['name' => 'plain', 'type' => 'text', 'options' => 'ignored'],
    ['name' => 'msg', 'type' => 'textarea', 'rows' => '8'],
    ['name' => 'num', 'type' => 'number', 'min' => '1', 'max' => '', 'step' => '5'],
]);
check('a name starting with form_ is dropped (it would collide with the form itself)', array_column($stored, 'name'), ['email', 'kind', 'plain', 'msg', 'num']);
check('a stored field has only what it has', $stored[0], ['type' => 'email', 'name' => 'email', 'label' => 'Your email', 'required' => true, 'placeholder' => 'you@x.test']);
check('options are only kept for kinds that use them', [isset($stored[1]['options']), isset($stored[2]['options'])], [true, false]);
check('rows are only for text areas', [$stored[3]['rows'] ?? null, isset($stored[4]['rows'])], [8, false]);
check('a value that was set is kept and an empty one is not stored', [$stored[4]['min'], isset($stored[4]['max']), $stored[4]['step']], ['1', false, '5']);
check('anything but a list gives nothing', FormFields::parseInput('x'), []);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
