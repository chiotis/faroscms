<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * The other languages of a page: the links of the language switcher for an entry, and the alternate addresses
 * (`hreflang`) of an entry, a content type's list, and a category or tag page. A language only appears where the
 * page really exists in it.
 */
final class LanguageAlternates
{
    /**
     * @param callable(): array<string, mixed> $settings the site settings, read each time
     * @param callable(): Taxonomies $taxonomies
     * @param callable(string): string $absolute the full address of a path of this site
     */
    public function __construct(
        private ContentRepository $content,
        private $taxonomies,
        private $settings,
        private $absolute
    ) {
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ($this->settings)();
    }

    private function absolute(string $path): string
    {
        return ($this->absolute)($path);
    }

    /** The path of a content type's list in a language. */
    private static function archivePath(string $type, string $lang, string $defaultLang): string
    {
        return ($lang === $defaultLang ? '' : $lang . '/') . $type;
    }

    /** @return array<string, string> */
    public function languageLinks(string $type, ContentItem $item): array
    {
        $available = $this->settings()['languages']['available'] ?? [];
        $defaultLang = $this->settings()['languages']['default'] ?? 'en';
        $homeSlug = $this->settings()['home_page'] ?? 'index';
        if ($homeSlug === '') {
            $homeSlug = 'index';
        }

        $translationId = (string)($item->meta['translation_id'] ?? '');
        $all = $this->content->getItems($type, null, true, false);
        $matches = [];
        foreach ($all as $candidate) {
            if ($translationId !== '' && (string)($candidate->meta['translation_id'] ?? '') === $translationId) {
                $matches[$candidate->lang] = $candidate;
            }
        }

        $links = [];
        foreach ($available as $lang) {
            $target = $matches[$lang] ?? null;
            if ($target === null && $translationId === '') {
                foreach ($all as $candidate) {
                    if ($candidate->lang === $lang && $candidate->slug === $item->slug) {
                        $target = $candidate;
                        break;
                    }
                }
            }
            if ($target) {
                $path = ContentPaths::build($type, $target->slug, $lang, $homeSlug, $defaultLang);
                $links[$lang] = $path;
            }
        }

        return $links;
    }

    /** @return array{urls: array<string, string>, default: string} */
    public function forItem(string $type, string $slug): array
    {
        $defaultLang = $this->settings()['languages']['default'] ?? 'en';
        $available = $this->settings()['languages']['available'] ?? [$defaultLang];
        $homeSlug = $this->settings()['home_page'] ?? 'index';
        if ($homeSlug === '') {
            $homeSlug = 'index';
        }

        $urls = [];
        foreach ($available as $lang) {
            $item = $this->content->find($type, $slug, $lang, false);
            if (!$item) {
                continue;
            }
            $path = ContentPaths::build($type, $slug, $lang, $homeSlug, $defaultLang);
            $urls[$lang] = $this->absolute($path);
        }

        return [
            'urls' => $urls,
            'default' => $urls[$defaultLang] ?? '',
        ];
    }

    /** @return array{urls: array<string, string>, default: string} */
    public function forArchive(string $type): array
    {
        $defaultLang = $this->settings()['languages']['default'] ?? 'en';
        $available = $this->settings()['languages']['available'] ?? [$defaultLang];

        $urls = [];
        foreach ($available as $lang) {
            $items = $this->content->getItems($type, $lang, false);
            if (empty($items)) {
                continue;
            }
            $path = self::archivePath($type, $lang, $defaultLang);
            $urls[$lang] = $this->absolute($path);
        }

        return [
            'urls' => $urls,
            'default' => $urls[$defaultLang] ?? '',
        ];
    }

    /** @return array{urls: array<string, string>, default: string} */
    public function forTaxonomy(string $kind, string $slug): array
    {
        $defaultLang = $this->settings()['languages']['default'] ?? 'en';
        $available = $this->settings()['languages']['available'] ?? [$defaultLang];
        $slug = Slug::plain($slug);
        $pathKind = $kind === 'category' ? 'category' : 'tag';
        $key = $kind === 'category' ? 'categories' : 'tags';

        $urls = [];
        foreach ($available as $lang) {
            if (!$this->hasTaxonomyItems($key, $slug, $lang)) {
                continue;
            }
            $prefix = $lang === $defaultLang ? '' : $lang . '/';
            $path = $prefix . $pathKind . '/' . $slug;
            $urls[$lang] = $this->absolute($path);
        }

        return [
            'urls' => $urls,
            'default' => $urls[$defaultLang] ?? '',
        ];
    }

    private function hasTaxonomyItems(string $key, string $slug, string $lang): bool
    {
        $term = ($this->taxonomies)()->findBySlug($key, $slug);
        if ($term === null) {
            return false;
        }
        $termId = (string)($term['id'] ?? '');
        if ($termId === '') {
            return false;
        }
        foreach ($this->content->getTypes() as $type) {
            if ($type === 'pages') {
                continue;
            }
            foreach ($this->content->getItems($type, $lang, false) as $item) {
                $values = Format::list($item->meta[$key] ?? null);
                if (in_array($termId, $values, true)) {
                    return true;
                }
            }
        }
        return false;
    }
}
