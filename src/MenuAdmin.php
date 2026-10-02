<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * What the Menus screens do on top of `Menus`: the list of menus, creating one (empty or as a copy of another), and
 * editing or deleting one. The class never reads the request: the caller passes the address's query and the
 * submitted form, and gets back a location to go to or the data of the page.
 */
final class MenuAdmin
{
    /**
     * @param \Closure(): array<string, mixed> $settings
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     */
    public function __construct(private Menus $menus, private \Closure $settings, private \Closure $log)
    {
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function overview(array $get): array
    {
        return [
            'admin_section' => 'menus',
            'rows' => $this->menus->listForAdmin(),
            'deleted' => isset($get['deleted']),
            'saved' => isset($get['saved']),
            'created' => isset($get['created']),
        ];
    }

    /**
     * The page that makes a menu, or making it from the submitted form.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array{location: string, view: array<string, mixed>}
     */
    public function create(array $get, array $post, bool $isPost): array
    {
        $menuKeys = $this->menus->keys();
        $newKey = Slug::plain((string)($get['new_key'] ?? ''));
        $sourceKey = Slug::plain((string)($get['source_key'] ?? ''));
        if ($newKey === '' && $sourceKey !== '') {
            $newKey = $sourceKey;
        }
        $newTitle = trim((string)($get['new_title'] ?? ''));

        $error = '';
        if ($isPost) {
            $newKey = Slug::plain((string)($post['new_key'] ?? $newKey));
            $title = trim((string)($post['new_title'] ?? ''));
            $newTitle = $title;
            $sourceKey = Slug::plain((string)($post['source_key'] ?? $sourceKey));
            if ($newKey === '') {
                $error = 'Menu key is required.';
            } elseif (in_array($newKey, $menuKeys, true)) {
                $error = 'Menu key already exists.';
            } else {
                $items = [];
                if ($sourceKey !== '' && in_array($sourceKey, $menuKeys, true)) {
                    $source = $this->menus->load($sourceKey);
                    $items = $this->menus->normalize($source['items'] ?? []);
                    if ($title === '') {
                        $title = (string)($source['title'] ?? '');
                    }
                }
                if ($title === '') {
                    $title = Slug::title($newKey);
                }
                $this->menus->write($newKey, ['title' => $title, 'items' => $items]);
                $this->menus->forget();
                ($this->log)('menus.create', 'info', 'menu', $newKey, 'Menu created.', [
                    'title' => $title,
                    'source_key' => $sourceKey,
                ]);
                return ['location' => '/admin/menus-edit?key=' . urlencode($newKey) . '&created=1', 'view' => []];
            }
        }

        return ['location' => '', 'view' => [
            'admin_section' => 'menus',
            'error' => $error,
            'new_key' => $newKey,
            'new_title' => $newTitle,
            'source_key' => $sourceKey,
            'menu_keys' => $menuKeys,
        ]];
    }

    /**
     * The editor of one menu, saving it, or deleting it.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array{location: string, view: array<string, mixed>}
     */
    public function edit(array $get, array $post, bool $isPost): array
    {
        $settings = ($this->settings)();
        $languages = $settings['languages']['available'] ?? [(string)($settings['languages']['default'] ?? 'el')];

        $menuKeys = $this->menus->keys();
        $key = Slug::plain((string)($get['key'] ?? $post['key'] ?? ''));
        if ($key === '') {
            return ['location' => $menuKeys !== [] ? '/admin/menus-edit?key=' . urlencode((string)$menuKeys[0]) : '/admin/menus-new', 'view' => []];
        }
        if (!in_array($key, $menuKeys, true)) {
            return ['location' => '/admin/menus', 'view' => []];
        }

        if ($isPost) {
            if ((string)($post['menu_action'] ?? 'save') === 'delete') {
                $path = $this->menus->path($key);
                if (is_file($path)) {
                    unlink($path);
                    $this->menus->forget();
                    ($this->log)('menus.delete', 'warning', 'menu', $key, 'Menu deleted.', []);
                }
                return ['location' => '/admin/menus?deleted=1', 'view' => []];
            }
            $title = trim((string)($post['menu_title'] ?? ''));
            if ($title === '') {
                $title = Slug::title($key);
            }
            $items = $this->menus->fromAdminRows(
                $post['menu_label_key'] ?? [],
                $post['menu_label_lang'] ?? [],
                $post['menu_url'] ?? [],
                $post['menu_class'] ?? [],
                $post['menu_target'] ?? [],
                $post['menu_depth'] ?? [],
                $languages
            );
            $this->menus->write($key, ['title' => $title, 'items' => $items]);
            $this->menus->forget();
            ($this->log)('menus.update', 'info', 'menu', $key, 'Menu updated.', [
                'title' => $title,
                'items' => count($items),
            ]);
            return ['location' => '/admin/menus-edit?key=' . urlencode($key) . '&saved=1', 'view' => []];
        }

        $menu = $this->menus->load($key);
        $items = $this->menus->flatten($menu['items'] ?? [], $languages);
        if ($items === []) {
            // An empty menu opens with one empty row to fill in.
            $items[] = [
                'depth' => '1',
                'label_key' => '',
                'labels' => array_fill_keys(array_map('strval', $languages), ''),
                'url' => '',
                'class' => '',
                'target' => '',
            ];
        }

        return ['location' => '', 'view' => [
            'admin_section' => 'menus',
            'menu_key' => $key,
            'languages' => $languages,
            'menu_title' => (string)($menu['title'] ?? Slug::title($key)),
            'menu_items' => $items,
            'saved' => isset($get['saved']),
            'created' => isset($get['created']),
            'deleted' => isset($get['deleted']),
            'error' => '',
        ]];
    }
}
