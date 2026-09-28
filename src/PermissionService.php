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
        'user' => [
            'admin.access',
            'users.self',
        ],
    ];

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
            'settings' => 'settings.manage',
            'dashboard', 'index' => 'dashboard.view',
            'content' => 'content.manage',
            'menus', 'menus-new', 'menus-edit' => 'menus.manage',
            'media', 'files' => 'media.manage',
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
            'edit', 'save', 'delete', 'new' => 'content.manage',
            default => 'content.manage',
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
