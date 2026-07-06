<?php

declare(strict_types=1);

namespace FarosCMS;

use PDO;
use Throwable;

final class SystemDatabase
{
    private string $path;
    private ?PDO $pdo = null;
    private ?string $lastError = null;

    public function __construct(string $storageDir)
    {
        $this->path = rtrim($storageDir, '/') . '/db/app.sqlite';
    }

    public function initialize(): void
    {
        try {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                throw new \RuntimeException('PDO SQLite driver is not available.');
            }

            $dir = dirname($this->path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $pdo = $this->connection();
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $this->migrate($pdo);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->pdo = null;
        }
    }

    public function isAvailable(): bool
    {
        return $this->pdo !== null && $this->lastError === null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function connection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $this->pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $this->pdo;
    }

    private function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        $this->applyMigration($pdo, '202607060001_system_foundation', fn(PDO $db) => $this->createFoundationSchema($db));
        $this->applyMigration($pdo, '202607060002_users_google_auth', fn(PDO $db) => $this->addGoogleAuthColumns($db));
    }

    private function applyMigration(PDO $pdo, string $version, callable $callback): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
        $stmt->execute(['version' => $version]);
        if ($stmt->fetchColumn()) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $callback($pdo);
            $insert = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)');
            $insert->execute([
                'version' => $version,
                'applied_at' => gmdate('c'),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function createFoundationSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_meta (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT,
                display_name TEXT,
                password_hash TEXT,
                role TEXT NOT NULL DEFAULT "admin",
                status TEXT NOT NULL DEFAULT "active",
                source TEXT NOT NULL DEFAULT "sqlite",
                google_sub TEXT,
                google_email TEXT,
                last_login_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users (role)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_status ON users (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_google_sub ON users (google_sub)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                level TEXT NOT NULL DEFAULT "info",
                action TEXT NOT NULL,
                actor_username TEXT,
                actor_role TEXT,
                ip_address TEXT,
                user_agent TEXT,
                subject_type TEXT,
                subject_id TEXT,
                message TEXT,
                context_json TEXT,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_action ON activity_logs (action)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_actor ON activity_logs (actor_username)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs (created_at)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS email_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient TEXT NOT NULL,
                subject TEXT NOT NULL,
                status TEXT NOT NULL,
                provider TEXT,
                error_message TEXT,
                context_json TEXT,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_logs_status ON email_logs (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_logs_created ON email_logs (created_at)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                body TEXT,
                severity TEXT NOT NULL DEFAULT "info",
                is_read INTEGER NOT NULL DEFAULT 0,
                target_url TEXT,
                context_json TEXT,
                created_at TEXT NOT NULL,
                read_at TEXT
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_read ON notifications (is_read, created_at)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS content_index (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                slug TEXT NOT NULL,
                lang TEXT NOT NULL,
                title TEXT,
                status TEXT,
                visible INTEGER NOT NULL DEFAULT 1,
                path TEXT NOT NULL,
                checksum TEXT,
                indexed_at TEXT NOT NULL,
                UNIQUE(type, slug, lang)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_index_type_lang ON content_index (type, lang)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_content_index_status ON content_index (status)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS backup_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT,
                status TEXT NOT NULL,
                size_bytes INTEGER,
                message TEXT,
                created_at TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_backup_runs_created ON backup_runs (created_at)');
    }

    private function addGoogleAuthColumns(PDO $pdo): null
    {
        $columns = [];
        foreach ($pdo->query('PRAGMA table_info(users)') as $row) {
            $columns[] = (string)$row['name'];
        }
        if (!in_array('google_sub', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN google_sub TEXT');
        }
        if (!in_array('google_email', $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN google_email TEXT');
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_google_sub ON users (google_sub)');
        return null;
    }
}
