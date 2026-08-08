<?php

namespace App\Support;

/**
 * The app icon set, in one place.
 *
 * Both the document head (HeadServiceProvider) and the web app manifest
 * (WebManifestController) read from here. They used to be declared separately
 * and had already drifted — the old static manifest claimed a theme colour of
 * #0b2410 while the meta tag said #0a0b0a.
 */
class BrandIcons
{
    /**
     * Cache-busting token for the icon URLs.
     *
     * Icons are served with `max-age=604800` behind Hostinger's CDN, so
     * replacing the bytes at the same URL leaves the old artwork live at the
     * edge for up to a week. Bump this whenever the artwork changes.
     */
    public const VERSION = '3';

    public const THEME_COLOR = '#0a0b0a';

    /** Versioned public URL for a generated icon. */
    public static function url(string $file): string
    {
        return asset($file).'?v='.self::VERSION;
    }

    /**
     * Icons advertised to the manifest.
     *
     * The maskable entry is what Android crops to its adaptive shape; without
     * one it letterboxes the standard icon inside a white circle.
     *
     * @return list<array<string, string>>
     */
    public static function manifestIcons(): array
    {
        return [
            [
                'src' => self::url('favicon.svg'),
                'sizes' => 'any',
                'type' => 'image/svg+xml',
            ],
            [
                'src' => self::url('icon-192.png'),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => self::url('icon-512.png'),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => self::url('icon-maskable-512.png'),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'maskable',
            ],
        ];
    }
}
