<?php

namespace App\Support;

/**
 * Prepares a client IP for storage.
 *
 * An IP address is personal data under GDPR whether or not we can identify the
 * person behind it, so the default is to anonymise rather than store it raw. The
 * truncated form still supports the things the analytics actually need — distinct
 * networks, rough geography, repeat-caller patterns — while dropping the ability
 * to single out one host.
 *
 * Modes (config('api.analytics.ip_mode')):
 *   anonymized  IPv4 → /24, IPv6 → /48   (default)
 *   full        stored verbatim; a conscious opt-in for abuse investigation
 *   hashed      HMAC with the app key. Preserves grouping, but note this is
 *               WEAKER than it looks: only ~4bn IPv4 inputs exist, so a hash is
 *               trivially reversible by brute force. Anonymising is stronger.
 */
class ApiIp
{
    public static function store(?string $ip, ?string $mode = null): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return match ($mode ?? (string) config('api.analytics.ip_mode', 'anonymized')) {
            'full' => $ip,
            'hashed' => substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 40),
            default => self::anonymize($ip),
        };
    }

    private static function anonymize(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        // IPv6 → keep the routing prefix (/48), zero the rest.
        $packed = inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        return inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));
    }
}
