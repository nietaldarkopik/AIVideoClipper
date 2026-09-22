<?php

namespace App\Support;

class Media
{
    /**
     * Signed URL for a file on the "media" disk. <img>/<video>/<audio> can't send a
     * bearer token, so access to /api/media/* is granted by an HMAC over the path
     * and an expiry instead of by login. The expiry is rounded up to the next hour
     * so the same file yields the same URL within that hour (browser cache friendly).
     */
    public static function url(?string $relativePath): ?string
    {
        if (! $relativePath) {
            return null;
        }

        $path = ltrim($relativePath, '/');
        $expires = self::expiry();

        return rtrim(config('app.url'), '/') . '/api/media/' . $path
            . '?' . http_build_query(['expires' => $expires, 'signature' => self::signature($path, $expires)]);
    }

    public static function valid(string $path, mixed $expires, mixed $signature): bool
    {
        if (! is_string($signature) || ! ctype_digit((string) $expires) || (int) $expires < time()) {
            return false;
        }

        return hash_equals(self::signature($path, (int) $expires), $signature);
    }

    public static function ttl(): int
    {
        return (int) config('filesystems.media_url_ttl', 86400);
    }

    private static function expiry(): int
    {
        return (intdiv(time(), 3600) + 1) * 3600 + self::ttl();
    }

    private static function signature(string $path, int $expires): string
    {
        return hash_hmac('sha256', $path . '|' . $expires, self::key());
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }
}
