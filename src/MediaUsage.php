<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * Where each uploaded file is used. Content refers to uploads by address (`/uploads/media/photo.jpg`), in the front
 * matter, in blocks, and in the Markdown text, so this reads every content file once, and the settings texts it is
 * given, and collects the addresses found. One pass answers for every file, and the result is kept for the request.
 *
 * A file is "used" when its address appears anywhere in that text. It does not know about links a visitor typed by
 * hand in a browser, or about files a theme or `custom/` folder points to.
 */
final class MediaUsage
{
    /** @var array<string, list<array{label: string, url: string, kind: string}>>|null uploads-relative path => places */
    private ?array $map = null;

    /**
     * @param string $contentDir the site's content folder
     * @param callable(): list<array{label: string, url: string, kind: string, text: string}> $extraSources settings texts to read too
     * @param string $defaultLang language of files that have no language in their name
     */
    public function __construct(
        private string $contentDir,
        private $extraSources,
        private string $defaultLang = 'en'
    ) {
    }

    /**
     * The places one file is used, for the file at this path under `public/uploads` (e.g. `media/abc.jpg`).
     *
     * @return list<array{label: string, url: string, kind: string}>
     */
    public function placesFor(string $uploadsPath): array
    {
        return $this->all()[ltrim($uploadsPath, '/')] ?? [];
    }

    /** @return array<string, list<array{label: string, url: string, kind: string}>> */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        $map = [];
        $note = static function (string $text, array $place) use (&$map): void {
            if (!preg_match_all('#/?uploads/([A-Za-z0-9_\-./%]+)#', $text, $matches)) {
                return;
            }
            foreach (array_unique($matches[1]) as $found) {
                $path = rtrim(rawurldecode($found), '.');
                if ($path === '' || str_contains($path, '..')) {
                    continue;
                }
                $map[$path][] = $place;
            }
        };

        foreach ($this->contentFiles() as $file) {
            $text = @file_get_contents($file);
            if (!is_string($text) || !str_contains($text, 'uploads/')) {
                continue;
            }
            $note($text, $this->placeOf($file, $text));
        }
        foreach (($this->extraSources)() as $source) {
            $note((string)$source['text'], ['label' => (string)$source['label'], 'url' => (string)$source['url'], 'kind' => (string)$source['kind']]);
        }

        return $this->map = $map;
    }

    /** @return list<string> */
    private function contentFiles(): array
    {
        if (!is_dir($this->contentDir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->contentDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $info) {
            if (!$info->isFile() || !in_array(strtolower($info->getExtension()), ['md', 'yaml', 'yml'], true)) {
                continue;
            }
            $relative = substr($info->getPathname(), strlen(rtrim($this->contentDir, '/')) + 1);
            // The library's own records name the files, they do not use them; user accounts are not content.
            if (str_starts_with($relative, 'media/') || str_starts_with($relative, 'users/')) {
                continue;
            }
            $files[] = $info->getPathname();
        }
        sort($files);
        return $files;
    }

    /** @return array{label: string, url: string, kind: string} */
    private function placeOf(string $file, string $text): array
    {
        $relative = substr($file, strlen(rtrim($this->contentDir, '/')) + 1);
        $parts = explode('/', $relative);
        $type = $parts[0];
        $name = (string)end($parts);
        $base = preg_replace('/\.(md|ya?ml)$/i', '', $name) ?? $name;

        if ($type === 'menus') {
            return ['label' => 'Menu: ' . $base, 'url' => '/admin/menus-edit?key=' . rawurlencode($base), 'kind' => 'menu'];
        }
        if ($type === 'taxonomies') {
            return ['label' => 'Taxonomy: ' . $base, 'url' => '/admin/taxonomies?taxonomy=' . rawurlencode($base), 'kind' => 'taxonomy'];
        }

        $lang = $this->defaultLang;
        $slug = $base;
        if (preg_match('/^(.+)\.([a-z]{2,3})$/', $base, $m)) {
            [$slug, $lang] = [$m[1], $m[2]];
        }
        $title = '';
        if (preg_match('/^title:\s*(.+)$/m', $text, $t)) {
            $title = trim($t[1], " \t\r\"'");
        }
        $label = ($title !== '' ? $title : $slug) . ($lang !== $this->defaultLang ? ' (' . strtoupper($lang) . ')' : '');
        return [
            'label' => $label,
            'url' => '/admin/edit?type=' . rawurlencode($type) . '&slug=' . rawurlencode($slug) . '&lang=' . rawurlencode($lang),
            'kind' => $type,
        ];
    }
}
