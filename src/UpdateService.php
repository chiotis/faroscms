<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * Read-only update information: local version, remote VERSION/CHANGELOG from the configured
 * GitHub source, and a cached status so admin pages do not hit the network on every request.
 */
final class UpdateService
{
    public const STATUS_META_KEY = 'update_status';
    public const CACHE_SECONDS = 43200;
    public const UNREACHABLE_CACHE_SECONDS = 3600;

    /** @param array<string, mixed> $settings the `updates` settings block */
    public function __construct(
        private string $basePath,
        private array $settings,
        private SystemMetaRepository $meta
    ) {
    }

    public function currentVersion(): string
    {
        $path = $this->basePath . '/VERSION';
        if (is_file($path)) {
            $version = trim((string)file_get_contents($path));
            if ($version !== '') {
                return $version;
            }
        }

        return 'base';
    }

    public function currentGitCommit(): string
    {
        $headPath = $this->basePath . '/.git/HEAD';
        if (!is_file($headPath)) {
            return '';
        }
        $head = trim((string)file_get_contents($headPath));
        if ($head === '') {
            return '';
        }
        if (!str_starts_with($head, 'ref: ')) {
            return substr($head, 0, 12);
        }
        $ref = trim(substr($head, 5));
        if ($ref === '' || str_contains($ref, '..')) {
            return '';
        }
        $refPath = $this->basePath . '/.git/' . $ref;
        if (is_file($refPath)) {
            $commit = trim((string)file_get_contents($refPath));
            return $commit !== '' ? substr($commit, 0, 12) : '';
        }

        return '';
    }

    public function configuredLatestVersion(): string
    {
        return trim((string)($this->settings['latest_version'] ?? ''));
    }

    public function channel(): string
    {
        return (string)($this->settings['channel'] ?? 'stable');
    }

    /** @return array{repository: string, branch: string, version_url: string, changelog_url: string, package_url: string} */
    public function sourceConfig(): array
    {
        $repository = trim((string)($this->settings['repository'] ?? 'chiotis/faroscms'));
        if ($repository === '') {
            $repository = 'chiotis/faroscms';
        }
        $branch = trim((string)($this->settings['branch'] ?? 'main'));
        if ($branch === '') {
            $branch = 'main';
        }
        $encodedBranch = rawurlencode($branch);
        $versionUrl = trim((string)($this->settings['version_url'] ?? ''));
        if ($versionUrl === '') {
            $versionUrl = 'https://raw.githubusercontent.com/' . $repository . '/' . $encodedBranch . '/VERSION';
        }
        $changelogUrl = trim((string)($this->settings['changelog_url'] ?? ''));
        if ($changelogUrl === '') {
            $changelogUrl = 'https://raw.githubusercontent.com/' . $repository . '/' . $encodedBranch . '/CHANGELOG.md';
        }
        $packageUrl = trim((string)($this->settings['package_url'] ?? ''));
        if ($packageUrl === '') {
            $packageUrl = 'https://github.com/' . $repository . '/archive/refs/heads/' . $encodedBranch . '.zip';
        }

        return [
            'repository' => $repository,
            'branch' => $branch,
            'version_url' => $versionUrl,
            'changelog_url' => $changelogUrl,
            'package_url' => $packageUrl,
        ];
    }

    /**
     * Returns the cached remote status, refreshing it when stale or when forced.
     *
     * @return array{checked_at: string, source: string, source_status: string, remote_version: string, current_version: string, latest_version: string, has_update: bool, from_cache: bool}
     */
    public function status(bool $forceRefresh = false): array
    {
        $source = $this->sourceConfig();
        $current = $this->currentVersion();
        $cached = $this->meta->getJson(self::STATUS_META_KEY);

        if (!$forceRefresh && is_array($cached) && (string)($cached['source'] ?? '') === $source['version_url']) {
            $age = time() - (int)strtotime((string)($cached['checked_at'] ?? ''));
            $ttl = (string)($cached['source_status'] ?? '') === 'ok' ? self::CACHE_SECONDS : self::UNREACHABLE_CACHE_SECONDS;
            if ($age >= 0 && $age < $ttl) {
                return $this->finalizeStatus((string)$cached['checked_at'], $source['version_url'], (string)($cached['remote_version'] ?? ''), $current, true);
            }
        }

        $remote = $this->fetchRemoteVersion($source['version_url']);
        $checkedAt = gmdate('c');
        $this->meta->setJson(self::STATUS_META_KEY, [
            'checked_at' => $checkedAt,
            'source' => $source['version_url'],
            'source_status' => $remote !== '' ? 'ok' : 'unreachable',
            'remote_version' => $remote,
        ]);

        return $this->finalizeStatus($checkedAt, $source['version_url'], $remote, $current, false);
    }

