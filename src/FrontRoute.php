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
     *   kind is one of sitemap, robots, legacy_page, taxonomy, search, archive, entry, page, not_found
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
        // An address names one thing and nothing after it: a page, a list, an entry (type/slug), a term (taxonomy/term), the
        // search. A longer one (/about/anything) is not that thing, so it is a not found rather than a second address for it.
        $count = count($segments);
        $notFound = ['kind' => 'not_found'] + $route;
        if ($first === 'pages') {
            if ($count > 2) {
                return $notFound;
            }
            $slug = $segments[1] ?? '';
            return ['kind' => 'legacy_page', 'location' => '/' . $prefix . ($slug !== '' && $slug !== $homeSlug ? $slug : '')] + $route;
        }
        if (in_array($first, ['tag', 'tags', 'category', 'categories'], true) || ($count > 1 && in_array($first, $taxonomies, true) && !in_array($first, $types, true))) {
            return $count > 2 ? $notFound : ['kind' => 'taxonomy'] + $route;
        }
        if ($first === 'search') {
            return $count > 1 ? $notFound : ['kind' => 'search'] + $route;
        }
        if ($first !== '' && in_array($first, $types, true)) {
            if ($count > 2) {
                return $notFound;
            }
            $slug = $segments[1] ?? '';
            return ['kind' => $slug === '' ? 'archive' : 'entry', 'type' => $first, 'slug' => $slug] + $route;
        }
        if ($count > 1) {
            return $notFound;
        }
        return ['slug' => $first !== '' ? $first : $homeSlug] + $route;
    }
}
