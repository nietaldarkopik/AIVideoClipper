<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only http(s) URLs whose host resolves exclusively to public addresses.
 *
 * URLs from this rule are handed to yt-dlp, which fetches them from the server, so
 * an unchecked value lets any signed-in user probe loopback, the compose network
 * (postgres/redis/whisper/...), RFC1918 ranges or cloud metadata (169.254.169.254).
 *
 * Residual risk: the check resolves DNS once and yt-dlp resolves again later
 * (DNS rebinding), and redirects are followed by yt-dlp. Egress filtering at the
 * network layer is the complete fix; this closes the direct cases.
 */
class PublicHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ($parts = parse_url(trim($value))) === false) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            $fail('The :attribute must be a public http(s) URL.');

            return;
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            $fail('The :attribute host could not be resolved.');

            return;
        }

        foreach ($addresses as $ip) {
            if (! $this->isPublic($ip)) {
                $fail('The :attribute must not point to a private or internal address.');

                return;
            }
        }
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $ips = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }

    private function isPublic(string $ip): bool
    {
        // Unwrap IPv4-mapped IPv6 (::ffff:10.0.0.1) so it can't dodge the IPv4 checks.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
