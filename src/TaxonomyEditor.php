<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * What the Taxonomies screen does with a submitted form: the terms and the page layout are checked and saved, a term
 * whose address changed leaves the old one behind as a redirect (in every language) and its links in menus are
 * updated, and the entries filed under each term are counted.
 */
final class TaxonomyEditor
{
    /** Types whose entries are never filed under a term. */
    private const UNFILED_TYPES = ['pages', 'forms'];

    /** @param callable(): Menus $menus */
    public function __construct(
        private Taxonomies $store,
        private RedirectRepository $redirects,
        private ContentRepository $content,
        private $menus
    ) {
    }

    /**
     * How many entries (any type, any language, drafts too) are filed under each term, for each taxonomy, counted in
     * one pass.
     *
     * @param string[] $taxonomies
     * @return ($byType is true ? array<string, array<string, array<string, int>>> : array<string, array<string, int>>) taxonomy => term id => entries (or entries per content type)
     */
    public function usage(array $taxonomies, bool $byType = false): array
    {
        $counts = array_fill_keys($taxonomies, []);
        foreach ($this->content->getTypes() as $type) {
            if ($type === 'forms') {
                continue;
            }
            foreach ($this->content->getItems($type, null, true, false) as $item) {
                foreach ($taxonomies as $taxonomy) {
                    foreach (Format::list($item->meta[$taxonomy] ?? null) as $termId) {
                        $counts[$taxonomy][$termId][$type] = ($counts[$taxonomy][$termId][$type] ?? 0) + 1;
                    }
                }
            }
        }
        if ($byType) {
            return $counts;
        }
        return array_map(static fn(array $terms): array => array_map('array_sum', $terms), $counts);
    }

    /** The content types that can be listed on a taxonomy page. @return string[] */
    public function listableTypes(): array
    {
        return array_values(array_filter($this->content->getTypes(), static fn(string $type): bool => !in_array($type, self::UNFILED_TYPES, true)));
    }

    /**
     * The terms of a submitted form, in the order they were sent. A description box that was not sent at all leaves
     * the descriptions of the terms alone (null); one that was sent empty clears them.
     *
     * @param array<string, mixed> $post
     * @param string[] $languages
     * @return array<int, array{id: string, slug: string, labels: array<string, string>, descriptions: array<string, string>|null}>
     */
    public function rowsFromPost(array $post, array $languages): array
    {
        $list = static fn(string $key): array => is_array($post[$key] ?? null) ? $post[$key] : [];
        $ids = $list('term_id');
        $slugs = $list('term_slug');
        $labels = $list('term_label');
        $texts = $list('term_description');
        $rows = [];
        for ($i = 0, $count = max(count($ids), count($slugs)); $i < $count; $i++) {
            $row = ['id' => trim((string)($ids[$i] ?? '')), 'slug' => (string)($slugs[$i] ?? ''), 'labels' => [], 'descriptions' => $texts === [] ? null : []];
            foreach ($languages as $lang) {
                $row['labels'][$lang] = trim((string)($labels[$lang][$i] ?? ''));
                if ($texts !== []) {
                    $row['descriptions'][$lang] = trim((string)($texts[$lang][$i] ?? ''));
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Saves a submitted form for one taxonomy.
     *
     * @param array<string, mixed> $post
     * @param string[] $languages
     * @return array{title: string, terms: int, moved: int, removed: int, added: int, orphaned: int}
     */
    public function apply(string $taxonomy, array $post, array $languages, string $defaultLang, string $by): array
    {
        $current = $this->store->load($taxonomy);
        $names = $this->store->names();
        $listable = $this->listableTypes();
        $title = trim((string)($post['taxonomy_title'] ?? $current['title']));
        $prepared = $this->store->prepare($taxonomy, $this->rowsFromPost($post, $languages), $defaultLang);

        // How the pages of this taxonomy look: only what differs from the defaults is written.
        $submitted = is_array($post['archive'] ?? null) ? $post['archive'] : [];
        $archive = ContentTypes::archiveFromInput(
            $submitted,
            $current['archive'],
            ContentTypes::resolveArchive([], static fn(mixed $v): mixed => $v),
            array_values(array_diff($names, [$taxonomy])),
            false
        );
        $types = array_values(array_intersect($listable, Taxonomies::typeList($submitted['types'] ?? null)));
        if ($types === [] || count($types) === count($listable)) {
            unset($archive['types']);
        } else {
            $archive['types'] = $types;
        }

        $this->store->save($taxonomy, $title, $prepared['terms'], $archive);

        // A term whose address changed leaves the old one behind, in every language, like a page does.
        $kind = Taxonomies::kind($taxonomy);
        foreach ($prepared['moved'] as $move) {
            foreach ($languages as $lang) {
                $prefix = $lang === $defaultLang ? '' : $lang . '/';
                $this->redirects->moved($prefix . $kind . '/' . $move['from'], $prefix . $kind . '/' . $move['to'], $by, $taxonomy);
            }
            ($this->menus)()->relink($kind . '/' . $move['from'], $kind . '/' . $move['to']);
        }
        foreach (array_merge($prepared['added'], array_column($prepared['moved'], 'id')) as $termId) {
            foreach ($languages as $lang) {
                $this->redirects->removeSource(($lang === $defaultLang ? '' : $lang . '/') . $kind . '/' . $this->store->slug($taxonomy, $termId));
            }
        }

        $usage = $this->usage([$taxonomy])[$taxonomy];
        return [
            'title' => $title,
            'terms' => count($prepared['terms']),
            'moved' => count($prepared['moved']),
            'removed' => count($prepared['removed']),
            'added' => count($prepared['added']),
            'orphaned' => array_sum(array_map(static fn(string $id): int => $usage[$id] ?? 0, $prepared['removed'])),
        ];
    }
}
