<?php

declare(strict_types=1);

namespace Aep\Infrastructure\Git;

/**
 * Strips credentials from remote URLs for metadata and log messages.
 */
final class RemoteUrlSanitizer
{
    public static function sanitize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            return self::stripUserinfoFallback($url);
        }

        $scheme = $parts['scheme'];
        if ($scheme === 'file') {
            return $url;
        }

        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        if ($host === '') {
            return self::stripUserinfoFallback($url);
        }

        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }

    public static function redactMessage(string $message, ?string $credential = null): string
    {
        $redacted = preg_replace('#://([^:@/]+):([^@/]+)@#', '://***:***@', $message) ?? $message;
        if ($credential !== null && $credential !== '') {
            $redacted = str_replace($credential, '***', $redacted);
        }

        return $redacted;
    }

    private static function stripUserinfoFallback(string $url): string
    {
        return preg_replace('#://([^:@/]+):([^@/]+)@#', '://', $url) ?? $url;
    }
}
