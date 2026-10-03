<?php
/*
 * Theme > Branding: what the choices make of a page. The CSS (colours with the shades worked out from them, type, spacing,
 * corners, buttons, logo sizes), the tags of the head (icons, browser colour, font file), the attributes of the page, and the
 * two kinds of field the screen needs (a number that may be empty, a colour that must be hex).
 *   php tests/unit/branding.php
 */
$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
use FarosCMS\{Branding, FieldSchema, Theme};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n"; }
function has(string $haystack, string $needle): bool { return str_contains($haystack, $needle); }

// ---- the fields: a number that may be empty, a colour that must be hex
$fields = FieldSchema::normalize([
    'size' => ['type' => 'number', 'blank' => true, 'min' => 10, 'max' => 50],
    'plain' => ['type' => 'number', 'min' => 3, 'max' => 9],
    'colour' => ['type' => 'color', 'hex' => true],
    'any' => ['type' => 'color'],
]);
check('a blank number starts empty, a plain one at its minimum', [$fields['size']['default'], $fields['plain']['default']], ['', 3]);
check('a blank number can be emptied; a plain one cannot', [FieldSchema::fromInput($fields, ['size' => ''], ['size' => 20])['size'], FieldSchema::fromInput($fields, ['plain' => ''], ['plain' => 5])['plain']], ['', 5]);
check('and is kept within its limits', [FieldSchema::fromInput($fields, ['size' => '99'], [])['size'], FieldSchema::fromInput($fields, ['size' => '2'], [])['size'], FieldSchema::fromInput($fields, ['size' => 'abc'], ['size' => 12])['size']], [50, 10, 12]);
$hex = static fn($v) => FieldSchema::fromInput($fields, ['colour' => $v], ['colour' => '#112233'])['colour'];
check('a hex colour is written the same way: #rrggbb in lower case', [$hex('#ABC'), $hex('c026d3'), $hex(' #1D4ED8 '), $hex('')], ['#aabbcc', '#c026d3', '#1d4ed8', '']);
check('a colour that is not hex keeps the current one', [$hex('red'), $hex('rgb(1,2,3)'), $hex('#12'), $hex('url(x)')], ['#112233', '#112233', '#112233', '#112233']);
check('a colour without the hex rule still takes names', FieldSchema::fromInput($fields, ['any' => 'rebeccapurple'], [])['any'], 'rebeccapurple');

// ---- the theme declares them
$theme = new Theme($repo, 'default');
$schema = $theme->settingsSchema();
check('the default theme has the design section and the new brand fields', [isset($schema['design']), isset($schema['brand']['fields']['logo_dark']), isset($schema['brand']['fields']['favicon']), $schema['design']['fields']['container']['blank']], [true, true, true, true]);
$defaults = $theme->defaultSettings();
check('and every one of them starts empty or on "theme", so a site that sets none looks as it did', [
    $defaults['design']['light_accent'], $defaults['design']['base_size'], $defaults['design']['line_height'], $defaults['design']['heading_font'], $defaults['design']['scale'], $defaults['design']['button_case'], $defaults['brand']['logo_height'], $defaults['brand']['show_name'],
], ['', '', '', 'pairing', 'auto', 'auto', '', false]);
check('so they add nothing to the page', [Branding::css($defaults), Branding::head($defaults)], ['', '']);
check('neither does a site with no settings at all', [Branding::css([]), Branding::head([])], ['', '']);

// ---- colours
$css = Branding::css(['design' => ['light_accent' => '#1d4ed8', 'dark_accent' => '#93c5fd']]);
check('an accent brings its hover and soft shades and a readable text colour, for light and dark', [
    has($css, '--accent-l:#1d4ed8;'), has($css, '--accent-l-hover:#1840b1;'), has($css, '--accent-l-soft:#e8edfb;'), has($css, '--accent-contrast-l:#ffffff;'),
    has($css, '--accent-d:#93c5fd;'), has($css, '--accent-d-hover:#b3d6fe;'), has($css, '--accent-contrast-d:#0b1220;'),
], [true, true, true, true, true, true, true]);
check('the rule beats the palette, font, shape and mode rules of the style sheet', str_starts_with($css, ':root[data-theme][data-mode]{'), true);
$css = Branding::css(['design' => ['light_accent' => '#fde047']]);
check('a light accent gets dark text', has($css, '--accent-contrast-l:#0b1220;'), true);
$css = Branding::css(['design' => ['light_background' => '#fffdf8', 'light_surface' => '#f8f1e4', 'light_text' => '#261b14', 'light_muted' => '#5b4a3d', 'light_border' => '#eadfca', 'ink' => '#323130']]);
check('background, surface, text, muted and border set the neutrals; surface and border bring a second shade', [
    has($css, '--n-bg:#fffdf8;'), has($css, '--n-surface:#f8f1e4;'), has($css, '--n-surface-2:#'), has($css, '--n-text:#261b14;'), has($css, '--n-muted:#5b4a3d;'), has($css, '--n-border:#eadfca;'), has($css, '--n-border-strong:#'),
], [true, true, true, true, true, true, true]);
check('the dark panels get their colour, its channels and a lighter shade', [has($css, '--ink:#323130;'), has($css, '--ink-rgb:50 49 48;'), has($css, '--ink-2:#')], [true, true, true]);
$css = Branding::css(['design' => ['dark_background' => '#101010', 'dark_surface' => '#1a1a1a', 'dark_text' => '#f0f0f0', 'dark_muted' => '#aaaaaa', 'dark_border' => '#333333']]);
check('dark colours set the dark neutrals', [has($css, '--d-bg:#101010;'), has($css, '--d-surface:#1a1a1a;'), has($css, '--d-surface-2:#'), has($css, '--d-text:#f0f0f0;'), has($css, '--d-muted:#aaaaaa;'), has($css, '--d-border:#333333;'), has($css, '--d-border-strong:#')], [true, true, true, true, true, true, true]);
check('a colour that is not hex is ignored, never put in the style sheet', Branding::css(['design' => ['light_accent' => 'red; } body { display:none', 'light_text' => 'url(x)']]), '');

