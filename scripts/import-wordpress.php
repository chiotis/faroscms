<?php
/*
 * Brings the content of a WordPress site into this one. It reads the site through its public REST API (no password), and
 * by default only says what it would do; add --apply to do it.
 *
 *   php scripts/import-wordpress.php https://example.com                  what would happen
 *   php scripts/import-wordpress.php https://example.com --apply         do it
 *
 * Options:
 *   --lang=en            language of the imported content (default en)
 *   --default-lang=en    this site's default language (default: the same as --lang)
 *   --home=index         file name of this site's home page (default index)
 *   --kinds=pages,posts  what to bring (default both)
 *   --only-used-media    leave out library files that no page or post uses
 *   --overwrite          also replace a file of the site that an import did not bring
 *   --content=PATH       the content folder (default ./content)
 *   --uploads=PATH       the uploads folder (default ./public/uploads)
 *   --out=PATH           where the report and the redirects list are written (default ./storage/import)
 */
require __DIR__ . '/../vendor/autoload.php';

use FarosCMS\{MediaLibrary, Taxonomies, WordPressHttp, WordPressImporter, WordPressReader};

$root = dirname(__DIR__);
$site = '';
$opt = ['apply' => false, 'lang' => 'en', 'default-lang' => '', 'home' => 'index', 'kinds' => 'pages,posts', 'only-used-media' => false, 'overwrite' => false, 'content' => $root . '/content', 'uploads' => $root . '/public/uploads', 'out' => $root . '/storage/import'];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if (!array_key_exists($key, $opt)) {
            fwrite(STDERR, "Unknown option --$key\n");
            exit(2);
        }
        $opt[$key] = is_bool($opt[$key]) ? true : (string)$value;
    } elseif ($site === '') {
        $site = $arg;
    }
}
if ($site === '') {
    fwrite(STDERR, "Usage: php scripts/import-wordpress.php https://example.com [--apply] [--lang=en] ...\n");
    exit(2);
}
$site = preg_match('#^https?://#i', $site) ? $site : 'https://' . $site;
$lang = (string)$opt['lang'];
$defaultLang = $opt['default-lang'] !== '' ? (string)$opt['default-lang'] : $lang;

$media = new MediaLibrary($opt['content'], $opt['uploads']);
$media->ensureDirectories();
$importer = new WordPressImporter(
    new WordPressReader($site, static fn(string $url): ?array => WordPressHttp::get($url)),
    $opt['content'],
    $media,
    new Taxonomies($opt['content'], array_values(array_unique([$defaultLang, $lang]))),
    static fn(string $url, string $to): int => WordPressHttp::download($url, $to),
    ['lang' => $lang, 'default_lang' => $defaultLang, 'home_slug' => $opt['home'], 'overwrite' => $opt['overwrite'], 'only_used_media' => $opt['only-used-media'], 'kinds' => array_values(array_filter(explode(',', (string)$opt['kinds'])))]
);

try {
    echo "Reading $site ...\n";
    $plan = $importer->plan();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not read the site: ' . $e->getMessage() . "\n");
    exit(1);
}

$c = $plan['counts'];
echo "\n{$plan['site']['name']}\n";
printf("  pages and posts: %d new, %d to update, %d left alone\n", $c['new'], $c['update'], $c['skip']);
foreach (['categories', 'tags'] as $name) {
    printf("  %s: %d\n", $name, count($plan['terms'][$name]));
}
printf("  files for the media library: %d\n  redirects from old addresses: %d\n", $c['media'], $c['redirects']);
if ($lang !== $defaultLang) {
    echo "\n  Note: the content is in '$lang' but this site's default language is '$defaultLang', so its addresses start with /$lang/.\n";
}
foreach ($plan['items'] as $item) {
    if ($item['state'] === 'skip') {
        echo "  left alone: {$item['type']}/{$item['slug']} ({$item['reason']})\n";
    }
}
if ($plan['notes'] !== []) {
    echo "\nWhat could not be carried over exactly (" . count($plan['notes']) . "):\n";
    foreach (array_slice($plan['notes'], 0, 15) as $note) {
        echo "  - $note\n";
    }
}
if ($plan['unresolved'] !== []) {
    arsort($plan['unresolved']);
    echo "\nLinks to addresses that are not imported (kept as they were; the redirects list may cover them) - " . count($plan['unresolved']) . ":\n";
    foreach (array_slice($plan['unresolved'], 0, 15, true) as $path => $count) {
        echo "  - $path ($count)\n";
    }
}

if (!$opt['apply']) {
    echo "\nNothing was changed. Run again with --apply to import.\n";
    exit(0);
}

echo "\nImporting ...\n";
$result = $importer->apply($plan);
if (!is_dir($opt['out'])) {
    mkdir($opt['out'], 0775, true);
}
$redirectsFile = $opt['out'] . '/redirects.txt';
file_put_contents($redirectsFile, WordPressImporter::redirectList($result['redirects']));
printf("  %d written, %d updated, %d left alone\n  %d terms added\n  %d files brought into the library", $result['written'], $result['updated'], $result['skipped'], $result['terms_added'], $result['media_new']);
echo count($result['media_failed']) > 0 ? ', ' . count($result['media_failed']) . " could not be fetched\n" : "\n";
foreach (array_slice($result['media_failed'], 0, 20) as $failed) {
    echo "    - $failed\n";
}
printf("  redirects: %d, written to %s\n    Paste them in Admin > Redirects > Import on the site that will serve them.\n", count($result['redirects']), $redirectsFile);
echo "\nDone. Open the admin to look through what came in; every imported file has 'imported_from' in its front matter, so running this again updates them.\n";
