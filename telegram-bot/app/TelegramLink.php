<?php
declare(strict_types=1);

namespace Hendy;

/**
 * Allowlist for public Telegram links rendered by the marketing site.
 * Values from environment variables are never emitted without validation.
 */
final class TelegramLink
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\r\n\x00-\x1F]/', $value)) {
            return '';
        }

        $parts = parse_url($value);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['t.me', 'telegram.me', 'www.telegram.me'], true)) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path === '/'
            || preg_match('~^/[A-Za-z0-9_+./-]{1,200}$~D', $path) !== 1
            || str_contains($path, '..')) {
            return '';
        }

        return 'https://t.me' . $path;
    }
}
