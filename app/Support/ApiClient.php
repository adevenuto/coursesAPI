<?php

namespace App\Support;

/**
 * Buckets a user-agent string into a low-cardinality client label.
 *
 * Grouping raw user agents would spread traffic across hundreds of near-identical
 * strings (every curl and requests patch version is its own value), which makes
 * the "what are people calling from" panel useless. Six buckets answer the actual
 * question; the full string is still stored alongside for spot checks.
 */
class ApiClient
{
    /** Ordered — the first match wins, so more specific needles come first. */
    private const SIGNATURES = [
        'curl' => ['curl'],
        'python' => ['python-requests', 'python-urllib', 'httpx', 'aiohttp', 'python'],
        'postman' => ['postmanruntime', 'insomnia'],
        'node' => ['node-fetch', 'axios', 'undici', 'got ', 'node'],
        'php' => ['guzzlehttp', 'php'],
        'go' => ['go-http-client'],
        'browser' => ['mozilla', 'webkit', 'chrome', 'safari', 'firefox'],
    ];

    public static function label(?string $userAgent): ?string
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return null;
        }

        $haystack = strtolower($ua);

        foreach (self::SIGNATURES as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $label;
                }
            }
        }

        return 'other';
    }
}
