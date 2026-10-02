<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * What the Updates screen shows and does: the current and latest version, the release notes (from the project when
 * it can be reached, else the local ones), the checks to pass before updating, the verified backup made just before,
 * and the two actions (check for an update, make that backup). It only reads and checks: updating itself is not done
 * from here. Permissions are the caller's.
 */
final class UpdateAdmin
{
    /**
     * @param \Closure(): UpdateService $updates
     * @param \Closure(): BackupAdmin $backupAdmin
     * @param \Closure(string, string, ?string, ?string, string, array<string, mixed>): void $log records an activity: action, level, subject type, subject id, message, context
     */
    public function __construct(
        private \Closure $updates,
        private AdminNotices $notices,
        private \Closure $backupAdmin,
        private BackupService $backups,
        private string $contentDir,
        private string $basePath,
        private \Closure $log
    ) {
    }

    /** Looks for an update right now (the screen otherwise reads what was kept) and records that it did. */
    public function check(): string
    {
        $status = ($this->updates)()->status(true);
        $this->notices->syncUpdate($status);
        ($this->log)('updates.check', 'info', 'updates', 'local', 'Read-only update check completed.', [
            'current_version' => $status['current_version'],
            'remote_version' => $status['remote_version'],
            'source_status' => $status['source_status'],
            'source' => $status['source'],
        ]);
        return '/admin/updates?checked=1';
    }

    /** Makes the verified backup to have before updating; where to go next is returned. */
    public function backupFirst(): string
    {
        $made = ($this->backupAdmin)()->preUpdateBackup(($this->updates)()->currentVersion());
        return '/admin/updates?' . http_build_query([
            'pre_backup' => $made['ok'] ? 'ok' : 'fail',
            'pre_backup_msg' => $made['message'],
        ]);
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function screen(array $get): array
    {
        $updates = ($this->updates)();
        $source = $updates->sourceConfig();
        $status = $updates->status();
        $this->notices->syncUpdate($status);
        $changelog = $updates->readChangelogEntries();
        $changelogSource = 'local';
        $remote = $updates->fetchRemoteChangelogEntries((string)$source['changelog_url']);
        if (!empty($remote)) {
            $changelog = $remote;
            $changelogSource = 'remote';
        }
        $last = $changelog[0] ?? null;
        $backups = $this->backups->list();
        $preBackup = ($this->backupAdmin)()->preUpdateStatus();

        return [
            'title' => 'Updates',
            'admin_section' => 'updates',
            'current_type' => 'pages',
            'current_version' => $status['current_version'],
            'current_commit' => $updates->currentGitCommit(),
            'latest_version' => $status['latest_version'] !== '' ? $status['latest_version'] : (string)($last['version'] ?? $status['current_version']),
            'remote_version' => $status['remote_version'],
            'has_update' => $status['has_update'],
            'update_checked_at' => $status['checked_at'],
            'update_source' => (string)$source['version_url'],
            'update_source_status' => $status['source_status'],
            'update_repository' => (string)$source['repository'],
            'update_branch' => (string)$source['branch'],
            'update_changelog_url' => (string)$source['changelog_url'],
            'update_package_url' => (string)$source['package_url'],
            'update_channel' => $updates->channel(),
            'checked' => isset($get['checked']),
            'changelog_entries' => $changelog,
            'changelog_source' => $changelogSource,
            'update_guide' => $updates->readUpdateGuideSummary(),
            'latest_backup' => $backups[0] ?? null,
            'backup_total' => count($backups),
            'preflight_checks' => $this->preflight($source, $preBackup),
            'pre_update_backup' => $preBackup,
            'pre_backup_status' => (string)($get['pre_backup'] ?? ''),
            'pre_backup_message' => trim((string)($get['pre_backup_msg'] ?? '')),
        ];
    }

    /**
     * What has to be in order before updating.
     *
     * @param array<string, mixed> $source the update source configuration
     * @param array<string, mixed>|null $preBackup the verified backup made before updating, if any
     * @return array<int, array{label: string, value: string, status: string}>
     */
    public function preflight(array $source, ?array $preBackup): array
    {
        $backupDir = $this->backups->directory();
        $preBackupAge = $preBackup !== null ? time() - (int)strtotime((string)$preBackup['created_at']) : PHP_INT_MAX;
        $preBackupReady = $preBackup !== null && $preBackup['verified'] && $preBackupAge < 86400 && $preBackup['version'] === ($this->updates)()->currentVersion();
        $storage = is_writable($this->basePath . '/storage');
        $content = is_writable($this->contentDir);
        $backupReady = is_dir($backupDir) && is_writable($backupDir);
        $configured = (string)$source['version_url'] !== '';
        return [
            [
                'label' => 'Verified pre-update backup',
                'value' => $preBackupReady ? 'ready' : ($preBackup === null ? 'missing' : ($preBackup['verified'] ? 'older than 24h' : 'not verified')),
                'status' => $preBackupReady ? 'ok' : 'warning',
            ],
            [
                'label' => 'PHP compatibility',
                'value' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'error',
            ],
            ['label' => 'Content writable', 'value' => $content ? 'yes' : 'no', 'status' => $content ? 'ok' : 'error'],
            ['label' => 'Storage writable', 'value' => $storage ? 'yes' : 'no', 'status' => $storage ? 'ok' : 'error'],
            ['label' => 'Backup directory', 'value' => $backupReady ? 'ready' : 'not ready', 'status' => $backupReady ? 'ok' : 'warning'],
            ['label' => 'Update source', 'value' => $configured ? 'configured' : 'not configured', 'status' => $configured ? 'ok' : 'warning'],
        ];
    }
}
