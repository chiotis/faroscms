<?php

declare(strict_types=1);

namespace FarosCMS;

/**
 * The two things an update asks of the network: fetching the package, and asking the site whether the new version
 * works. Both are plain functions so the installer can be given others in tests.
 */
final class UpdateNetwork
{
    /** The paths asked: the sign-in page must answer 200; the home page only must not fail (a site with no pages yet answers 404 there). */
    private const CHECKS = ['/admin/login', '/'];

    /** Whether an address may be fetched: https, or http on this machine (for tests). */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        return $host !== '' && ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)));
    }

    /** Downloads an address into a file, stopping past a number of bytes. An error message, or null. */
    public static function download(string $url, string $destination, int $maxBytes): ?string
    {
        if (!self::isAllowedUrl($url)) {
            return 'The address is not allowed (it must be https).';
        }
        $out = @fopen($destination, 'wb');
        if ($out === false) {
            return 'The file could not be written.';
        }
        $written = 0;
        $error = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => 'FarosCMS updater',
                CURLOPT_FAILONERROR => true,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                // A redirect may only go to https, unless the address itself is on this machine (tests).
                CURLOPT_REDIR_PROTOCOLS => str_starts_with(strtolower($url), 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($out, $maxBytes, &$written): int {
                    $written += strlen($chunk);
                    return $written > $maxBytes ? 0 : (int)fwrite($out, $chunk);
                },
            ]);
            if (curl_exec($ch) === false) {
                $error = $written > $maxBytes ? 'The file is larger than the package should be.' : curl_error($ch);
            }
            unset($ch);
        } else {
            $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'user_agent' => 'FarosCMS updater']]));
            if ($in === false) {
                $error = 'The address could not be reached.';
            } else {
                while (!feof($in)) {
                    $chunk = fread($in, 65536);
                    if ($chunk === false) {
                        $error = 'The download was interrupted.';
                        break;
                    }
                    $written += strlen($chunk);
                    if ($written > $maxBytes) {
                        $error = 'The file is larger than the package should be.';
                        break;
                    }
                    fwrite($out, $chunk);
                }
                fclose($in);
            }
        }
        fclose($out);
        if ($error !== null) {
            @unlink($destination);
        }
        return $error;
    }

    /**
     * Asks the site, in requests of their own, whether the new version works: the sign-in page must answer, the home
     * page must not fail, and neither may show a PHP error.
     *
     * @return array{reached: bool, ok: bool, message: string} reached is false when the site could not be asked at all (then nothing is known)
     */
    public static function checkSite(string $baseUrl, string $token): array
    {
        $reached = false;
        $problems = [];
        foreach (self::CHECKS as $path) {
            [$status, $body] = self::get(rtrim($baseUrl, '/') . $path, $token);
            if ($status === 0) {
                continue;
            }
            $reached = true;
            if ($status >= 500 || ($path === '/admin/login' && $status !== 200)) {
                $problems[] = $path . ' answered ' . $status;
            } elseif (preg_match('/(?:^|<br \/>\s*|<b>)\s*(?:PHP )?(?:Fatal error|Parse error)(?:<\/b>)?:/mi', $body)) {
                $problems[] = $path . ' shows a PHP error';
            }
        }
        if (!$reached) {
            return ['reached' => false, 'ok' => false, 'message' => 'no answer from ' . $baseUrl];
        }
        return ['reached' => true, 'ok' => $problems === [], 'message' => $problems === [] ? 'the site answered' : implode('; ', $problems)];
    }

    /** @return array{0: int, 1: string} the status (0 when there was no answer) and the body */
    private static function get(string $url, string $token): array
    {
        $header = MaintenanceMode::HEADER . ': ' . $token;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => [$header, 'Accept: text/html'], CURLOPT_USERAGENT => 'FarosCMS updater']);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);
            return [is_string($body) ? $status : 0, is_string($body) ? $body : ''];
        }
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0, 'header' => $header . "\r\nAccept: text/html\r\n"]]));
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }
        return [is_string($body) ? $status : 0, is_string($body) ? $body : ''];
    }
}
