<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * The sitemap search engines read: the address and the last change of every public entry in every language. Entries
 * that are drafts or hidden are left out.
 */
final class Sitemap
{
    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     */
    public function __construct(private ContentRepository $content, private \Closure $settings, private \Closure $absoluteUrl)
    {
    }

    /** @return array<int, array{loc: string, lastmod: string}> */
    public function entries(): array
    {
        $paths = new ContentPaths(($this->settings)());
        $settings = ($this->settings)();
        $default = $paths->defaultLang();
        $available = $settings['languages']['available'] ?? [$default];

        $entries = [];
        foreach ($this->content->getTypes() as $type) {
            foreach ($available as $lang) {
                foreach ($this->content->getItems($type, $lang, false) as $item) {
                    $key = $lang . '|' . $type . '|' . $item->slug;
                    if (isset($entries[$key])) {
                        continue;
                    }
                    $entries[$key] = [
                        'loc' => ($this->absoluteUrl)($paths->publicPath($type, $item->slug, (string)$lang)),
                        'lastmod' => date('c', $item->mtime),
                    ];
                }
            }
        }
        return array_values($entries);
    }

    public function xml(): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($this->entries() as $entry) {
            $xml .= "  <url>\n"
                . '    <loc>' . htmlspecialchars($entry['loc'], ENT_QUOTES) . "</loc>\n"
                . '    <lastmod>' . htmlspecialchars($entry['lastmod'], ENT_QUOTES) . "</lastmod>\n"
                . "  </url>\n";
        }
        return $xml . "</urlset>\n";
    }
}
