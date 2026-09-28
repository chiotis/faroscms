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
     * @return array{html: string, types: string[], styles: string[], structured_data: array<int, array<string, mixed>>, leads_with_hero: bool, count: int, image: string}
     */
    public function render(array $rawBlocks, array $context): array
    {
        $blocks = $this->prepare($rawBlocks, (string)($context['body_html'] ?? ''));
        $html = '';
        $types = [];
        $faq = [];
        $leadsWithHero = false;
        $firstImage = '';

        foreach ($blocks as $index => $entry) {
            [$type, $definition, $values] = $entry;
            $isFirst = $index === 0;
            if ($isFirst && $type === 'hero') {
                $leadsWithHero = true;
            }
            $values = $this->withData($type, $definition, $values, $context);
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
                'item_heading_tag' => $isFirst && $type === 'hero' ? 'h2' : 'h3',
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
        $structuredData = [];
        if ($faq !== []) {
            $structuredData[] = ['@type' => 'FAQPage', 'mainEntity' => $faq];
        }

        return [
            'html' => $html,
            'types' => array_keys($types),
            'styles' => $styles,
            'structured_data' => $structuredData,
            'leads_with_hero' => $leadsWithHero,
            'count' => count($blocks),
            'image' => $firstImage,
        ];
    }

    /**
     * Valid, visible blocks with resolved values. When the page body has text and no `content`
     * block places it, it is shown right after an opening hero (or first).
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
            $position = ($prepared[0][0] ?? '') === 'hero' ? 1 : 0;
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
        if ($type === 'form' && isset($this->providers['form'])) {
            $values['form_html'] = ($this->providers['form'])((string)($values['form'] ?? ''));
        }
        return $values;
    }

    private function markdownHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        return $markdown === '' ? '' : ($this->markdown)($markdown);
    }
}
