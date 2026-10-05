<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * Taking backups on top of `BackupService`: the schedule (when one is due, and only one request runs it), a full or
 * database-only snapshot with local retention and the optional upload to remote storage, the run history, the
 * notification, and the words for a result. A failed remote upload only downgrades a run to a warning: the local
 * archive decides whether the backup worked.
 */
final class BackupManager
{
    /**
     * @param callable(): array<string, mixed> $settings the site settings, read each time (the last run changes them)
     * @param callable(): object $updates the update service (its version and commit go into every archive)
     * @param callable(string): void $recordLastRun stores the time of a scheduled run in the settings
     * @param callable(array<string, mixed>): object $storage makes the remote storage from its settings
     */
    public function __construct(
        private BackupService $backups,
        private SystemDatabase $database,
        private BackupRunRepository $runs,
        private NotificationRepository $notifications,
        private $settings,
        private $updates,
        private $recordLastRun,
        private $storage
    ) {
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ($this->settings)();
    }

    public function runIfDue(): void
    {
        if (!$this->database->isAvailable()) {
            // Without SQLite the last run cannot be persisted, so every request would start a new backup.
            return;
        }
        $auto = $this->settings()['backup']['auto'] ?? [];
        if (!is_array($auto) || !Format::isTruthy($auto['enabled'] ?? false)) {
            return;
        }
        $schedule = strtolower((string)($auto['schedule'] ?? 'daily'));
        if (!in_array($schedule, ['daily', 'weekly', 'monthly'], true)) {
            return;
        }

        $lastRun = trim((string)($auto['last_run'] ?? ''));
        $lastTimestamp = $lastRun !== '' ? (int)strtotime($lastRun) : 0;
        if (!self::isDue($lastTimestamp, $schedule)) {
            return;
        }

        $this->backups->ensureDirectory();
        $lockPath = $this->backups->directory() . '/.auto-backup.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if (!$lockHandle) {
            return;
        }
        if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return;
        }

        $result = $this->createSnapshot('scheduled');
        if (($result['ok'] ?? false) === true) {
            ($this->recordLastRun)(date('c'));
        }
        $this->recordRun($result);
        $this->notify($result, true);

        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    public static function isDue(int $lastTimestamp, string $schedule): bool
    {
        if ($lastTimestamp <= 0) {
            return true;
        }
        $interval = match ($schedule) {
            'daily' => 86400,
            'weekly' => 604800,
            'monthly' => 2592000,
            default => PHP_INT_MAX,
        };
        return (time() - $lastTimestamp) >= $interval;
    }

    /**
     * Full snapshot + local retention + optional remote upload.
     * Pre-restore safety snapshots skip pruning so the archive being restored is never deleted.
     *
     * @return array{ok: bool, message: string, filename?: string, status?: string}
     */
    public function createSnapshot(string $reason = 'manual', bool $prune = true, bool $uploadRemote = true): array
    {
        $result = $this->backups->createFullSnapshot($this->siteSlug(), $this->meta($reason));
        return $this->finish($result, $prune, $uploadRemote, $reason);
    }

    /** @return array{ok: bool, message: string, filename?: string, status?: string} */
    public function createDatabaseSnapshot(string $reason = 'manual'): array
    {
        $result = $this->backups->createDatabaseSnapshot($this->siteSlug(), $this->meta($reason));
        return $this->finish($result, true, true, $reason);
    }

    private function finish(array $result, bool $prune, bool $uploadRemote, string $reason): array
    {
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }
        $path = (string)$result['path'];
        $size = is_file($path) ? (int)(filesize($path) ?: 0) : 0;
        if ($prune) {
            $this->backups->prune($this->localKeep());
        }
        $remote = $uploadRemote ? $this->uploadRemote($path, (string)$result['filename']) : null;
        $removed = false;
        if ($remote !== null && ($remote['ok'] ?? false) === true && $this->keepsNoLocalCopy($reason)) {
            // It is safe in remote storage, and the settings ask for no copy on this server.
            $removed = ($this->backups->delete((string)$result['filename'])['ok'] ?? false) === true;
        }
        $built = $this->buildResult((string)$result['message'], (string)$result['filename'], $remote, $removed);
        $built['size'] = $size;
        return $built;
    }

    /**
     * Whether a backup that reached remote storage is taken off this server: the ones that are scheduled or asked for are, unless
     * the settings keep a copy here. The ones taken before an update or a restore always stay, since the update's undo and the
     * restore need the file on this server.
     */
    private function keepsNoLocalCopy(string $reason): bool
    {
        if (!in_array($reason, ['scheduled', 'manual'], true)) {
            return false;
        }
        $remote = $this->settings()['backup']['remote'] ?? [];
        return is_array($remote) && !Format::isTruthy($remote['keep_local'] ?? false);
    }

    private function siteSlug(): string
    {
        $slug = Slug::plain((string)($this->settings()['title'] ?? 'site'));
        return $slug !== '' ? $slug : 'site';
    }

    /** @return array{reason: string, version: string, commit: string} */
    private function meta(string $reason): array
    {
        $updates = ($this->updates)();
        return [
            'reason' => $reason,
            'version' => $updates->currentVersion(),
            'commit' => $updates->currentGitCommit(),
        ];
    }

    private function localKeep(): int
    {
        return max(1, (int)($this->settings()['backup']['local']['keep'] ?? 20));
    }

    public function recordRun(array $result): void
    {
        try {
            $filename = (string)($result['filename'] ?? '');
            $size = (int)($result['size'] ?? 0);
            if ($filename !== '' && $size === 0) {
                $path = $this->backups->pathFor($filename);
                $size = $path !== null ? (int)(filesize($path) ?: 0) : 0;
            }
            $this->runs->record([
                'filename' => $filename,
                'status' => self::status($result),
                'size_bytes' => $size,
                'message' => (string)($result['message'] ?? ''),
            ]);
        } catch (\Throwable) {
            // Backup run history must never block the backup workflow.
        }
    }

    /** @return array{ok: bool, message: string, object_key?: string, pruned?: int}|null */
    private function uploadRemote(string $path, string $filename): ?array
    {
        $remoteSettings = $this->settings()['backup']['remote'] ?? [];
        if (!is_array($remoteSettings) || !Format::isTruthy($remoteSettings['enabled'] ?? false)) {
            return null;
        }

        $storage = ($this->storage)($remoteSettings);
        $upload = $storage->upload($path, $filename);
        if (($upload['ok'] ?? false) === true) {
            $keep = (int)($remoteSettings['keep'] ?? 20);
            $prune = $storage->prune($keep > 0 ? $keep : 20);
            if (($prune['ok'] ?? false) === true) {
                $upload['pruned'] = (int)($prune['deleted'] ?? 0);
            }
        }

        return $upload;
    }

    /**
     * The local archive decides `ok`; a failed remote upload only downgrades the run to a warning,
     * so schedules still advance and the local snapshot is not reported as lost.
     *
     * @param array<string, mixed>|null $remote
     * @return array{ok: bool, status: string, message: string, filename: string, remote: array<string, mixed>|null}
     */
    private function buildResult(string $message, string $filename, ?array $remote, bool $removedLocal = false): array
    {
        $remoteFailed = $remote !== null && (($remote['ok'] ?? false) !== true);
        return [
            'ok' => true,
            'status' => $remoteFailed ? 'warning' : 'success',
            'message' => $message . $this->remoteMessage($remote) . ($removedLocal ? ' It is not kept on this server.' : ''),
            'filename' => $filename,
            'remote' => $remote,
            'removed_local' => $removedLocal,
        ];
    }

    public static function logLevel(array $result): string
    {
        return match (self::status($result)) {
            'warning' => 'warning',
            'success' => 'info',
            default => 'error',
        };
    }

    public static function queryStatus(array $result): string
    {
        return match (self::status($result)) {
            'warning' => 'warn',
            'success' => 'ok',
            default => 'fail',
        };
    }

    public static function status(array $result): string
    {
        if (($result['ok'] ?? false) !== true) {
            return 'failed';
        }
        return (string)($result['status'] ?? 'success') === 'warning' ? 'warning' : 'success';
    }

    /** @param array<string, mixed>|null $remote */
    private function remoteMessage(?array $remote): string
    {
        if ($remote === null) {
            return '';
        }
        if (($remote['ok'] ?? false) === true) {
            $suffix = ' Remote upload completed.';
            if (isset($remote['object_key']) && trim((string)$remote['object_key']) !== '') {
                $suffix .= ' Object: ' . (string)$remote['object_key'] . '.';
            }
            if (isset($remote['pruned']) && (int)$remote['pruned'] > 0) {
                $suffix .= ' Pruned ' . (int)$remote['pruned'] . ' remote backup(s).';
            }
            return $suffix;
        }

        return ' Saved locally, but remote upload failed: ' . (string)($remote['message'] ?? 'Remote storage error.');
    }

    /** @return array{ok: bool, message: string} */
    public function testRemote(): array
    {
        $remoteSettings = $this->settings()['backup']['remote'] ?? [];
        if (!is_array($remoteSettings)) {
            return ['ok' => false, 'message' => 'Remote backup settings are missing.'];
        }

        return (($this->storage)($remoteSettings))->testConnection();
    }

    public function notify(array $result, bool $scheduled): void
    {
        try {
            $status = self::status($result);
            $ok = $status !== 'failed';
            $this->notifications->createIfMissing([
                'type' => match ($status) {
                    'warning' => 'backup.remote_failed',
                    'success' => 'backup.success',
                    default => 'backup.failed',
                },
                'title' => match ($status) {
                    'warning' => 'Backup saved locally, remote upload failed',
                    'success' => 'Backup completed',
                    default => 'Backup failed',
                },
                'body' => (string)($result['message'] ?? ($ok ? 'Backup completed.' : 'Backup failed.')),
                'severity' => match ($status) {
                    'warning' => 'warning',
                    'success' => 'success',
                    default => 'error',
                },
                'target_url' => '/admin/backups',
                'context' => [
                    'scheduled' => $scheduled,
                    'filename' => (string)($result['filename'] ?? ''),
                    'result' => $result,
                ],
            ]);
        } catch (\Throwable) {
            // Notifications must never block backup creation.
        }
    }

    /**
     * The archives on this server, newest first, with the last backup that was taken and kept only in remote storage when it is
     * newer (marked `remote`), so a site that keeps nothing here still knows when it was last backed up.
     *
     * @return array<int, array<string, mixed>>
     */
    public function knownArchives(): array
    {
        $local = $this->backups->list();
        $newestLocal = (int)($local[0]['mtime'] ?? 0);
        foreach ($this->runs->recent(10) as $run) {
            if (!in_array((string)($run['status'] ?? ''), ['success', 'warning'], true)) {
                continue;
            }
            $at = (int)strtotime((string)($run['created_at'] ?? ''));
            if ($at > $newestLocal && $this->backups->pathFor((string)($run['filename'] ?? '')) === null) {
                array_unshift($local, [
                    'filename' => (string)($run['filename'] ?? ''),
                    'mtime' => $at,
                    'size' => (int)($run['size_bytes'] ?? 0),
                    'created_at' => (string)($run['created_at'] ?? ''),
                    'remote' => true,
                ]);
            }
            break;
        }
        return $local;
    }

    /** @return array{label: string, value: string, status: string} */
    public function scheduleStatus(): array
    {
        $auto = is_array($this->settings()['backup']['auto'] ?? null) ? $this->settings()['backup']['auto'] : [];
        $latest = $this->knownArchives()[0] ?? null;
        $latestAge = $latest !== null ? time() - (int)$latest['mtime'] : PHP_INT_MAX;
        if (!Format::isTruthy($auto['enabled'] ?? false)) {
            // Without a schedule, only warn when nobody has taken a backup for a month.
            return [
                'label' => 'Scheduled backups',
                'value' => $latest === null ? 'off, no backups yet' : 'off',
                'status' => $latestAge > 30 * 86400 ? 'warning' : 'ok',
            ];
        }
        $schedule = (string)($auto['schedule'] ?? 'daily');
        $lastRun = (int)strtotime((string)($auto['last_run'] ?? ''));
        $interval = match ($schedule) {
            'weekly' => 604800,
            'monthly' => 2592000,
            default => 86400,
        };
        $overdue = $lastRun > 0 && time() - $lastRun > $interval + 86400;
        return [
            'label' => 'Scheduled backups',
            'value' => $overdue ? 'overdue (' . $schedule . ')' : $schedule . ($lastRun > 0 ? ', last ' . date('Y-m-d', $lastRun) : ''),
            'status' => $overdue ? 'warning' : 'ok',
        ];
    }
}
