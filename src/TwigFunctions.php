<?php

declare(strict_types=1);

namespace FarosCMS;

use Twig\Environment;
use Twig\TwigFunction;

/**
 * The template functions that depend on nothing about the request: addresses of assets and pages, pictures and
 * icons, links from blocks, the table of contents, and the structured data script. Functions that depend on the
 * language being shown, the signed-in person or the form token are added by the site itself.
 */
final class TwigFunctions
{
    /**
     * @param \Closure(string): string $absoluteUrl the full address of a public path
     * @param \Closure(): StructuredData $structuredData
     */
    public static function register(Environment $twig, string $baseUrl, Theme $theme, Images $images, \Closure $absoluteUrl, \Closure $structuredData): void
    {
        $baseUrl = rtrim($baseUrl, '/');
        $add = static function (string $name, callable $function, bool $html = false) use ($twig): void {
            $twig->addFunction(new TwigFunction($name, $function, $html ? ['is_safe' => ['html']] : []));
        };

        $add('asset', static fn(string $path): string => $baseUrl . '/assets/' . ltrim($path, '/'));
        $add('theme_asset', static fn(string $path): string => $theme->assetUrl($baseUrl, $path));
        $add('custom_asset', static fn(string $path): string => $theme->customAssetUrl($baseUrl, $path));
        $add('admin_asset', static fn(string $path): string => $baseUrl . '/admin-assets/' . ltrim($path, '/'));
        $add('url', static fn(string $path = ''): string => $baseUrl . '/' . ltrim($path, '/'));
        $add('image', static fn(string $src, array $options = []): string => $images->render($src, $options), true);
        $add('icon', static fn(string $name, string $class = ''): string => $theme->icon($name, $class), true);

        // The words of a piece of HTML, for a card's summary when an entry has no excerpt: no tags, no Markdown marks
        // (the HTML is what the Markdown became), entities as characters, one space between the paragraphs.
        $add('plain_text', static function (mixed $html): string {
            $html = mb_substr((string)$html, 0, 6000);
            $html = preg_replace('#</(?:p|h[1-6]|li|div|tr|td|th|blockquote|figcaption|dd|dt)>|<br\s*/?>#i', '$0 ', $html) ?? $html;
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text);
        });

        // The icon set as JSON ({name: svg}) for the admin's icon picker, one library for every place an icon is chosen.
        $add('icon_library_json', static function () use ($theme): string {
            $library = [];
            foreach ($theme->iconNames() as $name) {
                $svg = $theme->icon($name);
                if ($svg !== '') {
                    $library[$name] = $svg;
                }
            }
            return (string)json_encode($library, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT);
        }, true);

        // Block links: absolute URLs, #anchors, mailto:/tel: as given; "/path" from the site root;
        // a bare "path" is relative to the current language ("contact" → "/en/contact").
        $add('link_url', static function (string $value, string $prefix = '') use ($baseUrl): string {
            $value = trim($value);
            if ($value === '' || !FieldSchema::isSafeLink($value)) {
                return '';
            }
            if (preg_match('#^(https?://|mailto:|tel:|\#)#i', $value)) {
                return $value;
            }
            if (str_starts_with($value, '/')) {
                return $baseUrl . $value;
            }
            return $baseUrl . '/' . $prefix . ltrim($value, '/');
        });
        $add('absolute_url', static fn(string $path): string => preg_match('#^https?://#i', $path) ? $path : $absoluteUrl($path));
        $add('toc', static fn(string $html): array => Toc::build($html));
        $add('json_ld', static fn(array $graph): string => $structuredData()->script($graph), true);
    }
}
