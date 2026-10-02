<?php

declare(strict_types=1);

namespace FarosCMS;

use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ContentRepository
{
    private string $contentDir;
    private MarkdownConverter $markdown;
    private array $settings;

    /** @var array<string, array<string, ContentItem>> */
    private array $cache = [];

    /** @var array<string, string> files whose front matter could not be read, path => what the parser said */
    private array $unreadable = [];

    /** @var (\Closure(string, string, string): void)|null told about each file that cannot be read: type, path, message */
    private ?\Closure $onUnreadable = null;

    /** @var (\Closure(): string[])|null the content types the theme ships, which a site switches on and off */
    private ?\Closure $catalogue;

    /** @param (\Closure(): string[])|null $catalogue */
    public function __construct(string $contentDir, MarkdownConverter $markdown, array $settings, ?\Closure $catalogue = null)
    {
        $this->catalogue = $catalogue;
        $this->contentDir = rtrim($contentDir, '/');
        $this->markdown = $markdown;
        $this->settings = $settings;
    }

    /** Called once for each file whose front matter cannot be read, so the site can tell someone. @param \Closure(string, string, string): void $callback */
    public function onUnreadable(\Closure $callback): void
    {
        $this->onUnreadable = $callback;
    }

    /** @return array<string, string> files that could not be read so far, path => message */
    public function unreadable(): array
    {
        return $this->unreadable;
    }

    /**
     * The content types that are on. A type is on when the site lists it or has a folder for it, unless it was switched off
     * (`content_types_off`; pages and forms cannot be). A type from the theme's catalogue that is not listed needs
     * content in its folder to count, so an empty folder does not switch a prebuilt type on.
     *
     * @return string[]
     */
    public function getTypes(): array
    {
        $off = array_map('strval', is_array($this->settings['content_types_off'] ?? null) ? $this->settings['content_types_off'] : []);
        $catalogue = $this->catalogue !== null ? ($this->catalogue)() : [];
        $listed = array_map('strval', is_array($this->settings['content_types'] ?? null) ? $this->settings['content_types'] : []);
        return array_values(array_filter($this->typesOnDisk(), function (string $type) use ($off, $catalogue, $listed): bool {
            if (!in_array($type, ['pages', 'forms'], true) && in_array($type, $off, true)) {
                return false;
            }
            if (in_array($type, $catalogue, true) && !in_array($type, $listed, true)) {
                return (glob($this->contentDir . '/' . $type . '/*.md') ?: []) !== [];
            }
            return true;
        }));
    }

    /** @return string[] */
    private function typesOnDisk(): array
    {
        $types = [];
        $excluded = ['settings', 'users', 'media', 'menus', 'taxonomies', 'forms-submissions'];
        $configTypes = $this->settings['content_types'] ?? [];
        if (is_array($configTypes)) {
            foreach ($configTypes as $type) {
                if (!is_string($type)) {
                    continue;
                }
                $type = trim($type);
                if ($type === '' || in_array($type, $excluded, true)) {
                    continue;
                }
                if (!in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }
        foreach (scandir($this->contentDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (str_starts_with($entry, '.')) {
                continue;
            }
            $path = $this->contentDir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            if (in_array($entry, $excluded, true)) {
                continue;
            }
            if (!in_array($entry, $types, true)) {
                $types[] = $entry;
            }
        }
        if (empty($configTypes)) {
            sort($types);
        }
        return $types;
    }

    /** @return ContentItem[] */
    public function getItems(string $type, ?string $lang = null, bool $includeHidden = false, bool $fallbackDefault = true): array
    {
        $defaultLang = $this->settings['languages']['default'] ?? null;
        $cacheKey = $type . '|' . ($lang === null ? '_all' : $lang) . '|' . ($fallbackDefault ? 'fb' : 'strict');
        if (isset($this->cache[$cacheKey])) {
            return $this->filterVisibility($this->cache[$cacheKey], $includeHidden);
        }

        $itemsBySlug = [];
        foreach (glob($this->contentDir . '/' . $type . '/*.md') ?: [] as $path) {
            $item = $this->parseFile($type, $path);
            if ($lang === null) {
                $itemsBySlug[$item->slug . '|' . $item->lang] = $item;
                continue;
            }

            if ($item->lang === $lang) {
                $itemsBySlug[$item->slug] = $item;
                continue;
            }

            if ($fallbackDefault && !isset($itemsBySlug[$item->slug]) && $defaultLang !== null && $item->lang === $defaultLang) {
                $itemsBySlug[$item->slug] = $item;
            }
        }

        $items = array_values($itemsBySlug);
        usort($items, function (ContentItem $a, ContentItem $b): int {
            $dateA = strtotime((string)($a->meta['date'] ?? '')) ?: $a->mtime;
            $dateB = strtotime((string)($b->meta['date'] ?? '')) ?: $b->mtime;
            return $dateB <=> $dateA;
        });

        $this->cache[$cacheKey] = $items;
        return $this->filterVisibility($items, $includeHidden);
    }

    public function find(string $type, string $slug, ?string $lang = null, bool $includeHidden = false, bool $fallbackDefault = true): ?ContentItem
    {
        $items = $this->getItems($type, $lang, $includeHidden, $fallbackDefault);
        foreach ($items as $item) {
            if ($item->slug === $slug) {
                return $item;
            }
        }
        return null;
    }

    /** @return ContentItem[] */
    public function search(string $query, ?string $lang = null, bool $includeHidden = false): array
    {
        $query = trim($this->lowercase($query));
        if ($query === '') {
            return [];
        }

        $results = [];
        foreach ($this->getTypes() as $type) {
            foreach ($this->getItems($type, $lang, $includeHidden) as $item) {
                $tags = $item->meta['tags'] ?? '';
                $categories = $item->meta['categories'] ?? '';
                if (is_array($tags)) {
                    $tags = implode(' ', $tags);
                }
                if (is_array($categories)) {
                    $categories = implode(' ', $categories);
                }
                $haystack = $this->lowercase(
                    ($item->meta['title'] ?? '') . ' ' .
                    $tags . ' ' .
                    $categories . ' ' .
                    $item->markdown
                );
                if (str_contains($haystack, $query)) {
                    $results[] = $item;
                }
            }
        }
        return $results;
    }

    private function lowercase(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }
        return strtolower($value);
    }

    public function parseFile(string $type, string $path): ContentItem
    {
        $raw = (string)file_get_contents($path);
        $meta = [];
        $body = $raw;

        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R(.*)\z/s', $raw, $matches)) {
            $body = $matches[2];
            try {
                $parsed = Yaml::parse($matches[1]) ?: [];
                if (!is_array($parsed)) {
                    throw new ParseException('The front matter is not a list of fields.');
                }
                $meta = $parsed;
            } catch (ParseException $e) {
                // One file with a typo must not take the whole site down: it counts as an unpublished draft (so no visitor
                // sees half a page) that the admin flags, and its text stays in the file for someone to fix.
                $meta = ['status' => 'draft', 'visible' => false, 'front_matter_error' => $e->getMessage()];
                $this->unreadable[$path] = $e->getMessage();
                if ($this->onUnreadable !== null) {
                    ($this->onUnreadable)($type, $path, $e->getMessage());
                }
            }
        }

        $filename = basename($path, '.md');
        $lang = $this->settings['languages']['default'] ?? 'en';
        $slug = $filename;
        if (preg_match('/^(.*)\.([a-z]{2})$/', $filename, $langMatch)) {
            $slug = $langMatch[1];
            $lang = $langMatch[2];
        }

        $meta['title'] = $meta['title'] ?? $this->titleFromSlug($slug);
        $meta['slug'] = $slug;
        $meta['type'] = $type;
        $meta['lang'] = $lang;

        $html = (string)$this->markdown->convert($body);

        return new ContentItem(
            $type,
            $slug,
            $lang,
            $meta,
            $body,
            $html,
            $path,
            (int)filemtime($path)
        );
    }

    private function titleFromSlug(string $slug): string
    {
        return trim(ucwords(str_replace(['-', '_'], ' ', $slug)));
    }

    /** @param ContentItem[] $items */
    private function filterVisibility(array $items, bool $includeHidden): array
    {
        if ($includeHidden) {
            return $items;
        }

        return array_values(array_filter($items, function (ContentItem $item): bool {
            $status = strtolower((string)($item->meta['status'] ?? 'published'));
            $visible = $item->meta['visible'] ?? true;
            return $status === 'published' && $visible !== false;
        }));
    }
}
