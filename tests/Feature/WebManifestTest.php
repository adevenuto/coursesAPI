<?php

namespace Tests\Feature;

use App\Support\BrandIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_served_as_a_web_app_manifest(): void
    {
        $this->get('/site.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json; charset=UTF-8');
    }

    public function test_it_advertises_the_sizes_android_needs_to_install(): void
    {
        // Without a 192 and a 512 Chrome refuses to offer installation, and
        // without a maskable entry it letterboxes the icon in a white circle.
        $icons = $this->get('/site.webmanifest')->assertOk()->json('icons');

        $sizes = array_column($icons, 'sizes');

        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', array_column($icons, 'purpose'));
    }

    public function test_every_icon_url_is_versioned(): void
    {
        // The CDN caches icons for a week; an unversioned URL would keep serving
        // the previous artwork after a deploy.
        foreach ($this->get('/site.webmanifest')->assertOk()->json('icons') as $icon) {
            $this->assertStringContainsString('?v='.BrandIcons::VERSION, $icon['src']);
        }
    }

    public function test_its_theme_colour_matches_the_rendered_meta_tag(): void
    {
        // These were declared in two places and had already drifted — the old
        // static manifest said #0b2410 while the meta tag said #0a0b0a.
        $themeColor = $this->get('/site.webmanifest')->assertOk()->json('theme_color');

        $this->assertStringContainsString(
            '<meta name="theme-color" content="'.$themeColor.'">',
            $this->get('/')->assertOk()->getContent(),
        );
    }

    public function test_the_head_links_the_versioned_icons(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $version = '?v='.BrandIcons::VERSION;

        $this->assertStringContainsString('rel="icon" href="'.asset('favicon.svg').$version.'"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon" href="'.asset('apple-touch-icon.png').$version.'"', $html);
        $this->assertStringContainsString('rel="manifest" href="'.asset('site.webmanifest').$version.'"', $html);
    }

    public function test_the_generated_icon_files_exist_at_their_advertised_sizes(): void
    {
        // The bug this all came from was a file nobody looked at for a month.
        $expected = [
            'apple-touch-icon.png' => 180,
            'icon-192.png' => 192,
            'icon-512.png' => 512,
            'icon-maskable-512.png' => 512,
        ];

        foreach ($expected as $file => $size) {
            $path = public_path($file);

            $this->assertFileExists($path);
            $this->assertSame([$size, $size], array_slice((array) getimagesize($path), 0, 2), "{$file} is the wrong size");
        }
    }

    public function test_no_static_manifest_shadows_the_route(): void
    {
        // Apache serves an existing file ahead of the route, so a
        // public/site.webmanifest would silently take over again.
        $this->assertFileDoesNotExist(public_path('site.webmanifest'));
    }
}
