<?php

declare(strict_types=1);

namespace FarosCMS;

final class PermissionService
{
    /** @var array<string, array<int, string>> */
    private array $roleCapabilities = [
        'superadmin' => [
            'admin.access',
            'dashboard.view',
            'content.manage',
            'content.raw_html',
            'forms.manage',
            'media.manage',
            'menus.manage',
            'taxonomies.manage',
            'settings.manage',
            'translations.manage',
            'users.manage',
            'users.self',
            'activity.manage',
            'email_logs.manage',
            'notifications.manage',
            'imports.manage',
            'exports.manage',
            'backups.manage',
            'backups.restore',
            'updates.manage',
        ],
        'admin' => [
            'admin.access',
            'dashboard.view',
            'content.manage',
            'content.raw_html',
            'forms.manage',
            'media.manage',
            'menus.manage',
            'taxonomies.manage',
            'settings.manage',
            'translations.manage',
            'users.self',
            'activity.manage',
            'email_logs.manage',
            'notifications.manage',
            'imports.manage',
            'exports.manage',
            'backups.manage',
            'updates.manage',
        ],
        // Writes and publishes content and manages media and taxonomies. No forms (their submissions hold
        // visitors' personal data), menus, settings, users, logs, backups, updates, or import/export, and no
        // raw HTML in content (see content.raw_html), so an editor cannot script the public site.
        'editor' => [
            'admin.access',
            'dashboard.view',
            'content.manage',
            'media.manage',
            'taxonomies.manage',
            'users.self',
        ],
        'user' => [
            'admin.access',
            'users.self',
        ],
    ];

    /**
     * Roles in order of power, for the users screens.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function roles(): array
    {
        return [
            'superadmin' => ['label' => 'Super admin', 'description' => 'Everything, including users and roles. Keep at least one.'],
            'admin' => ['label' => 'Admin', 'description' => 'Everything except managing users: content, forms, menus, settings, backups, and updates.'],
            'editor' => ['label' => 'Editor', 'description' => 'Writes, edits, and publishes pages, posts, and projects, and manages media and categories. Cannot change settings, forms, menus, or users, and cannot add raw HTML.'],
            'user' => ['label' => 'Basic user', 'description' => 'Can sign in and edit their own profile only.'],
        ];
    }

    /** @return array<int, string> */
    public function capabilitiesForRole(string $role): array
    {
        return $this->roleCapabilities[$this->normalizeRole($role)] ?? [];
    }

    public function can(?array $user, string $capability): bool
    {
        if (!$user || (string)($user['status'] ?? 'active') !== 'active') {
            return false;
        }

        return in_array($capability, $this->capabilitiesForRole((string)($user['role'] ?? 'user')), true);
    }

    public function canAccessAction(?array $user, string $action): bool
    {
        $capability = match ($action) {
            'settings', 'system', 'content-types' => 'settings.manage',
            'dashboard', 'index' => 'dashboard.view',
            'content' => 'content.manage',
            'menus', 'menus-new', 'menus-edit' => 'menus.manage',
            'media', 'files' => 'media.manage',
            'forms', 'form-submissions' => 'forms.manage',
            'forms-export', 'export' => 'exports.manage',
            'import' => 'imports.manage',
            'translations' => 'translations.manage',
            'taxonomies' => 'taxonomies.manage',
            'activity-logs' => 'activity.manage',
            'email-logs' => 'email_logs.manage',
            'notification-read', 'notifications-read-all' => 'notifications.manage',
            'backups' => 'backups.manage',
            'updates' => 'updates.manage',
            'users', 'users-delete' => 'users.manage',
            'users-edit' => null,
            'edit', 'save', 'delete', 'new', 'block-presets', 'content-bulk', 'search' => 'content.manage',
            // An action nobody mapped is for administrators only, never for a lower role by accident.
            default => 'settings.manage',
        };

        if ($capability === null) {
            return $this->can($user, 'users.manage') || $this->can($user, 'users.self');
        }

        return $this->can($user, $capability);
    }

    public function canEditUser(?array $actor, int $targetId): bool
    {
        if ($this->can($actor, 'users.manage')) {
            return true;
        }

        return $this->can($actor, 'users.self') && $targetId > 0 && (int)($actor['id'] ?? 0) === $targetId;
    }

    public function canCreateUsers(?array $actor): bool
    {
        return $this->can($actor, 'users.manage');
    }

    public function canChangeAccessForUser(?array $actor, int $targetId): bool
    {
        return $this->can($actor, 'users.manage') && (int)($actor['id'] ?? 0) !== $targetId;
    }

    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        return $role !== '' ? $role : 'user';
    }
}
