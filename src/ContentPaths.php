<?php

declare(strict_types=1);

namespace FarosCMS;

/** Where content lives on disk and on the site: file names and public paths, from the language and home page settings. */
final class ContentPaths
{
    public function __construct(private array $settings)
    {
    }

    public function defaultLang(): string
    {
        return (string)($this->settings['languages']['default'] ?? 'en');
    }

    public function homeSlug(): string
    {
        $home = (string)($this->settings['home_page'] ?? '');
        return $home !== '' ? $home : 'index';
    }

    /** "about.md" in the default language, "about.en.md" in another. */
    public function filename(string $slug, string $lang): string
    {
        if ($lang === $this->defaultLang() || $lang === '') {
            return $slug . '.md';
        }
        return $slug . '.' . $lang . '.md';
    }

    /** The public path (no leading slash) of an item: "about", "en/posts/hello", "" for the home page. */
    public function publicPath(string $type, string $slug, string $lang): string
    {
        return self::build($type, $slug, $lang, $this->homeSlug(), $this->defaultLang());
    }

    public static function build(string $type, string $slug, string $lang, string $homeSlug, string $defaultLang): string
    {
        $prefix = $lang === $defaultLang ? '' : $lang . '/';

        if ($type === 'pages') {
            if ($slug === $homeSlug) {
                return rtrim($prefix, '/');
            }
            return $prefix . $slug;
        }

        return $prefix . $type . '/' . $slug;
    }
}
