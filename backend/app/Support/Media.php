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

        return self::baseUrl(app()->runningInConsole() ? null : request()) . '/api/media/' . $path
            . '?' . http_build_query(['expires' => $expires, 'signature' => self::signature($path, $expires)]);
    }

    /**
     * Origin that signed URLs point at. Normally APP_URL (the public domain). But when the API is
     * reached directly by a loopback or private-LAN address (http://localhost:8000, http://192.168.x.x:8000),
     * URLs must use THAT address, otherwise every image and video would still point at the public
     * domain and break the moment its DNS or reverse proxy is down.
     *
     * Only local/private hosts are honoured: honouring any Host header would let a caller make the
     * API emit links to an arbitrary host. A request that arrives via APP_URL's own host (the
     * public path, with ProxyPreserveHost), and any context with no request (CLI, queue jobs,
     * scheduled commands), keep using APP_URL exactly as before.
     */
    public static function baseUrl(?\Illuminate\Http\Request $request = null): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if ($request === null) {
            return $base;
        }

        $host = $request->getHost();

        if ($host === parse_url($base, PHP_URL_HOST)) {
            return $base;
        }

        if (self::isLocalHost($host)) {
            return $request->getSchemeAndHttpHost();
        }

        // Opt-in fallback hosts (tunnels). These are always served over HTTPS by the tunnel
        // provider, but the tunnel talks plain HTTP to us, so the request's own scheme would
        // wrongly say http — force https instead of trusting it.
        foreach ((array) config('integrations.media_trusted_host_suffixes', []) as $suffix) {
            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                return 'https://' . $request->getHttpHost();
            }
        }

        return $base;
    }

    private static function isLocalHost(string $host): bool
    {
        $host = trim($host, '[]');

        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            return true;
        }

        // A valid IP that is NOT public (loopback, 10/8, 172.16/12, 192.168/16, link-local, ::1).
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
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