// ---- type
$css = Branding::css(['design' => ['heading_font' => 'geometric', 'body_font' => 'old_style']]);
check('a font choice sets the family of headings and of text', [has($css, '--font-heading:Avenir, Montserrat'), has($css, '--font-body:"Iowan Old Style"')], [true, true]);
check('"custom" without a font file is not used', Branding::css(['design' => ['heading_font' => 'custom']]), '');
$css = Branding::css(['design' => ['heading_font' => 'custom', 'font_file' => 'fonts/brand.woff2']], '/site');
check('with a font file it is loaded: the file of custom/assets, under the folder the site lives in', [
    has($css, '@font-face{font-family:"Brand Font"'), has($css, 'src:url("/site/_custom/fonts/brand.woff2") format("woff2")'), has($css, '--font-heading:"Brand Font", var(--font-system);'), has($css, '--font-body:'),
], [true, true, true, false]);
check('a full address is used as it is', has(Branding::css(['design' => ['body_font' => 'custom', 'font_file' => 'https://cdn.example.test/f.woff']]), 'url("https://cdn.example.test/f.woff") format("woff")'), true);
check('only font files are accepted: nothing else, nothing that climbs out of the folder', [
    Branding::css(['design' => ['body_font' => 'custom', 'font_file' => 'fonts/x.php']]), Branding::css(['design' => ['body_font' => 'custom', 'font_file' => '../secret.woff2']]), Branding::css(['design' => ['body_font' => 'custom', 'font_file' => 'a.woff2") } body {x:y']]),
], ['', '', '']);
$css = Branding::css(['design' => ['base_size' => 18]]);
check('a text size scales the type: 18 is 1.125 times the theme\'s 16', has($css, '--type-scale:1.125;'), true);
check('16 is the theme\'s own size: nothing', Branding::css(['design' => ['base_size' => 16]]), '');
$css = Branding::css(['design' => ['scale' => '1.25']]);
check('a heading scale works out five steps that grow with the window', preg_match_all('/--step-[1-5]:clamp\(/', $css), 5);
check('the biggest is the text size times the ratio five times: 17 x 1.25^5 = 51.9 pixels', has($css, '3.2425rem)'), true);
check('"theme" is no scale', Branding::css(['design' => ['scale' => 'auto']]), '');
$css = Branding::css(['design' => ['heading_weight' => '800', 'heading_tracking' => 'wide', 'heading_leading' => 'relaxed', 'heading_case' => 'uppercase', 'line_height' => 1.8]]);
check('heading weight, letter spacing, line height and case, and the line height of the text', [
    has($css, '--heading-weight:800;'), has($css, '--heading-tracking:0.025em;'), has($css, 'h1,h2,h3,h4,h5,h6{line-height:1.35;text-transform:uppercase}'), has($css, 'body.theme-body{line-height:1.8}'),
], [true, true, true, true]);
check('capitals without a letter spacing of their own get a little', has(Branding::css(['design' => ['heading_case' => 'uppercase']]), '--heading-tracking:0.02em;'), true);
check('a weight or word that is not on the list is ignored', Branding::css(['design' => ['heading_weight' => '999', 'heading_tracking' => 'x', 'heading_leading' => 'y', 'button_weight' => '100']]), '');