    /** Status from cache only; never touches the network. Null when no check has run yet. */
    public function cachedStatus(): ?array
    {
        $cached = $this->meta->getJson(self::STATUS_META_KEY);
        if (!is_array($cached) || empty($cached['checked_at'])) {
            return null;
        }
        return $this->finalizeStatus((string)$cached['checked_at'], (string)($cached['source'] ?? ''), (string)($cached['remote_version'] ?? ''), $this->currentVersion(), true);
    }

    public function fetchRemoteVersion(string $url): string
    {
        $body = $this->readRemoteText($url);
        if ($body === '') {
            return '';
        }
        $line = trim(strtok($body, "\r\n") ?: '');
        if ($line === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]*$/', $line)) {
            return '';
        }

        return $line;
    }

    /** @return array<int, array{version: string, items: array<int, string>}> */
    public function fetchRemoteChangelogEntries(string $url): array
    {
        $body = $this->readRemoteText($url);
        if ($body === '') {
            return [];
        }

        return $this->parseChangelogEntries(preg_split('/\R/', $body) ?: []);
    }

    /** @return array<int, array{version: string, items: array<int, string>}> */
    public function readChangelogEntries(): array
    {
        $path = $this->basePath . '/CHANGELOG.md';
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        return $this->parseChangelogEntries($lines);
    }

    /** @return string[] */
    public function readUpdateGuideSummary(): array
    {
        $path = $this->basePath . '/update.md';
        if (!is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $items = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '- ')) {
                $items[] = trim(substr($trimmed, 2));
            }
            if (count($items) >= 6) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param string[] $lines
     * @return array<int, array{version: string, items: array<int, string>}>
     */
    private function parseChangelogEntries(array $lines): array
    {
        $entries = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
                if (is_array($current)) {
                    $entries[] = $current;
                }
                $current = [
                    'version' => trim((string)$matches[1]),
                    'items' => [],
                ];
                continue;
            }
            if (!is_array($current)) {
                continue;
            }
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '- ')) {
                $current['items'][] = trim(substr($trimmed, 2));
            }
        }
        if (is_array($current)) {
            $entries[] = $current;
        }

        return array_slice(array_map(static function (array $entry): array {
            $entry['items'] = array_slice($entry['items'], 0, 8);
            return $entry;
        }, $entries), 0, 5);
    }

    private function finalizeStatus(string $checkedAt, string $source, string $remote, string $current, bool $fromCache): array
    {
        $latest = $remote !== '' ? $remote : $this->configuredLatestVersion();
        return [
            'checked_at' => $checkedAt,
            'source' => $source,
            'source_status' => $source === '' ? 'not_configured' : ($remote !== '' ? 'ok' : 'unreachable'),
            'remote_version' => $remote,
            'current_version' => $current,
            'latest_version' => $latest,
            'has_update' => $latest !== '' && version_compare($latest, $current, '>'),
            'from_cache' => $fromCache,
        ];
    }

    private function readRemoteText(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        $headers = ['User-Agent: FarosCMS update checker'];
        $token = trim((string)($this->settings['github_token'] ?? ''));
        if ($token !== '' && preg_match('#^https?://(raw\.githubusercontent\.com|api\.github\.com)/#i', $url)) {
            $headers[] = 'Authorization: Bearer ' . $token;
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        }
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) {
            return '';
        }

        return trim($body);
    }
}
