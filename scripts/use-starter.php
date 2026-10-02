<?php
/*
 * Starts a new site from the demo in starter/: copies its pages, posts, menus and media into content/ and
 * public/uploads/. It does nothing when the site already has content, so it can never overwrite a site.
 *
 *   php scripts/use-starter.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pairs = [$root . '/starter/content' => $root . '/content', $root . '/starter/uploads' => $root . '/public/uploads'];

if (!is_dir($root . '/starter/content')) {
    fwrite(STDERR, "There is no starter/ folder here.\n");
    exit(1);
}
if (glob($root . '/content/pages/*.md') || glob($root . '/content/posts/*.md')) {
    fwrite(STDERR, "This site already has pages or posts in content/, so nothing was copied.\n");
    exit(1);
}

function copyTree(string $from, string $to): int
{
    $count = 0;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($items as $item) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        if ($item->isDir()) {
            @mkdir($target, 0775, true);
        } elseif (!is_file($target)) {
            @mkdir(dirname($target), 0775, true);
            copy($item->getPathname(), $target);
            $count++;
        }
    }
    return $count;
}

$total = 0;
foreach ($pairs as $from => $to) {
    if (is_dir($from)) {
        @mkdir($to, 0775, true);
        $total += copyTree($from, $to);
    }
}
echo "Copied $total files. Open the site, then change the demo pages to your own.\n";
