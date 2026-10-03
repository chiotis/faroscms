<?php

declare(strict_types=1);

namespace FarosCMS;

use Symfony\Component\Yaml\Yaml;

/**
 * Categories, tags, and any other taxonomy: the terms content can be filed under, and how the page of each taxonomy lists them.
 *
 * A taxonomy is a file in content/taxonomies/<name>.yaml:
 *   title:    what the admin calls it
 *   terms:    id (what content refers to, never changes), slug (the address, may change), labels per language, and
 *             an optional description per language (kept only when one is written)
 *   archive:  only the layout choices that differ from the defaults, the same ones a content type has
 *             (layout, columns, per_page, order, show_*, title, subtitle, taxonomies), plus `types`: the
 *             content types listed on its pages (none chosen means all of them).
 */
final class Taxonomies
{
    /** @var array<string, array{title: string, terms: array<int, array{id: string, slug: string, labels: array<string, string>, descriptions: array<string, string>}>, archive: array<string, mixed>}> */
    private array $cache = [];

    /** @param string[] $languages every language of the site */
    public function __construct(private string $contentDir, private array $languages)
    {
    }

    private function dir(): string
    {
        return $this->contentDir . '/taxonomies';
    }

    /**
     * The word in the address of a taxonomy's pages: "category" for categories (/category/news), "tag" for tags, and the
     * name of any other taxonomy as it is (/project-types/web).
     */
    public static function kind(string $name): string
    {
        return match ($name) {
            'categories' => 'category',
            'tags' => 'tag',
            default => $name,
        };
    }

    /** The taxonomy an address word belongs to (category, categories, tag, tags, or the name of another), or null. */
    public function fromWord(string $word): ?string
    {
        return match ($word) {
            'category', 'categories' => 'categories',
            'tag', 'tags' => 'tags',
            default => in_array($word, $this->names(), true) ? $word : null,
        };
    }

    /** True for a taxonomy a site added (not categories or tags). */
    public static function isCustom(string $name): bool
    {
        return !in_array($name, ['categories', 'tags'], true);
    }

