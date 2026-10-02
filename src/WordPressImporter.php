<?php

declare(strict_types=1);

namespace FarosCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Brings the content of a WordPress site over: its pages and posts as Markdown files, its categories and tags as terms,
 * the pictures and files they use into the media library, and the list of old addresses that must lead to the new ones.
 *
 * It works in two steps. plan() reads the site and works out everything without changing anything (what would be
 * created, updated or skipped, which files would be downloaded, which links cannot be resolved). apply() then does it.
 * Every id is made from the address it came from, so running it again updates what it brought and never makes a second
 * copy; a file of the site's own that was not brought by an import is never overwritten unless asked.
 *
 * WordPress keeps categories in a tree and this site's categories are a flat list: a category that shares its address
 * with another one takes its parent's name in front, and a post in a child category is also put in its parents, as
 * WordPress shows it on the parent's page.
 */
final class WordPressImporter
{
    private const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'rtf', 'txt', 'zip', 'mp3', 'wav', 'ogg', 'm4a', 'mp4', 'webm', 'mov'];

    /** @var array<string, array{id: string, ext: string, name: string, alt: string, uses: int, attachment: bool}> canonical address => file */
    private array $mediaFiles = [];

    /** @var array<int, string> attachment id => canonical address */
    private array $attachmentUrls = [];

    /** @var array<string, bool> canonical address => it is in the library now */
    private array $mediaReady = [];

    /** @var array<string, string> old path (no slashes at the ends) => new public path */
    private array $linkMap = [];

    /** @var array<string, int> */
    private array $unresolved = [];

    /** @var array<string, int> host => pictures of other sites that the text points to */
    private array $external = [];

    /** @var array<string, array{slug: string, label: string, description: string, parent: int, link: string}> */
    private array $termInfo = [];

    /** @var array<string, bool> canonical address => the old site does not have it either */
    private array $mediaGone = [];

    private bool $final = false;

    private bool $planned = false;

    /** @var array<int, array{image: string, title: string, text: string, buttons: array<int, array{label: string, url: string}>}> */
    private array $slides = [];

    /**
     * @param \Closure(string, string): int $download fetches an address into a file and returns the answer's status, 0 when the site could not be reached
     * @param array{lang?: string, default_lang?: string, home_slug?: string, overwrite?: bool, only_used_media?: bool, kinds?: string[], custom_dir?: string, menus?: bool} $options
     * @param array<string, mixed> $profile what to read from the pages of the site, and how to lay out the home page (see docs/wordpress-import.md)
     */
    public function __construct(
        private WordPressReader $reader,
        private string $contentDir,
        private MediaLibrary $media,
        private Taxonomies $taxonomies,
        private \Closure $download,
        private array $options = [],
        private ?WordPressScraper $scraper = null,
        private array $profile = [],
        private ?\Closure $writeMenu = null
    ) {
        $this->contentDir = rtrim($contentDir, '/');
    }

    private function lang(): string
    {
        return (string)($this->options['lang'] ?? 'en');
    }

    private function defaultLang(): string
    {
        return (string)($this->options['default_lang'] ?? $this->lang());
    }

    private function homeSlug(): string
    {
        return (string)($this->options['home_slug'] ?? 'index');
    }

    /**
     * Reads the site and says what an import would do. Nothing is written and no file is downloaded.
     *
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $this->reset();
        $info = $this->reader->info();
        $kinds = $this->options['kinds'] ?? ['pages', 'posts'];

        $categories = in_array('posts', $kinds, true) ? $this->reader->terms('categories') : [];
        $tags = in_array('posts', $kinds, true) && in_array('tags', $info['taxonomies'], true) ? $this->reader->terms('tags') : [];
        $terms = ['categories' => $this->flatTerms($categories, 'category'), 'tags' => $this->flatTerms($tags, 'tag')];

        foreach ($this->reader->media() as $file) {
            $this->attachmentUrls[$file['id']] = $this->registerMedia($file['url'], $file['alt'], $file['title'], true);
        }

        $entries = [];
        $slugs = [];
        foreach (['pages' => 'pages', 'posts' => 'posts'] as $base => $type) {
            if (!in_array($base, $kinds, true)) {
                continue;
            }
            foreach ($this->reader->entries($base) as $entry) {
                $slug = $this->slugFor($entry, $type === 'pages' && $entry['id'] === $info['home_page_id'], $slugs[$type] ?? []);
                $slugs[$type][$slug] = true;
                $entry['type'] = $type;
                $entry['new_slug'] = $slug;
                $entry['new_path'] = ContentPaths::build($type, $slug, $this->lang(), $this->homeSlug(), $this->defaultLang());
                // When a page and a post share one old address, the page is what the old site showed there.
                $this->linkMap[self::pathOf($entry['link'])] ??= $entry['new_path'];
                $entries[] = $entry;
            }
        }
        foreach ($terms as $name => $list) {
            foreach ($list as $term) {
                $this->linkMap[self::pathOf($term['link'])] = $this->termPath($name, $term['slug']);
            }
        }

        // What the API does not give, read from the pages themselves.
        $this->slides = $this->scraper?->slides() ?? [];
        $menus = [];
        foreach (array_keys((array)($this->profile['menus'] ?? [])) as $menuKey) {
            $menus[(string)$menuKey] = $this->scraper?->menu((string)$menuKey) ?? [];
        }
        foreach ($this->scraper?->entries() ?? [] as $entry) {
            $type = $entry['type'];
            $slug = $this->slugFor($entry + ['title' => $entry['title']], false, $slugs[$type] ?? []);
            $slugs[$type][$slug] = true;
            $entry['new_slug'] = $slug;
            $entry['new_path'] = ContentPaths::build($type, $slug, $this->lang(), $this->homeSlug(), $this->defaultLang());
            $this->linkMap[self::pathOf($entry['link'])] ??= $entry['new_path'];
            $entries[] = $entry;
        }
        foreach ((array)($this->profile['archives'] ?? []) as $oldPath => $newType) {
            $this->linkMap[trim((string)$oldPath, '/')] = ContentPaths::archive((string)$newType, $this->lang(), $this->defaultLang());
        }

        $items = [];
        $notes = [];
        $redirects = [];
        foreach ($entries as $entry) {
            $made = $this->render($entry, $terms);
            foreach ($made['notes'] as $note) {
                $notes[$entry['type'] . '/' . $entry['new_slug'] . ': ' . $note] = 1;
            }
            $file = $this->contentDir . '/' . $entry['type'] . '/' . (new ContentPaths(['languages' => ['default' => $this->defaultLang()]]))->filename($entry['new_slug'], $this->lang());
            [$state, $reason] = $this->stateOf($file, $entry['link']);
            $items[] = [
                'id' => $entry['id'],
                'type' => $entry['type'],
                'slug' => $entry['new_slug'],
                'title' => $entry['title'],
                'file' => $file,
                'state' => $state,
                'reason' => $reason,
                'from' => $entry['link'],
                'path' => $entry['new_path'],
                'words' => (int)preg_match_all('/\S+/u', $made['body']),
            ];
            $this->addRedirect($redirects, $entry['link'], '/' . $entry['new_path']);
        }
        foreach ($terms as $name => $list) {
            foreach ($list as $term) {
                $this->addRedirect($redirects, $term['link'], '/' . $this->termPath($name, $term['slug']));
            }
        }
        foreach ((array)($this->profile['archives'] ?? []) as $oldPath => $newType) {
            $this->addRedirect($redirects, '/' . trim((string)$oldPath, '/'), '/' . ContentPaths::archive((string)$newType, $this->lang(), $this->defaultLang()));
        }

        $onlyUsed = (bool)($this->options['only_used_media'] ?? false);
        // An old address that is a page of this site's own must not be sent anywhere else.
        $own = [];
        foreach ($items as $item) {
            $own['/' . $item['path']] = true;
        }
        $redirects = array_values(array_filter($redirects, static fn(array $r): bool => !isset($own[rtrim($r[0], '/')])));
        $media = array_filter($this->mediaFiles, static fn(array $file): bool => $file['uses'] > 0 || (!$onlyUsed && $file['attachment']));
        $this->planned = true;
        return [
            'site' => ['name' => $info['name'], 'url' => $info['url'], 'description' => $info['description']],
            'items' => $items,
            'entries' => $entries,
            'terms' => $terms,
            'menus' => $menus,
            'slides' => count($this->slides),
            'media' => $media,
            'redirects' => $redirects,
            'notes' => array_keys($notes),
            'unresolved' => $this->unresolved,
            'external_images' => $this->external,
            'counts' => [
                'new' => count(array_filter($items, static fn(array $i): bool => $i['state'] === 'new')),
                'update' => count(array_filter($items, static fn(array $i): bool => $i['state'] === 'update')),
                'skip' => count(array_filter($items, static fn(array $i): bool => $i['state'] === 'skip')),
                'media' => count($media),
                'redirects' => count($redirects),
            ],
        ];
    }

    /**
     * Does what plan() described: terms, then files, then the pages and posts.
     *
     * @param array<string, mixed> $plan the result of plan()
     * @return array{written: int, updated: int, skipped: int, media_new: int, media_failed: string[], terms_added: int, redirects: array<int, array{0: string, 1: string}>, notes: string[], menus: string[], content_types: string[]}
     */
    public function apply(array $plan): array
    {
        if (!$this->planned) {
            throw new \LogicException('apply() needs the same importer that made the plan.');
        }
        $this->final = true;
        $result = ['written' => 0, 'updated' => 0, 'skipped' => 0, 'media_new' => 0, 'media_failed' => [], 'terms_added' => 0, 'redirects' => [], 'notes' => [], 'menus' => [], 'content_types' => []];

        foreach ($plan['terms'] as $name => $list) {
            $result['terms_added'] += $this->saveTerms((string)$name, $list);
        }

        foreach ($plan['media'] as $canonical => $file) {
            if ($this->media->find($file['id']) !== null) {
                $this->mediaReady[$canonical] = true;
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'wpimp');
            if ($tmp === false) {
                $result['media_failed'][] = $canonical;
                continue;
            }
            $status = ($this->download)($canonical, $tmp);
            if ($status !== 200 && isset($file['original']) && $file['original'] !== $canonical) {
                $status = ($this->download)((string)$file['original'], $tmp);
            }
            try {
                if ($status !== 200) {
                    $this->mediaGone[$canonical] = $status === 404 || $status === 410;
                    throw new \RuntimeException($status === 404 || $status === 410 ? 'missing on the old site too, so the references to it are dropped' : 'the site answered ' . $status);
                }
                $this->media->import($tmp, $file['name'], $file['id'], $file['alt'], 'wordpress-import');
                $this->mediaReady[$canonical] = true;
                $result['media_new']++;
            } catch (\Throwable $e) {
                $result['media_failed'][] = $canonical . ' (' . $e->getMessage() . ')';
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        $result['menus'] = [];
        foreach ($plan['menus'] as $key => $items) {
            if ($items === []) {
                continue;
            }
            if (($this->options['menus'] ?? false) && $this->writeMenu !== null) {
                ($this->writeMenu)((string)$key, ['title' => Slug::title((string)$key) . ' menu', 'items' => $this->menuRows($items)]);
                $result['menus'][] = (string)$key;
            }
        }
        foreach ((array)($this->profile['content_types'] ?? []) as $name => $definition) {
            $path = rtrim((string)($this->options['custom_dir'] ?? ''), '/') . '/content-types/' . Slug::plain((string)$name) . '.yaml';
            if (($this->options['custom_dir'] ?? '') !== '' && !is_file($path) && is_array($definition)) {
                @mkdir(dirname($path), 0775, true);
                file_put_contents($path, Yaml::dump($definition, 6, 2));
                $result['content_types'][] = (string)$name;
            }
        }

        // The conversion runs again now that it is known which files arrived: a link to a file that did not come stays as it was.
        $notes = [];
        $redirects = $plan['redirects'];
        foreach ($plan['media'] as $canonical => $file) {
            if (!empty($this->mediaReady[$canonical])) {
                foreach ($file['seen'] ?? [] as $oldPath) {
                    $this->addRedirect($redirects, $oldPath, $this->mediaPath($file));
                }
            }
        }
        $byLink = []; // a page and a post can share an address on the old site, so they are told apart by kind and id
        foreach ($plan['items'] as $item) {
            $byLink[$item['type'] . '/' . $item['id']] = $item;
        }
        foreach ($plan['entries'] as $entry) {
            $item = $byLink[$entry['type'] . '/' . $entry['id']] ?? null;
            if ($item === null || $item['state'] === 'skip') {
                $result['skipped']++;
                continue;
            }
            $made = $this->render($entry, $plan['terms']);
            foreach ($made['notes'] as $note) {
                $notes[$entry['type'] . '/' . $entry['new_slug'] . ': ' . $note] = 1;
            }
            $dir = dirname($item['file']);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($item['file'], "---\n" . Yaml::dump($made['front'], 4, 2) . "---\n\n" . $made['body']);
            $item['state'] === 'update' ? $result['updated']++ : $result['written']++;
        }
        $result['redirects'] = $redirects;
        $result['notes'] = array_keys($notes);
        $this->taxonomies->forget();
        return $result;
    }

    /**
     * The text of a redirects list, one "old new 301" per line, in the form Admin > Redirects takes pasted.
     *
     * @param array<int, array{0: string, 1: string}> $redirects
     */
    public static function redirectList(array $redirects): string
    {
        $lines = ['# Old address, new address, code. Paste into Admin > Redirects > Import.'];
        foreach ($redirects as [$from, $to]) {
            $lines[] = $from . ' ' . $to . ' 301';
        }
        return implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------------ entries

    private function reset(): void
    {
        $this->mediaFiles = [];
        $this->attachmentUrls = [];
        $this->mediaReady = [];
        $this->linkMap = [];
        $this->unresolved = [];
        $this->termInfo = [];
        $this->mediaGone = [];
        $this->external = [];
        $this->slides = [];
        $this->planned = false;
        $this->final = false;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, bool> $taken addresses already used by this kind
     */
    private function slugFor(array $entry, bool $isHome, array $taken): string
    {
        if ($isHome) {
            return $this->homeSlug();
        }
        $slug = Slug::plain((string)$entry['slug']);
        if ($slug === '' || $slug !== $entry['slug']) {
            $slug = Slug::fromText((string)$entry['title']);
        }
        if ($slug === '') {
            $slug = Slug::plain($entry['type'] ?? 'item') . '-' . $entry['id'];
        }
        $base = $slug;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /**
     * What an existing file means for the import.
     *
     * @return array{0: string, 1: string} new, update or skip, and why
     */
    private function stateOf(string $file, string $from): array
    {
        if (!is_file($file)) {
            return ['new', ''];
        }
        [$yaml] = FrontMatter::split((string)file_get_contents($file));
        $front = [];
        try {
            $parsed = Yaml::parse($yaml);
            $front = is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            $front = [];
        }
        if (($front['imported_from'] ?? '') === $from) {
            return ['update', 'brought by an earlier import'];
        }
        if ($this->options['overwrite'] ?? false) {
            return ['update', 'replaced on request'];
        }
        return ['skip', 'a file of the site with this address exists'];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, array<int, array<string, mixed>>> $terms
     * @return array{front: array<string, mixed>, body: string, notes: string[]}
     */
    private function render(array $entry, array $terms): array
    {
        if (isset($entry['post_type'])) {
            return $this->renderScraped($entry);
        }
        $converter = new HtmlToMarkdown(fn(string $address, string $kind): string => $this->resolve($address, $kind));
        $body = $converter->convert((string)$entry['html']);

        $front = ['title' => $entry['title'] !== '' ? $entry['title'] : Slug::title($entry['new_slug']), 'status' => 'published', 'visible' => true];
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$entry['date'], $m)) {
            $front['date'] = $m[0];
        }
        $excerpt = $this->explicitExcerpt((string)$entry['excerpt'], (string)$entry['html']);
        if ($excerpt !== '') {
            $front['excerpt'] = $excerpt;
        }
        if ($entry['image'] > 0 && isset($this->attachmentUrls[$entry['image']])) {
            $canonical = $this->attachmentUrls[$entry['image']];
            $this->mediaFiles[$canonical]['uses']++;
            $front['main_image'] = $this->resolve($canonical, 'image');
        }
        if ($entry['type'] === 'posts') {
            $categories = $this->withParents($entry['categories'], $terms['categories']);
            if ($categories !== []) {
                $front['categories'] = $categories;
            }
            $tagSlugs = [];
            foreach ($entry['tags'] as $id) {
                foreach ($terms['tags'] as $term) {
                    if ($term['id'] === $id) {
                        $tagSlugs[] = $term['slug'];
                    }
                }
            }
            if ($tagSlugs !== []) {
                $front['tags'] = $tagSlugs;
            }
        }
        $front['imported_from'] = $entry['link'];
        $home = $this->profile['home']['blocks'] ?? null;
        if ($entry['type'] === 'pages' && $entry['new_slug'] === $this->homeSlug() && is_array($home) && $home !== []) {
            [$image, $text] = self::splitLeadingImage($body);
            $front['template'] = 'landing';
            $front['blocks'] = $this->fill($home, ['@slides' => $this->slideItems(), '@body' => $text, '@image' => $image]);
            $body = '';
        }
        return ['front' => $front, 'body' => $body, 'notes' => $converter->notes()];
    }

    /**
     * A page of a custom type (a book, a play): the cover and the description side by side, and the tabs as a Tabs block.
     *
     * @param array<string, mixed> $entry
     * @return array{front: array<string, mixed>, body: string, notes: string[]}
     */
    private function renderScraped(array $entry): array
    {
        $converter = new HtmlToMarkdown(fn(string $address, string $kind): string => $this->resolve($address, $kind));
        $summary = trim($converter->convert((string)$entry['summary']));
        $image = $entry['image'] !== '' ? $this->resolve((string)$entry['image'], 'image') : '';
        $front = ['title' => $entry['title'], 'status' => 'published', 'visible' => true];
        if ($image !== '') {
            $front['main_image'] = $image;
        }
        // Front matter the profile wants on every page of the type (the opening layout, say).
        foreach ((array)($this->profile['types'][$entry['post_type']]['front'] ?? []) as $key => $value) {
            $front[(string)$key] = $value;
        }
        $blocks = [];
        if ($summary !== '' || $image !== '') {
            $blocks[] = ['type' => 'text-image', 'variant' => 'image-left', 'body' => $summary, 'image' => $image, 'image_alt' => $image !== '' ? $entry['title'] : '', 'image_ratio' => 'portrait'];
        }
        $tabs = [];
        foreach ($entry['tabs'] as $tab) {
            $tabs[] = ['label' => $tab['label'], 'text' => trim($converter->convert($tab['html']))];
        }
        if ($tabs !== []) {
            $blocks[] = ['type' => 'tabs', 'variant' => 'horizontal', 'items' => $tabs];
        }
        if ($blocks !== []) {
            $front['blocks'] = $blocks;
        }
        $front['imported_from'] = $entry['link'];
        return ['front' => $front, 'body' => '', 'notes' => $converter->notes()];
    }

    /** @return array<int, array<string, mixed>> the slides of the home page as items of a slider block */
    private function slideItems(): array
    {
        $items = [];
        foreach ($this->slides as $slide) {
            $item = ['image' => $slide['image'] !== '' ? $this->resolve($slide['image'], 'image') : '', 'title' => $slide['title'], 'text' => $slide['text']];
            $actions = [];
            foreach ($slide['buttons'] as $button) {
                $actions[] = ['label' => $button['label'], 'url' => $this->resolve($button['url'], 'link'), 'style' => 'primary'];
            }
            if ($actions !== []) {
                $item['actions'] = $actions;
            }
            $items[] = $item;
        }
        return $items;
    }

    /** The picture a text starts with, and the text after it. @return array{0: string, 1: string} */
    private static function splitLeadingImage(string $markdown): array
    {
        if (preg_match('/\A!\[[^\]]*\]\(([^)\s]+)\)\s*(.*)\z/s', ltrim($markdown), $m)) {
            return [$m[1], trim($m[2])];
        }
        return ['', trim($markdown)];
    }

    /**
     * A template with the words @slides, @body and @image in it, filled in.
     *
     * @param array<string, mixed> $values
     */
    private function fill(mixed $template, array $values): mixed
    {
        if (is_string($template)) {
            return array_key_exists($template, $values) ? $values[$template] : $template;
        }
        if (!is_array($template)) {
            return $template;
        }
        $out = [];
        foreach ($template as $key => $value) {
            $out[$key] = $this->fill($value, $values);
        }
        return $out;
    }

    /**
     * A menu read from the pages, as items the Menus class writes, with their addresses on the new site.
     *
     * @param array<int, array{label: string, url: string, children?: array<int, mixed>}> $items
     * @return array<int, array<string, mixed>>
     */
    private function menuRows(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $row = ['label' => $item['label'], 'url' => $this->resolve($item['url'], 'link')];
            if (!empty($item['children'])) {
                $row['children'] = $this->menuRows($item['children']);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** The excerpt an author wrote; one WordPress cut from the text on its own is left out. */
    private function explicitExcerpt(string $excerptHtml, string $bodyHtml): string
    {
        $excerpt = WordPressReader::text($excerptHtml);
        $excerpt = trim(preg_replace('/\s*(\[…\]|\[&hellip;\]|…\s*continue reading|…|\.\.\.)$/ui', '', $excerpt) ?? $excerpt);
        if ($excerpt === '') {
            return '';
        }
        $compact = static fn(string $text): string => preg_replace('/\s+/u', '', $text) ?? $text;
        $head = mb_substr($compact($excerpt), 0, 40);
        return str_starts_with($compact(WordPressReader::text($bodyHtml)), $head) ? '' : $excerpt;
    }

    // ------------------------------------------------------------------ terms

    /**
     * @param array<int, array{id: int, slug: string, name: string, link: string, parent: int, count: int, description: string}> $terms
     * @return array<int, array{id: int, slug: string, label: string, description: string, parent: int, link: string}>
     */
    private function flatTerms(array $terms, string $kind): array
    {
        $byId = [];
        $names = [];
        foreach ($terms as $term) {
            $byId[$term['id']] = $term;
            $names[mb_strtolower($term['name'])] = ($names[mb_strtolower($term['name'])] ?? 0) + 1;
        }
        $used = [];
        $flat = [];
        foreach ($terms as $term) {
            $slug = Slug::plain($term['slug']);
            $slug = $slug !== '' && $slug === $term['slug'] ? $slug : (Slug::fromText($term['name']) ?: 'term-' . $term['id']);
            $label = $term['name'];
            $parent = $byId[$term['parent']] ?? null;
            if ($names[mb_strtolower($term['name'])] > 1 && $parent !== null) {
                // The same name under several parents (Reviews > Logicomix, News > Logicomix): the parent tells them apart.
                $prefix = Slug::plain($parent['slug']) ?: Slug::fromText($parent['name']);
                $own = Slug::fromText(str_replace(["’", "'"], '', $term['name'])) ?: $slug;
                $slug = $prefix . '-' . $own;
                $label = $term['name'] . ' (' . $parent['name'] . ')';
            }
            $base = $slug;
            for ($n = 2; isset($used[$slug]); $n++) {
                $slug = $base . '-' . $n;
            }
            $used[$slug] = true;
            $flat[] = ['id' => $term['id'], 'slug' => $slug, 'label' => $label, 'description' => $term['description'], 'parent' => $term['parent'], 'link' => $term['link']];
        }
        return $flat;
    }

    /**
     * The terms of a post and the terms above them, as addresses.
     *
     * @param int[] $ids
     * @param array<int, array{id: int, slug: string, parent: int}> $terms
     * @return string[]
     */
    private function withParents(array $ids, array $terms): array
    {
        $byId = [];
        foreach ($terms as $term) {
            $byId[$term['id']] = $term;
        }
        $out = [];
        foreach ($ids as $id) {
            for ($guard = 0; $id > 0 && isset($byId[$id]) && $guard < 10; $guard++) {
                $out[$byId[$id]['slug']] = true;
                $id = $byId[$id]['parent'];
            }
        }
        return array_keys($out);
    }

    private function termPath(string $taxonomy, string $slug): string
    {
        $prefix = $this->lang() === $this->defaultLang() ? '' : $this->lang() . '/';
        return $prefix . Taxonomies::kind($taxonomy) . '/' . $slug;
    }

    /**
     * Adds the terms that are not in the taxonomy yet; the ones it has are left as they are.
     *
     * @param array<int, array{slug: string, label: string, description: string}> $list
     */
    private function saveTerms(string $name, array $list): int
    {
        if ($list === []) {
            return 0;
        }
        $current = $this->taxonomies->load($name);
        $terms = $current['terms'];
        $have = array_column($terms, 'id');
        $added = 0;
        foreach ($list as $term) {
            if (in_array($term['slug'], $have, true)) {
                continue;
            }
            $terms[] = [
                'id' => $term['slug'],
                'slug' => $term['slug'],
                'labels' => [$this->lang() => $term['label']],
                'descriptions' => [$this->lang() => $term['description']],
            ];
            $added++;
        }
        if ($added > 0) {
            $this->taxonomies->save($name, $current['title'], $terms);
        }
        return $added;
    }

    // ------------------------------------------------------------------ addresses

    /** Where an address inside the old site leads on the new one. */
    private function resolve(string $address, string $kind): string
    {
        $address = trim(html_entity_decode($address, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $site = $this->reader->site();
        $absolute = preg_match('#^[a-z][a-z0-9+.-]*:#i', $address) ? $address : (str_starts_with($address, '//') ? 'https:' . $address : rtrim($site, '/') . '/' . ltrim($address, '/'));
        if (!preg_match('#^https?://#i', $absolute)) {
            return $address; // mailto:, tel: and the like
        }
        if (!$this->sameSite($absolute)) {
            if ($kind === 'image' && !$this->final) {
                $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
                $this->external[$host] = ($this->external[$host] ?? 0) + 1;
            }
            return $address;
        }
        $parts = parse_url($absolute) ?: [];
        $path = (string)($parts['path'] ?? '/');
        $suffix = (isset($parts['query']) && !self::isMediaPath($path) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        if (self::isMediaPath($path) || ($kind === 'image' && $this->looksLikeFile($path))) {
            $canonical = $this->registerMedia($absolute, '', '', false, true);
            $file = $this->mediaFiles[$canonical];
            if ($this->final && empty($this->mediaReady[$canonical])) {
                // A file the old site does not have either is not worth a broken reference; one that could not be fetched this time stays pointing at the old site.
                return !empty($this->mediaGone[$canonical]) ? '' : $absolute;
            }
            return $this->mediaPath($file);
        }
        $key = trim(rawurldecode($path), '/');
        if ($key === '' || $key === 'index.php' || $key === '..') {
            return '/' . $suffix;
        }
        if (isset($this->linkMap[$key])) {
            return '/' . $this->linkMap[$key] . $suffix;
        }
        $this->unresolved['/' . $key . '/'] = ($this->unresolved['/' . $key . '/'] ?? 0) + 1;
        return '/' . $key . '/' . $suffix;
    }

    private function sameSite(string $absolute): bool
    {
        $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
        $mine = strtolower((string)parse_url($this->reader->site(), PHP_URL_HOST));
        return $host !== '' && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $mine);
    }

    private static function isMediaPath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MEDIA_EXTENSIONS, true);
    }

    private function looksLikeFile(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) !== '';
    }

    /**
     * Notes a file as one to bring over, and gives the address that stands for it: sizes WordPress made of a picture
     * (photo-300x200.jpg) are the picture itself.
     */
    private function registerMedia(string $url, string $alt, string $title, bool $attachment, bool $used = false): string
    {
        $original = strtok($url, '?#') ?: $url;
        $canonical = (string)preg_replace('/-(?:\d{2,5}x\d{2,5}|scaled)(\.[A-Za-z0-9]+)$/', '$1', $original);
        $path = (string)parse_url($canonical, PHP_URL_PATH);
        if (!isset($this->mediaFiles[$canonical])) {
            $ext = strtolower((string)preg_replace('/[^a-z0-9]/i', '', pathinfo($path, PATHINFO_EXTENSION)));
            $this->mediaFiles[$canonical] = [
                'id' => substr(sha1($canonical), 0, 16),
                'ext' => $ext,
                'name' => rawurldecode(basename($path)),
                'alt' => $alt,
                'uses' => 0,
                'attachment' => $attachment,
                'original' => $original,
                'seen' => [],
            ];
        }
        $file = &$this->mediaFiles[$canonical];
        if ($file['alt'] === '' && $alt !== '') {
            $file['alt'] = $alt;
        }
        if ($attachment) {
            $file['attachment'] = true;
        }
        if ($used) {
            $file['uses']++;
        }
        $seenPath = rawurldecode((string)parse_url($original, PHP_URL_PATH));
        if ($seenPath !== '' && !in_array($seenPath, $file['seen'], true)) {
            $file['seen'][] = $seenPath;
        }
        unset($file);
        return $canonical;
    }

    /** @param array{id: string, ext: string} $file */
    private function mediaPath(array $file): string
    {
        return '/uploads/media/' . $file['id'] . ($file['ext'] !== '' ? '.' . $file['ext'] : '');
    }

    /** The path of an address on the old site, with no slash at either end. */
    private static function pathOf(string $address): string
    {
        $path = (string)parse_url($address, PHP_URL_PATH);
        return trim(rawurldecode($path), '/');
    }

    /**
     * @param array<int, array{0: string, 1: string}> $redirects
     */
    private function addRedirect(array &$redirects, string $oldAddress, string $new): void
    {
        $old = self::pathOf($oldAddress);
        if (str_contains($oldAddress, '://')) {
            $old = '/' . $old;
        } elseif ($old !== '') {
            $old = '/' . ltrim($old, '/');
        }
        if ($old === '/' || $old === '' || rtrim($old, '/') === rtrim($new, '/')) {
            return;
        }
        foreach ($redirects as [$from]) {
            if ($from === $old) {
                return;
            }
        }
        $redirects[] = [$old, $new];
    }
}
