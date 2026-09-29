<?php
/*
 * Verifies that every block in the given content files (default: this site's pages, posts, projects, forms)
 * uses known fields and values that survive validation. Run it after editing content by hand:
 *
 *   php scripts/check-blocks.php [file.md ...]
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
use FarosCMS\{Theme, BlockRegistry, FieldSchema};
use Symfony\Component\Yaml\Yaml;
$reg = new BlockRegistry(new Theme($root, 'default'), ['content_types' => fn() => ['posts' => 'Posts', 'projects' => 'Projects'], 'forms' => fn() => ['' => '—', 'contact' => 'Contact']]);
$problems = 0; $files = 0; $blocksTotal = 0;
$files_list = array_slice($argv, 1) ?: array_merge(glob("$root/content/pages/*.md"), glob("$root/content/posts/*.md"), glob("$root/content/projects/*.md"), glob("$root/content/forms/*.md"));
foreach ($files_list as $file) {
    preg_match('/\A---\R(.*?)\R---/s', file_get_contents($file), $m);
    $meta = Yaml::parse($m[1]) ?: [];
    if (empty($meta['blocks'])) continue;
    $files++;
    $rel = str_replace("$root/", '', $file);
    foreach ($meta['blocks'] as $i => $block) {
        $blocksTotal++;
        $def = $reg->get($block['type'] ?? '');
        if (!$def) { echo "PROBLEM $rel #$i unknown block type " . ($block['type'] ?? '?') . "\n"; $problems++; continue; }
        $fields = $def['common'] + $def['fields'];
        foreach ($block as $key => $value) {
            if ($key === 'type') continue;
            if (!isset($fields[$key])) { echo "PROBLEM $rel #$i ({$block['type']}) unknown field '$key'\n"; $problems++; continue; }
            $clean = FieldSchema::clean($fields[$key], $value);
            if ($fields[$key]['type'] === 'repeater') {
                foreach ((array)$value as $r => $row) {
                    foreach ((array)$row as $k => $v) {
                        if (!isset($fields[$key]['fields'][$k])) { echo "PROBLEM $rel #$i ({$block['type']}.$key) unknown sub-field '$k'\n"; $problems++; continue; }
                        $cv = FieldSchema::clean($fields[$key]['fields'][$k], $v);
                        if ($cv !== $v && !(is_string($v) && trim($v) === $cv)) { echo "PROBLEM $rel #$i ({$block['type']}.$key[$r].$k) value changed by validation: " . json_encode($v) . " -> " . json_encode($cv) . "\n"; $problems++; }
                    }
                }
            } elseif ($clean !== $value && !(is_string($value) && trim($value) === $clean) && !(is_string($value) && str_replace("\r\n", "\n", trim($value)) === $clean)) {
                echo "PROBLEM $rel #$i ({$block['type']}.$key) value changed by validation: " . json_encode($value) . " -> " . json_encode($clean) . "\n"; $problems++;
            }
        }
    }
}
echo "$files files with blocks, $blocksTotal blocks, $problems problems\n";