    /** @return string[] */
    public function names(): array
    {
        $names = ['tags', 'categories'];
        foreach (glob($this->dir() . '/*.yaml') ?: [] as $path) {
            $name = Slug::plain(basename($path, '.yaml'));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /** Writes the two built-in taxonomies with a few example terms when a site has none yet. */
    public function ensureDefaults(): void
    {
        $defaults = [
            'tags' => [
                'title' => 'Tags',
                'terms' => [
                    ['id' => 'news', 'slug' => 'news', 'labels' => ['el' => 'Νέα', 'en' => 'News']],
                    ['id' => 'design', 'slug' => 'design', 'labels' => ['el' => 'Σχεδιασμός', 'en' => 'Design']],
                ],
            ],
            'categories' => [
                'title' => 'Categories',
                'terms' => [
                    ['id' => 'announcements', 'slug' => 'announcements', 'labels' => ['el' => 'Ανακοινώσεις', 'en' => 'Announcements']],
                    ['id' => 'insights', 'slug' => 'insights', 'labels' => ['el' => 'Ιδέες', 'en' => 'Insights']],
                ],
            ],
        ];
        if (!is_dir($this->dir())) {
            mkdir($this->dir(), 0775, true);
        }
        foreach ($defaults as $name => $payload) {
            if (!is_file($this->dir() . '/' . $name . '.yaml')) {
                $this->save($name, $payload['title'], $payload['terms']);
            }
        }
    }

    /** @return array{title: string, terms: array<int, array{id: string, slug: string, labels: array<string, string>, descriptions: array<string, string>}>, archive: array<string, mixed>} */
    public function load(string $name): array
    {
        $name = Slug::plain($name) ?: 'tags';
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }
        $data = [];
        $path = $this->dir() . '/' . $name . '.yaml';
        if (is_file($path)) {
            try {
                $parsed = Yaml::parseFile($path);
            } catch (\Throwable) {
                $parsed = [];
            }
            $data = is_array($parsed) ? $parsed : [];
        }
        $title = trim((string)($data['title'] ?? ''));
        return $this->cache[$name] = [
            'title' => $title !== '' ? $title : Slug::title($name),
            'terms' => $this->normalizeTerms($data['terms'] ?? []),
            'archive' => is_array($data['archive'] ?? null) ? $data['archive'] : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     * @param array<string, mixed>|null $archive the layout choices to keep; null leaves what the file holds
     */
    public function save(string $name, string $title, array $terms, ?array $archive = null): void
    {
        $name = Slug::plain($name);
        if ($name === '') {
            return;
        }
        if (!is_dir($this->dir())) {
            mkdir($this->dir(), 0775, true);
        }
        $payload = [
            'title' => trim($title) !== '' ? trim($title) : Slug::title($name),
            'terms' => array_map(static function (array $term): array {
                // A description is written only when there is one, so files stay as short as they were.
                if (implode('', $term['descriptions']) === '') {
                    unset($term['descriptions']);
                }
                return $term;
            }, $this->normalizeTerms($terms)),
        ];
        $archive ??= $this->load($name)['archive'];
        if ($archive !== []) {
            $payload['archive'] = $archive;
        }
        file_put_contents($this->dir() . '/' . $name . '.yaml', Yaml::dump($payload, 6, 2));
        $this->cache = [];
    }

    /** Removes a taxonomy the site added (never categories or tags). True when its file is gone. */
    public function delete(string $name): bool
    {
        $name = Slug::plain($name);
        if ($name === '' || !self::isCustom($name)) {
            return false;
        }
        $path = $this->dir() . '/' . $name . '.yaml';
        $gone = !is_file($path) || @unlink($path);
        $this->cache = [];
        return $gone;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** @return array<int, array{id: string, slug: string, labels: array<string, string>, descriptions: array<string, string>}> */
    private function normalizeTerms(mixed $terms): array
    {
        if (!is_array($terms)) {
            return [];
        }
        $rows = [];
        $seen = [];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $id = Slug::plain((string)($term['id'] ?? ''));
            $slug = Slug::plain((string)($term['slug'] ?? ''));
            $id = $id !== '' ? $id : $slug;
            $slug = $slug !== '' ? $slug : $id;
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $source = is_array($term['labels'] ?? null) ? $term['labels'] : [];
            $texts = is_array($term['descriptions'] ?? null) ? $term['descriptions'] : [];
            $labels = [];
            $descriptions = [];
            foreach ($this->languages as $lang) {
                $labels[(string)$lang] = trim((string)($source[(string)$lang] ?? ''));
                $descriptions[(string)$lang] = trim(str_replace("\r\n", "\n", (string)($texts[(string)$lang] ?? '')));
            }
            $rows[] = ['id' => $id, 'slug' => $slug, 'labels' => $labels, 'descriptions' => $descriptions];
            $seen[$id] = true;
        }
        return $rows;
    }

    /** @return array{id: string, slug: string, labels: array<string, string>, descriptions: array<string, string>}|null */
    public function findBySlug(string $name, string $slug): ?array
    {
        $slug = Slug::plain($slug);
        if ($slug === '') {
            return null;
        }
        foreach ($this->load($name)['terms'] as $term) {
            if ($term['slug'] === $slug) {
                return $term;
            }
        }
        return null;
    }

    public function label(string $name, string $termId, string $lang): string
    {
        $termId = Slug::plain($termId);
        if ($termId === '') {
            return '';
        }
        foreach ($this->load($name)['terms'] as $term) {
            if ($term['id'] !== $termId) {
                continue;
            }
            $label = trim((string)($term['labels'][$lang] ?? ''));
            if ($label !== '') {
                return $label;
            }
            foreach ($term['labels'] as $candidate) {
                if (trim((string)$candidate) !== '') {
                    return trim((string)$candidate);
                }
            }
            break;
        }
        return Slug::title($termId);
    }

    /** The description of a term in a language, or in the default language when this one has none; empty when there is none. */
    public function description(string $name, string $termId, string $lang, string $defaultLang): string
    {
        $termId = Slug::plain($termId);
        foreach ($this->load($name)['terms'] as $term) {
            if ($term['id'] === $termId) {
                $text = (string)($term['descriptions'][$lang] ?? '');
                return $text !== '' ? $text : (string)($term['descriptions'][$defaultLang] ?? '');
            }
        }
        return '';
    }

    public function slug(string $name, string $termId): string
    {
        $termId = Slug::plain($termId);
        if ($termId === '') {
            return '';
        }
        foreach ($this->load($name)['terms'] as $term) {
            if ($term['id'] === $termId) {
                return $term['slug'] !== '' ? $term['slug'] : $termId;
            }
        }
        return $termId;
    }

    /**
     * The archive settings of a taxonomy, with defaults for everything its file leaves out.
     *
     * @return array<string, mixed> the same keys as a content type's archive, and `types`
     */
    public function archive(string $name, string $lang, string $defaultLang): array
    {
        $raw = $this->load($name)['archive'];
        $values = ContentTypes::resolveArchive($raw, static fn(mixed $v): mixed => ContentTypes::pick($v, $lang, $defaultLang));
        $values['types'] = self::typeList($raw['types'] ?? null);
        return $values;
    }

    /** @return string[] */
    public static function typeList(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', is_array($value) ? $value : []),
            static fn(string $t): bool => (bool)preg_match('/^[a-z][a-z0-9_-]*$/', $t)
        )));
    }

