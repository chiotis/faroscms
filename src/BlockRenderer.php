<?php

declare(strict_types=1);

namespace FarosCMS;

use Twig\Environment as TwigEnvironment;

/**
 * Renders the `blocks:` list of a page or post.
 *
 * Every block is checked against its definition before it reaches a template: unknown types
 * and hidden blocks are skipped, fields get defaults, and invalid values fall back. Markdown
 * fields also get an `<key>_html` rendering. The first block may be a hero, which then owns the
 * page's <h1>; every other block starts at <h2>.
 *
 * The result carries the stylesheets of the block types in use and structured data that
 * blocks contribute (FAQ), so the layout can place both in <head>.
 */
final class BlockRenderer
{
    /**
     * @param \Closure(string): string $markdown
     * @param array<string, \Closure> $providers dynamic data: 'items' (type, lang, limit) and 'form' (slug)
     */
    public function __construct(
        private BlockRegistry $registry,
        private TwigEnvironment $twig,
        private Theme $theme,
        private \Closure $markdown,
        private array $providers = [],
        private string $baseUrl = ''
    ) {
    }

    /**
     * @param array<int, mixed> $rawBlocks
     * @param array<string, mixed> $context template variables shared with every block (item, lang, lang_prefix, body_html, …)
     * @return array{html: string, types: string[], styles: string[], scripts: string[], structured_data: array<int, array<string, mixed>>, leads_with_hero: bool, opens_with_slider: bool, lead: array{type: string, variant: string, tone: string}|null, count: int, image: string}
     */
    public function render(array $rawBlocks, array $context): array
    {
        $blocks = $this->prepare($rawBlocks, (string)($context['body_html'] ?? ''));
        $html = '';
        $types = [];
        $faq = [];
        $leadsWithHero = false;
        $opensWithSlider = false;
        $firstImage = '';
        $lead = null;

        foreach ($blocks as $index => $entry) {
            [$type, $definition, $values] = $entry;
            $isFirst = $index === 0;
            if ($isFirst && $type === 'hero') {
                $leadsWithHero = true;
            }
            if ($isFirst && self::isOpeningSlider($type, $values)) {
                $opensWithSlider = true;
            }
            if ($isFirst) {
                $lead = ['type' => $type, 'variant' => (string)$values['variant'], 'tone' => (string)$values['tone']];
            }
            $values = $this->withData($type, $definition, $values, $context);
            if (($values['_empty'] ?? false) === true) {
                // A dynamic block with nothing to show (no matching content, no valid video) leaves no empty section behind.
                continue;
            }
            if ($firstImage === '' && is_string($values['image'] ?? null) && $values['image'] !== '') {
                // Share-image fallback for pages without a main image.
                $firstImage = $values['image'];
            }
            $uid = $values['anchor'] !== '' ? $values['anchor'] : 'block-' . ($index + 1);

            $html .= $this->twig->render('components/block.twig', $context + [
                'block' => $values,
                'block_type' => $type,
                'block_uid' => $uid,
                'block_index' => $index,
                'block_first' => $isFirst,
                // The hero that opens the page is the page title; everything else is a section.
                'heading_tag' => $isFirst && $type === 'hero' ? 'h1' : 'h2',
                // Items sit one level below their block's heading; a block without a heading has none to sit under.
                'item_heading_tag' => $isFirst && $type === 'hero' ? 'h2' : (trim((string)($values['heading'] ?? '')) !== '' ? 'h3' : 'h2'),
                'block_labelled' => trim((string)($values['heading'] ?? '')) !== '',
            ]);
            $types[$type] = true;

            if ($type === 'faq' && ($values['schema'] ?? true) === true) {
                foreach ($values['items'] as $question) {
                    $name = trim((string)($question['question'] ?? ''));
                    $answer = trim(strip_tags((string)($question['answer_html'] ?? '')));
                    if ($name !== '' && $answer !== '') {
                        $faq[] = [
                            '@type' => 'Question',
                            'name' => $name,
                            'acceptedAnswer' => ['@type' => 'Answer', 'text' => preg_replace('/\s+/', ' ', $answer)],
                        ];
                    }
                }
            }
        }

        $stylesheet = $this->theme->blockStylesheetUrl($this->baseUrl, array_keys($types));
        $styles = $stylesheet !== '' ? [$stylesheet] : [];
        $script = $this->theme->blockScriptUrl($this->baseUrl, array_keys($types));
        $structuredData = [];
        if ($faq !== []) {
            $structuredData[] = ['@type' => 'FAQPage', 'mainEntity' => $faq];
        }

        return [
            'html' => $html,
            'types' => array_keys($types),
            'styles' => $styles,
            'scripts' => $script !== '' ? [$script] : [],
            'structured_data' => $structuredData,
            'leads_with_hero' => $leadsWithHero,
            'opens_with_slider' => $opensWithSlider,
            'lead' => $lead,
            'count' => count($blocks),
            'image' => $firstImage,
        ];
    }