// ---- layout, spacing, corners, buttons, logo
$css = Branding::css(['design' => ['container' => 1400, 'reading_width' => 800, 'gutter' => 64, 'section_space' => 56, 'header_height' => 80, 'spacing' => 'airy']]);
check('widths and the header\'s height in pixels become rem; a margin and the space between sections follow the window', [
    has($css, '--container:87.5rem;'), has($css, '--container-narrow:50rem;'), has($css, '--gutter:clamp(1.25rem, calc('), has($css, '--section-scale:0.5;'), has($css, '--header-min:5rem;'), has($css, '--space-scale:1.2;'),
], [true, true, true, true, true, true]);
check('a side margin under 20 pixels does not grow', has(Branding::css(['design' => ['gutter' => 16]]), '--gutter:1rem;'), true);
$css = Branding::css(['design' => ['radius' => 10, 'button_radius' => 'square', 'shadows' => 'none']]);
check('a corner radius sets the four sizes from one number; buttons and shadows have their own choice', [
    has($css, '--radius-m:0.625rem;'), has($css, '--radius-s:0.3562rem;'), has($css, '--radius-xl:1.25rem;'), has($css, '--radius-button:0px;'), has($css, '--shadow-s:none;--shadow-m:none;'),
], [true, true, true, true, true]);
check('pill and rounded buttons', [has(Branding::css(['design' => ['button_radius' => 'pill']]), '--radius-button:999px;'), has(Branding::css(['design' => ['button_radius' => 'rounded']]), '--radius-button:var(--radius-s);')], [true, true]);
check('zero is a radius: square corners', has(Branding::css(['design' => ['radius' => 0]]), '--radius-m:0px;'), true);
$css = Branding::css(['design' => ['button_height' => 52, 'button_pad' => 28, 'button_size' => 16, 'button_weight' => '700', 'button_case' => 'uppercase']]);
check('buttons: height, padding, text size and weight, capitals with a little letter spacing', [
    has($css, '--btn-height:3.25rem;'), has($css, '--btn-pad-x:1.75rem;'), has($css, '--btn-size:1rem;'), has($css, '--btn-weight:700;'), has($css, '--btn-case:uppercase;'), has($css, '--btn-tracking:0.06em;'),
], [true, true, true, true, true, true]);
$css = Branding::css(['brand' => ['logo_height' => 48, 'logo_height_mobile' => 32, 'footer_logo_height' => 40, 'name_size' => 24]]);
check('the logo sizes and the size of the name', [has($css, '--logo-height:3rem;'), has($css, '--logo-height-mobile:2rem;'), has($css, '--footer-logo-height:2.5rem;'), has($css, '--brand-name-size:1.5rem;')], [true, true, true, true]);
check('a number given as text counts; an empty one does not', [has(Branding::css(['design' => ['radius' => '12']]), '--radius-m:0.75rem;'), Branding::css(['design' => ['radius' => '', 'container' => 'wide']])], [true, '']);

// ---- the head
$head = Branding::head(['brand' => ['favicon' => '/uploads/media/f.svg', 'touch_icon' => '/uploads/media/a.png', 'theme_color' => '#1E293B']], '/site');
check('icons, the home screen icon and the colour of the browser', [
    has($head, '<link rel="icon" href="/site/uploads/media/f.svg" type="image/svg+xml">'), has($head, '<link rel="apple-touch-icon" href="/site/uploads/media/a.png">'), has($head, '<meta name="theme-color" content="#1e293b">'),
], [true, true, true]);
check('a PNG and an ICO favicon say their type', [
    has(Branding::head(['brand' => ['favicon' => '/a.png']]), 'type="image/png"'), has(Branding::head(['brand' => ['favicon' => '/a.ico']]), 'type="image/x-icon"'),
], [true, true]);
check('an address with a quote or a bracket is not written', Branding::head(['brand' => ['favicon' => '/a.png" onerror="x', 'touch_icon' => 'javascript:alert(1)']]), '');
check('a font file in use is fetched early', has(Branding::head(['design' => ['body_font' => 'custom', 'font_file' => 'fonts/b.woff2']]), '<link rel="preload" href="/_custom/fonts/b.woff2" as="font" type="font/woff2" crossorigin>'), true);
check('and not when no font uses it', Branding::head(['design' => ['font_file' => 'fonts/b.woff2']]), '');

// ---- the attributes
check('the page gets the palette, mode, font, shape and decoration', Branding::attributes(['appearance' => ['palette' => 'garnet', 'mode' => 'dark', 'font' => 'serif', 'shape' => 'sharp', 'glow' => 'solid']]), [
    'data-theme' => 'garnet', 'data-mode' => 'dark', 'data-font' => 'serif', 'data-shape' => 'sharp', 'data-glow' => 'solid',
]);
check('and the usual ones without settings', Branding::attributes([]), ['data-theme' => 'slate', 'data-mode' => 'system', 'data-font' => 'sans', 'data-shape' => 'soft', 'data-glow' => 'soft']);

// ---- nothing a person typed reaches the style sheet as anything but a number, a hex colour or a word of the lists
$evil = ['design' => [
    'light_accent' => '#fff;}</style><script>x</script>', 'heading_font' => 'x;}body{background:red', 'scale' => '1.25;}', 'radius' => '5;}body{x:y', 'font_file' => 'a.woff2</style>',
    'button_case' => 'uppercase;}', 'spacing' => 'airy;}', 'shadows' => 'none;}',
], 'brand' => ['logo_height' => '9;}', 'favicon' => '/x"><script>']];
$out = Branding::css($evil) . Branding::head($evil);
check('so nothing of it survives', [has($out, '<script'), has($out, '</style'), has($out, 'body{')], [false, false, false]);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
