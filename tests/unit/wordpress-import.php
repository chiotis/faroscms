<?php
/*
 * Bringing a WordPress site over: reading it through its REST API, working out the plan without changing anything, then
 * writing pages, posts, terms, media and the list of redirects, and doing it again without making copies.
 *   php tests/unit/wordpress-import.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use FarosCMS\{FrontMatter, MediaLibrary, Taxonomies, WordPressImporter, WordPressReader};
use Symfony\Component\Yaml\Yaml;

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$SITE = 'https://old.example';
$web = [
    '/wp-json/' => ['name' => 'Old &amp; Gold', 'description' => 'A writer', 'home' => $SITE, 'namespaces' => ['wp/v2'], 'show_on_front' => 'page', 'page_on_front' => 10],
    '/wp-json/wp/v2/types' => ['post' => ['rest_base' => 'posts'], 'page' => ['rest_base' => 'pages']],
    '/wp-json/wp/v2/taxonomies' => ['category' => ['rest_base' => 'categories'], 'post_tag' => ['rest_base' => 'tags']],
    '/wp-json/wp/v2/categories' => [
        ['id' => 1, 'slug' => 'news', 'name' => 'News', 'link' => "$SITE/category/news/", 'parent' => 0, 'count' => 2, 'description' => ''],
        ['id' => 2, 'slug' => 'reviews', 'name' => 'Reviews', 'link' => "$SITE/category/reviews/", 'parent' => 0, 'count' => 1, 'description' => 'What people say'],
        ['id' => 3, 'slug' => 'c3-logicomix', 'name' => 'Logicomix', 'link' => "$SITE/category/news/c3-logicomix/", 'parent' => 1, 'count' => 1, 'description' => ''],
        ['id' => 4, 'slug' => 'c4-logicomix', 'name' => 'Logicomix', 'link' => "$SITE/category/reviews/c4-logicomix/", 'parent' => 2, 'count' => 0, 'description' => ''],
    ],
    '/wp-json/wp/v2/tags' => [['id' => 7, 'slug' => 'maths', 'name' => 'Maths', 'link' => "$SITE/tag/maths/", 'parent' => 0, 'count' => 1, 'description' => '']],
    '/wp-json/wp/v2/media' => [
        ['id' => 50, 'source_url' => "$SITE/wp-content/uploads/2013/cover.png", 'alt_text' => 'The cover', 'title' => ['rendered' => 'cover'], 'mime_type' => 'image/png', 'post' => 0],
        ['id' => 51, 'source_url' => "$SITE/wp-content/uploads/2013/paper.pdf", 'alt_text' => '', 'title' => ['rendered' => 'paper'], 'mime_type' => 'application/pdf', 'post' => 0],
    ],
    '/wp-json/wp/v2/pages' => [
        ['id' => 10, 'slug' => 'home', 'link' => "$SITE/", 'title' => ['rendered' => 'Home'], 'content' => ['rendered' => '<p>Welcome. <a href="/about/">About</a>, <a href="' . $SITE . '/index.php/">old home</a>.</p>'], 'excerpt' => ['rendered' => '<p>Welcome. About, old home.…</p>'], 'date' => '2012-09-03T10:00:00', 'modified' => '2013-01-01T10:00:00', 'status' => 'publish', 'categories' => [], 'tags' => [], 'featured_media' => 0, 'parent' => 0, 'template' => 'x'],
        ['id' => 11, 'slug' => 'about', 'link' => "$SITE/about/", 'title' => ['rendered' => 'About &#8211; me'], 'content' => ['rendered' => '<p><img src="' . $SITE . '/wp-content/uploads/2013/cover-300x200.png" alt="cover-300x200.png"> The writer. <a href="/hello/">A post</a> and <a href="/category/news/c3-logicomix/">a category</a>, <a href="' . $SITE . '/wp-content/uploads/2013/paper.pdf">the paper</a>, <a href="https://elsewhere.test/x">out</a>.</p>'], 'excerpt' => ['rendered' => '<p>Written by the author.</p>'], 'date' => '2012-09-04T10:00:00', 'modified' => '2013-01-01T10:00:00', 'status' => 'publish', 'categories' => [], 'tags' => [], 'featured_media' => 0, 'parent' => 0, 'template' => ''],
        ['id' => 12, 'slug' => 'draft-page', 'link' => "$SITE/draft-page/", 'title' => ['rendered' => 'Draft'], 'content' => ['rendered' => '<p>x</p>'], 'excerpt' => ['rendered' => ''], 'date' => '2012-09-04T10:00:00', 'modified' => '', 'status' => 'draft', 'categories' => [], 'tags' => [], 'featured_media' => 0, 'parent' => 0, 'template' => ''],
    ],
    '/wp-json/wp/v2/posts' => [
        ['id' => 20, 'slug' => 'hello', 'link' => "$SITE/hello/", 'title' => ['rendered' => 'Hello'], 'content' => ['rendered' => '<p>Hello world, a long enough post to be cut for an excerpt by WordPress itself.</p><p><img src="' . $SITE . '/images/stories/gone.gif"> <img src="http://pics.elsewhere.test/a.jpg"> <img src="http://pics.elsewhere.test/b.jpg"> <a href="' . $SITE . '/missing-thing/">gone</a></p>'], 'excerpt' => ['rendered' => '<p>Hello world, a long enough post to be cut for an excerpt by WordPress itself. [&hellip;]</p>'], 'date' => '2009-02-03T10:00:00', 'modified' => '2009-02-03T10:00:00', 'status' => 'publish', 'categories' => [3], 'tags' => [7], 'featured_media' => 50, 'parent' => 0, 'template' => ''],
        ['id' => 21, 'slug' => 'about', 'link' => "$SITE/about/", 'title' => ['rendered' => 'About (post)'], 'content' => ['rendered' => '<p>Shadowed by the page of the same address.</p>'], 'excerpt' => ['rendered' => '<p>A summary that the author wrote by hand.</p>'], 'date' => '2009-03-03T10:00:00', 'modified' => '2009-03-03T10:00:00', 'status' => 'publish', 'categories' => [1], 'tags' => [], 'featured_media' => 0, 'parent' => 0, 'template' => ''],
        ['id' => 22, 'slug' => '%ce%b5%ce%bb%ce%bb%ce%b7%ce%bd%ce%b9%ce%ba%ce%ac', 'link' => "$SITE/%ce%b5%ce%bb%ce%bb%ce%b7%ce%bd%ce%b9%ce%ba%ce%ac/", 'title' => ['rendered' => 'Ελληνικά'], 'content' => ['rendered' => '<p>Καλημέρα</p>'], 'excerpt' => ['rendered' => ''], 'date' => '2010-01-01T10:00:00', 'modified' => '', 'status' => 'publish', 'categories' => [], 'tags' => [], 'featured_media' => 0, 'parent' => 0, 'template' => ''],
    ],
];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$requests = [];
$get = function (string $url) use (&$web, $SITE, &$requests): ?array {
    $requests[] = $url;
    $path = parse_url($url, PHP_URL_PATH);
    if (!str_starts_with($url, $SITE) || !isset($web[$path])) { return ['status' => 404, 'body' => '{}']; }
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    if (($query['page'] ?? '1') !== '1') { return ['status' => 400, 'body' => '{"code":"rest_post_invalid_page_number"}']; }
    return ['status' => 200, 'body' => json_encode($web[$path])];
};
$downloads = [];
$download = function (string $url, string $to) use (&$downloads, $png): int {
    $downloads[] = $url;
    if (str_ends_with($url, 'cover.png')) { file_put_contents($to, $png); return 200; }
    if (str_ends_with($url, 'paper.pdf')) { file_put_contents($to, "%PDF-1.4\n%%EOF\n"); return 200; }
    return str_contains($url, 'flaky') ? 503 : 404;
};

$root = sys_get_temp_dir() . '/wpimp' . getmypid();
$make = function (array $options = []) use (&$root, $get, $download, $SITE): WordPressImporter {
    @mkdir("$root/content", 0775, true);
    @mkdir("$root/uploads", 0775, true);
    $media = new MediaLibrary("$root/content", "$root/uploads");
    $media->ensureDirectories();
    return new WordPressImporter(new WordPressReader($SITE, $get), "$root/content", $media, new Taxonomies("$root/content", ['en']), $download, $options + ['lang' => 'en', 'default_lang' => 'en']);
};
$files = static fn(string $dir): array => array_map('basename', glob("$root/content/$dir/*.md") ?: []);
$front = static function (string $path): array { [$yaml] = FrontMatter::split((string)file_get_contents($path)); return Yaml::parse($yaml); };
$bodyOf = static function (string $path): string { [, $body] = FrontMatter::split((string)file_get_contents($path)); return $body; };

// ---- the reader
$reader = new WordPressReader($SITE, $get);
check('the site is told apart from a page that is not WordPress', (function () use ($SITE) { try { (new WordPressReader($SITE, fn() => ['status' => 200, 'body' => '<html>']))->info(); } catch (RuntimeException $e) { return 'refused'; } return 'accepted'; })(), 'refused');
check('an unreachable site is said so', (function () use ($SITE) { try { (new WordPressReader($SITE, fn() => null))->entries('posts'); } catch (RuntimeException $e) { return str_contains($e->getMessage(), 'Could not reach'); } return false; })(), true);
check('the name has no entities, and the home page is known', [$reader->info()['name'], $reader->info()['home_page_id']], ['Old & Gold', 10]);
check('only published entries are read, titles are plain text', array_map(fn($e) => $e['title'], $reader->entries('pages')), ['Home', 'About – me']);
check('an address with percent-encoded letters is decoded', $reader->entries('posts')[2]['slug'], 'ελληνικά');
check('text of a field', WordPressReader::text('<p>A&nbsp;&amp; <b>b</b></p>'), 'A & b');

// ---- the plan changes nothing
$importer = $make();
$plan = $importer->plan();
check('planning writes no content', [glob("$root/content/pages/*"), glob("$root/content/posts/*"), glob("$root/content/media/*.yaml")], [[], [], []]);
check('planning downloads nothing', $downloads, []);
check('what the plan counts', $plan['counts'], ['new' => 5, 'update' => 0, 'skip' => 0, 'media' => 3, 'redirects' => $plan['counts']['redirects']]);
check('the home page takes the home file name', array_map(fn($i) => $i['type'] . '/' . $i['slug'], $plan['items']), ['pages/index', 'pages/about', 'posts/hello', 'posts/about', 'posts/ellinika']);
check('a post and a page with one address are different files', [$plan['items'][1]['path'], $plan['items'][3]['path']], ['about', 'posts/about']);
check('categories with the same name get their parent in the name and address', array_map(fn($t) => [$t['slug'], $t['label']], $plan['terms']['categories']), [['news', 'News'], ['reviews', 'Reviews'], ['news-logicomix', 'Logicomix (News)'], ['reviews-logicomix', 'Logicomix (Reviews)']]);
check('tags are read too', array_map(fn($t) => $t['slug'], $plan['terms']['tags']), ['maths']);
$list = array_map(fn($r) => $r[0] . ' ' . $r[1], $plan['redirects']);
check('an old post address leads to the new one', in_array('/hello /posts/hello', $list, true), true);
check('an address that is a page here is not redirected', array_values(array_filter($list, fn($l) => str_starts_with($l, '/about '))), []);
check('a category address leads to the flat one', in_array('/category/news/c3-logicomix /category/news-logicomix', $list, true), true);
check('a Greek address is decoded in the redirect', in_array('/ελληνικά /posts/ellinika', $list, true), true);
check('pictures on other sites are counted by host', $plan['external_images'], ['pics.elsewhere.test' => 2]);
check('a link that cannot be resolved is counted', $plan['unresolved'], ['/missing-thing/' => 1]);
check('what the reader asked for was only ever read', count(array_filter($requests, fn($u) => !str_contains($u, '/wp-json/') )), 0);

// ---- applying
$result = $importer->apply($plan);
check('files written', [$files('pages'), $files('posts')], [['about.md', 'index.md'], ['about.md', 'ellinika.md', 'hello.md']]);
check('the result counts', [$result['written'], $result['updated'], $result['skipped'], $result['terms_added']], [5, 0, 0, 5]);
$about = $front("$root/content/pages/about.md");
check('a title with entities is plain', $about['title'], 'About – me');
check('the date and the source are kept', [$about['date'], $about['imported_from'], $about['status'], $about['visible']], ['2012-09-04', "$SITE/about/", 'published', true]);
check('an excerpt the author wrote is kept', $about['excerpt'], 'Written by the author.');
check('an excerpt WordPress cut itself is not', array_key_exists('excerpt', $front("$root/content/posts/hello.md")), false);
check('a home page excerpt that is the start of the text is not kept either', array_key_exists('excerpt', $front("$root/content/pages/index.md")), false);
$text = $bodyOf("$root/content/pages/about.md");
$coverId = substr(sha1("$SITE/wp-content/uploads/2013/cover.png"), 0, 16);
$paperId = substr(sha1("$SITE/wp-content/uploads/2013/paper.pdf"), 0, 16);
check('a size of a picture is the picture, with an id made from its address', str_contains($text, "![](/uploads/media/$coverId.png)"), true);
check('a file link points into the library', str_contains($text, "[the paper](/uploads/media/$paperId.pdf)"), true);
check('a link to a post leads to its new address', str_contains($text, '[A post](/posts/hello)'), true);
check('a link to a category leads to the flat one', str_contains($text, '[a category](/category/news-logicomix)'), true);
check('a link to another site is left alone', str_contains($text, '[out](https://elsewhere.test/x)'), true);
check('the old site\'s index.php is the home page', str_contains($bodyOf("$root/content/pages/index.md"), '[old home](/)') && str_contains($bodyOf("$root/content/pages/index.md"), '[About](/about)'), true);
$hello = $bodyOf("$root/content/posts/hello.md");
check('a picture the old site does not have is dropped', str_contains($hello, 'gone.gif'), false);
check('a link to a page that does not exist is kept as a path', str_contains($hello, '[gone](/missing-thing/)'), true);
$helloFront = $front("$root/content/posts/hello.md");
check('the featured picture is the main image', $helloFront['main_image'], "/uploads/media/$coverId.png");
check('a post is also in the categories above its own', [$helloFront['categories'], $helloFront['tags']], [['news-logicomix', 'news'], ['maths']]);
check('the file name of a Greek post is made of its title when WordPress gave letters', is_file("$root/content/posts/ellinika.md"), true);
check('the library has the files, with the alternative text', [(new MediaLibrary("$root/content", "$root/uploads"))->find($coverId)['alt'], is_file("$root/uploads/media/$coverId.png"), is_file("$root/uploads/media/$paperId.pdf")], ['The cover', true, true]);
check('the terms were added with their labels', array_map(fn($t) => $t['labels']['en'], (new Taxonomies("$root/content", ['en']))->load('categories')['terms']), ['News', 'Reviews', 'Logicomix (News)', 'Logicomix (Reviews)']);
check('a description comes with the term', (new Taxonomies("$root/content", ['en']))->load('categories')['terms'][1]['descriptions']['en'], 'What people say');
check('the redirects include the old address of a file that arrived', in_array("/wp-content/uploads/2013/cover.png /uploads/media/$coverId.png", array_map(fn($r) => $r[0] . ' ' . $r[1], $result['redirects']), true), true);
check('and the list is in the form the Redirects screen takes', str_starts_with(WordPressImporter::redirectList([['/a', '/b']]), "# Old address, new address, code. Paste into Admin > Redirects > Import.\n/a /b 301\n"), true);

// ---- doing it again
$downloads = [];
$again = $make();
$plan2 = $again->plan();
check('a second plan finds what the first brought', $plan2['counts']['update'], 5);
$result2 = $again->apply($plan2);
check('it updates and makes no second copy', [$result2['written'], $result2['updated'], count(glob("$root/content/media/*.yaml")), count($files('posts'))], [0, 5, 2, 3]);
check('files already in the library are not fetched again', array_values(array_filter($downloads, fn($u) => !str_contains($u, 'gone'))), []);
check('terms are not added twice', $result2['terms_added'], 0);

// ---- a file of the site's own
file_put_contents("$root/content/pages/about.md", "---\ntitle: My own about\nstatus: published\n---\n\nMine.\n");
$import3 = $make();
$plan3 = $import3->plan();
check('a file that no import brought is left alone', array_map(fn($i) => $i['state'], array_filter($plan3['items'], fn($i) => $i['slug'] === 'about' && $i['type'] === 'pages')), [1 => 'skip']);
$import3->apply($plan3);
check('and stays as it was', trim($bodyOf("$root/content/pages/about.md")), 'Mine.');
$plan4 = $make(['overwrite' => true])->plan();
check('unless asked to replace it', array_map(fn($i) => $i['state'], array_filter($plan4['items'], fn($i) => $i['slug'] === 'about' && $i['type'] === 'pages')), [1 => 'update']);

// ---- another language than the site's
$root = sys_get_temp_dir() . '/wpimp2' . getmypid();
$other = $make(['lang' => 'en', 'default_lang' => 'el']);
$plan5 = $other->plan();
check('content in a language other than the default sits under its prefix', [$plan5['items'][1]['file'], $plan5['items'][1]['path'], $plan5['terms']['categories'][0]['slug']], ["$root/content/pages/about.en.md", 'en/about', 'news']);
check('and so do the redirects of its categories', in_array('/category/news /en/category/news', array_map(fn($r) => $r[0] . ' ' . $r[1], $plan5['redirects']), true), true);
check('a plan from another importer is refused', (function () use ($make, $plan5) { try { $make()->apply($plan5); } catch (LogicException $e) { return 'refused'; } return 'accepted'; })(), 'refused');

// ---- a file that cannot be had, and one that is missing for good
$root = sys_get_temp_dir() . '/wpimp3' . getmypid();
$web['/wp-json/wp/v2/media'][] = ['id' => 52, 'source_url' => "$SITE/wp-content/uploads/2013/flaky.png", 'alt_text' => '', 'title' => ['rendered' => 'flaky'], 'mime_type' => 'image/png', 'post' => 0];
$web['/wp-json/wp/v2/posts'][0]['content']['rendered'] .= '<p><img src="' . $SITE . '/wp-content/uploads/2013/flaky.png" alt="Flaky"></p>';
$flaky = $make();
$planF = $flaky->plan();
$resultF = $flaky->apply($planF);
check('a file the site answered an error for is reported with why', array_values(array_filter($resultF['media_failed'], fn($m) => str_contains($m, 'flaky'))), ["$SITE/wp-content/uploads/2013/flaky.png (the site answered 503)"]);
check('its reference stays pointing at the old site, to be tried again', str_contains($bodyOf("$root/content/posts/hello.md"), "![Flaky]($SITE/wp-content/uploads/2013/flaky.png)"), true);
check('a file that is missing on the old site too is reported as that', array_values(array_filter($resultF['media_failed'], fn($m) => str_contains($m, 'gone'))) !== [], true);

// ---- only what is used
$root = sys_get_temp_dir() . '/wpimp4' . getmypid();
$used = $make(['only_used_media' => true])->plan();
check('the library files nobody uses can be left out', array_map(fn($f) => $f['name'], array_values($used['media'])), ['cover.png', 'paper.pdf', 'flaky.png', 'gone.gif']);

foreach (['', '2', '3', '4'] as $suffix) { exec('rm -rf ' . escapeshellarg(sys_get_temp_dir() . '/wpimp' . $suffix . getmypid())); }
echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