    /**
     * A slider that runs edge to edge opens the page the way a hero does: the header can sit over it, it has no title area
     * above it, and the text of the page comes after it.
     *
     * @param array<string, mixed> $values
     */
    private static function isOpeningSlider(string $type, array $values): bool
    {
        return $type === 'slider' && ($values['width'] ?? '') === 'full' && in_array($values['variant'] ?? '', ['full', 'banner'], true);
    }

    /**
     * Valid, visible blocks with resolved values. When the page body has text and no `content`
     * block places it, it is shown right after an opening hero or slider (or first).
     *
     * @param array<int, mixed> $rawBlocks
     * @return array<int, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    private function prepare(array $rawBlocks, string $bodyHtml): array
    {
        $prepared = [];
        $hasContent = false;
        foreach ($rawBlocks as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $type = (string)($raw['type'] ?? '');
            $definition = $this->registry->get($type);
            if ($definition === null) {
                continue;
            }
            $values = FieldSchema::resolve($definition['common'], array_intersect_key($raw, $definition['common']));
            if ($values['hidden'] === true) {
                continue;
            }
            $values['anchor'] = trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)$values['anchor'])), '-');
            $values += FieldSchema::resolve($definition['fields'], array_intersect_key($raw, $definition['fields']));
            $prepared[] = [$type, $definition, $values];
            $hasContent = $hasContent || $type === 'content';
        }

        $content = $this->registry->get('content');
        if (!$hasContent && $content !== null && trim(strip_tags($bodyHtml, '<img><iframe><video>')) !== '') {
            $values = FieldSchema::resolve($content['common'], []) + FieldSchema::resolve($content['fields'], []);
            $position = ($prepared[0][0] ?? '') === 'hero' || self::isOpeningSlider((string)($prepared[0][0] ?? ''), $prepared[0][2] ?? []) ? 1 : 0;
            array_splice($prepared, $position, 0, [['content', $content, $values]]);
        }
        return $prepared;
    }

    /**
     * Markdown renderings and dynamic data for a block.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function withData(string $type, array $definition, array $values, array $context): array
    {
        foreach ($definition['fields'] as $key => $field) {
            if ($field['type'] === 'markdown') {
                $values[$key . '_html'] = $this->markdownHtml((string)$values[$key]);
            }
            if ($field['type'] === 'repeater') {
                foreach ($values[$key] as $i => $row) {
                    foreach ($field['fields'] as $subKey => $subField) {
                        if ($subField['type'] === 'markdown') {
                            $values[$key][$i][$subKey . '_html'] = $this->markdownHtml((string)$row[$subKey]);
                        }
                    }
                }
            }
        }

        if ($type === 'content') {
            $values['body_html'] = (string)($context['body_html'] ?? '');
        }
        if ($type === 'cards' && ($values['source'] ?? 'manual') !== 'manual' && isset($this->providers['items'])) {
            $values['entries'] = ($this->providers['items'])((string)$values['source'], (string)($context['lang'] ?? ''), (int)($values['limit'] ?? 3));
        }
        if ($type === 'latest') {
            $values['entries'] = isset($this->providers['items'])
                ? ($this->providers['items'])((string)$values['source'], (string)($context['lang'] ?? ''), (int)$values['limit'], (string)$values['term'])
                : [];
            $values['_empty'] = $values['entries'] === [];
        }
        if ($type === 'video') {
            $videos = [];
            foreach ($values['videos'] as $video) {
                $info = self::videoInfo((string)$video['url']);
                if ($info !== null) {
                    $videos[] = $info + $video;
                }
            }
            $values['videos'] = $videos;
            $values['_empty'] = $videos === [];
        }
        if ($type === 'banner') {
            // A changed announcement gets a new key, so a visitor who dismissed the old one sees it again.
            $values['key'] = substr(sha1($values['title'] . '|' . $values['text'] . '|' . $values['url']), 0, 10);
        }
        if ($type === 'slider') {
            $values['items'] = array_values(array_filter($values['items'], static fn(array $slide): bool => $slide['image'] !== '' || $slide['title'] !== '' || $slide['text'] !== ''));
            $values['_empty'] = $values['items'] === [];
        }
        if (($type === 'form' || $type === 'contact') && isset($this->providers['form'])) {
            $values['form_html'] = ($this->providers['form'])((string)($values['form'] ?? ''));
        }
        if ($type === 'map') {
            $values += self::mapUrls($values['lat'] ?? '', $values['lng'] ?? '', (int)($values['zoom'] ?? 15));
        }
        return $values;
    }

    /**
     * Playback details for a video link, or null when it is not a supported source.
     *
     * YouTube and Vimeo are embedded through their privacy-friendly hosts (youtube-nocookie.com,
     * Vimeo's do-not-track player); a direct .mp4, .webm, .ogv, or .m4v file plays in a plain
     * <video>. Anything else is refused, so a stored URL can never become an arbitrary iframe.
     *
     * @return array{provider: string, embed_url: string, watch_url: string}|null
     */
    public static function videoInfo(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || !FieldSchema::isSafeUrl($url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = preg_replace('/^(www\.|m\.)/', '', $host) ?? $host;
        $path = (string)($parts['path'] ?? '');
        parse_str((string)($parts['query'] ?? ''), $query);

        $youtubeId = '';
        if (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                $youtubeId = (string)($query['v'] ?? '');
            } elseif (preg_match('#^/(embed|shorts|live|v)/([A-Za-z0-9_-]{11})#', $path, $m)) {
                $youtubeId = $m[2];
            }
        } elseif ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})#', $path, $m)) {
            $youtubeId = $m[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $youtubeId)) {
            $start = isset($query['t']) && preg_match('/^(\d+)s?$/', (string)$query['t'], $t) ? '&start=' . (int)$t[1] : '';
            return [
                'provider' => 'youtube',
                'embed_url' => 'https://www.youtube-nocookie.com/embed/' . $youtubeId . '?rel=0&playsinline=1' . $start,
                'watch_url' => 'https://www.youtube.com/watch?v=' . $youtubeId,
            ];
        }

        if (($host === 'vimeo.com' || $host === 'player.vimeo.com') && preg_match('#^/(?:video/)?(\d{5,12})(?:/([a-f0-9]{6,20}))?#', $path, $m)) {
            $hash = ($m[2] ?? '') !== '' ? '&h=' . $m[2] : '';
            return [
                'provider' => 'vimeo',
                'embed_url' => 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1' . $hash,
                'watch_url' => 'https://vimeo.com/' . $m[1] . (($m[2] ?? '') !== '' ? '/' . $m[2] : ''),
            ];
        }
        // A direct video file, on this site or elsewhere.
        if (preg_match('/\.(mp4|webm|ogv|m4v)$/i', $path)) {
            return ['provider' => 'file', 'embed_url' => $url, 'watch_url' => $url];
        }
        return null;
    }

    /**
     * OpenStreetMap embed and link URLs for a point. No API key; the embed shows a marker.
     *
     * @return array{embed_url: string, link_url: string, directions_url: string}
     */
    public static function mapUrls(mixed $lat, mixed $lng, int $zoom): array
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return ['embed_url' => '', 'link_url' => '', 'directions_url' => ''];
        }
        $lat = max(-85.0, min(85.0, (float)$lat));
        $lng = max(-180.0, min(180.0, (float)$lng));
        $zoom = max(3, min(19, $zoom));
        // About 1150 × 520 px of 256 px tiles around the point.
        $lonSpan = 360 / (2 ** $zoom) * 4.5;
        $latSpan = $lonSpan * cos(deg2rad($lat)) * 0.45;
        $format = static fn(float $n): string => rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');
        $bbox = implode(',', array_map($format, [$lng - $lonSpan / 2, $lat - $latSpan / 2, $lng + $lonSpan / 2, $lat + $latSpan / 2]));
        $point = $format($lat) . ',' . $format($lng);
        return [
            'embed_url' => 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&layer=mapnik&marker=' . $point,
            'link_url' => 'https://www.openstreetmap.org/?mlat=' . $format($lat) . '&mlon=' . $format($lng) . '#map=' . $zoom . '/' . $format($lat) . '/' . $format($lng),
            'directions_url' => 'https://www.openstreetmap.org/directions?route=%3B' . rawurlencode($point),
        ];
    }

    private function markdownHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        return $markdown === '' ? '' : ($this->markdown)($markdown);
    }
}
