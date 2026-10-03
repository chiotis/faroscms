<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * Which kind of public page an address asks for: the sitemap, robots.txt, an old /pages/... address (sent to the
 * address it became), a category or tag, the search, the list of a content type, one entry of a type, or a page. The
 * language is read from the first part of the address. This only decides; finding the entry and drawing the page is
 * the caller's.
 */
final class FrontRoute
{
    /** The part of an address that names a language: "" for the default language, "en/" for another. */
    public static function langPrefix(string $lang, string $defaultLang): string
    {
        return $lang === $defaultLang ? '' : $lang . '/';
    }

    /**
     * @param array<string, mixed> $settings
     * @param string[] $types the content types of the site
     * @param string[] $taxonomies the taxonomies a site added: each has pages of its own at /<name>/<term>
     * @return array{kind: string, lang: string, segments: string[], lang_prefix: string, path_no_lang: string, home_slug: string, slug: string, type: string, location: string}
     *   kind is one of sitemap, robots, legacy_page, taxonomy, search, archive, entry, page
     */
    public static function resolve(string $path, array $settings, array $types, array $taxonomies = []): array
    {
        $default = (string)($settings['languages']['default'] ?? 'en');
        $available = $settings['languages']['available'] ?? [];
        $homeSlug = (string)($settings['home_page'] ?? 'index');
        $segments = $path === '' ? [] : explode('/', $path);
        $lang = $default;
        if (isset($segments[0]) && in_array($segments[0], $available, true)) {
            $lang = array_shift($segments);
        }
        $prefix = self::langPrefix($lang, $default);
        $pathNoLang = implode('/', $segments);
        $route = [
            'kind' => 'page',
            'lang' => $lang,
            'segments' => $segments,
            'lang_prefix' => $prefix,
            'path_no_lang' => $pathNoLang === $homeSlug ? '' : $pathNoLang,
            'home_slug' => $homeSlug,
            'slug' => '',
            'type' => '',
            'location' => '',
        ];
        $first = $segments[0] ?? '';

        if ($path === 'sitemap.xml' || $path === 'robots.txt') {
            return ['kind' => $path === 'sitemap.xml' ? 'sitemap' : 'robots'] + $route;
        }
        if ($first === 'pages') {
            $slug = $segments[1] ?? '';
            return ['kind' => 'legacy_page', 'location' => '/' . $prefix . ($slug !== '' && $slug !== $homeSlug ? $slug : '')] + $route;
        }
        if (in_array($first, ['tag', 'tags', 'category', 'categories'], true) || (count($segments) > 1 && in_array($first, $taxonomies, true) && !in_array($first, $types, true))) {
            return ['kind' => 'taxonomy'] + $route;
        }
        if ($first === 'search') {
            return ['kind' => 'search'] + $route;
        }
        if ($first !== '' && in_array($first, $types, true)) {
            $slug = $segments[1] ?? '';
            return ['kind' => $slug === '' ? 'archive' : 'entry', 'type' => $first, 'slug' => $slug] + $route;
        }
        return ['slug' => $first !== '' ? $first : $homeSlug] + $route;
    }
}