    /**
     * What to save for the terms of a submitted form. The address of a new term is made from its label (Greek
     * converted to Latin) unless one was typed; a term whose address field is empty keeps the one it has.
     *
     * @param string $name the taxonomy
     * @param array<int, array{id: string, slug: string, labels: array<string, string>, descriptions?: array<string, string>|null}> $rows in form order, `id` empty for a new term; `descriptions` left out (or null) keeps what the term has
     * @return array{terms: array<int, array{id: string, slug: string, labels: array<string, string>, descriptions: array<string, string>}>, moved: array<int, array{id: string, from: string, to: string}>, removed: string[], added: string[]}
     */
    public function prepare(string $name, array $rows, string $defaultLang): array
    {
        $before = [];
        foreach ($this->load($name)['terms'] as $term) {
            $before[$term['id']] = $term;
        }
        $terms = [];
        $usedIds = [];
        $usedSlugs = [];
        $moved = [];
        $added = [];
        // Terms that already exist claim their id and address first, so a new term never takes one of theirs.
        foreach ($rows as $row) {
            if ($row['id'] !== '' && isset($before[Slug::plain($row['id'])])) {
                $usedIds[Slug::plain($row['id'])] = true;
            }
        }
        foreach ($rows as $row) {
            $id = Slug::plain($row['id']);
            $existing = $id !== '' ? ($before[$id] ?? null) : null;
            if ($existing !== null && in_array($id, array_column($terms, 'id'), true)) {
                continue; // The same term twice.
            }
            $label = $this->firstLabel($row['labels'], $defaultLang);
            $typed = trim($row['slug']);

            if ($existing !== null) {
                $slug = $typed !== '' ? Slug::fromText($typed) : $existing['slug'];
                $slug = $slug !== '' ? $slug : $existing['slug'];
            } else {
                if ($id === '' && $typed === '' && $label === '') {
                    continue; // An empty row.
                }
                $base = $typed !== '' ? $typed : ($label !== '' ? $label : $id);
                $slug = Slug::fromText($base);
                if ($id === '' || isset($usedIds[$id])) {
                    $id = Slug::fromText($label !== '' ? $label : $base);
                }
            }
            if ($slug === '') {
                $slug = 'term';
            }
            if ($existing === null) {
                $id = $this->unique($id !== '' ? $id : $slug, $usedIds);
            }
            $slug = $this->unique($slug, $usedSlugs);
            $usedIds[$id] = true;
            $usedSlugs[$slug] = true;

            if ($existing !== null && $existing['slug'] !== $slug) {
                $moved[] = ['id' => $id, 'from' => $existing['slug'], 'to' => $slug];
            }
            if ($existing === null) {
                $added[] = $id;
            }
            $descriptions = is_array($row['descriptions'] ?? null) ? $row['descriptions'] : ($existing['descriptions'] ?? []);
            $terms[] = ['id' => $id, 'slug' => $slug, 'labels' => $row['labels'], 'descriptions' => $descriptions];
        }
        $kept = array_column($terms, 'id');
        // An address another term still uses is not gone, so nobody is sent away from it.
        $moved = array_values(array_filter($moved, static fn(array $m): bool => !isset($usedSlugs[$m['from']])));
        return ['terms' => $terms, 'moved' => $moved, 'removed' => array_values(array_diff(array_keys($before), $kept)), 'added' => $added];
    }

    /** @param array<string, string> $labels */
    private function firstLabel(array $labels, string $defaultLang): string
    {
        $first = trim((string)($labels[$defaultLang] ?? ''));
        if ($first !== '') {
            return $first;
        }
        foreach ($labels as $label) {
            if (trim((string)$label) !== '') {
                return trim((string)$label);
            }
        }
        return '';
    }

    /** @param array<string, bool> $taken */
    private function unique(string $value, array $taken): string
    {
        if (!isset($taken[$value])) {
            return $value;
        }
        for ($n = 2; $n < 1000; $n++) {
            if (!isset($taken[$value . '-' . $n])) {
                return $value . '-' . $n;
            }
        }
        return $value . '-' . bin2hex(random_bytes(2));
    }
}
