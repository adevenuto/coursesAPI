<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventInertiaResponseCaching;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards against the browser serving a cached Inertia JSON payload to a normal
 * page load, which renders as a raw JSON dump instead of the page.
 *
 * @see PreventInertiaResponseCaching
 */
class ResponseCachingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The headers a real Inertia XHR sends. Without the matching version,
     * Inertia answers 409 to force a full reload.
     *
     * The version is only resolved once a request has been through Inertia's
     * middleware, so it is read off a rendered page rather than the facade.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        preg_match('/"version":"([a-f0-9]+)"/', $this->get('/docs')->getContent(), $matches);

        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $matches[1] ?? '',
        ];
    }

    public function test_html_documents_are_not_stored_by_the_browser(): void
    {
        $response = $this->get('/docs')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_inertia_responses_are_not_stored_by_the_browser(): void
    {
        $response = $this->withHeaders($this->inertiaHeaders())
            ->get('/docs')
            ->assertOk();

        $this->assertSame('true', $response->headers->get('X-Inertia'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_inertia_urls_vary_on_the_inertia_header(): void
    {
        // Without this, a cache keeps one entry per URL and cannot tell the HTML
        // document apart from the JSON payload.
        foreach ([[], $this->inertiaHeaders()] as $headers) {
            $response = $this->withHeaders($headers)->get('/docs')->assertOk();

            $this->assertStringContainsString('X-Inertia', $response->headers->get('Vary'));
        }
    }

    public function test_the_same_url_returns_html_or_json_depending_on_the_header(): void
    {
        $this->assertStringContainsString(
            'text/html',
            $this->get('/docs')->headers->get('Content-Type'),
        );

        $this->assertStringContainsString(
            'application/json',
            $this->withHeaders($this->inertiaHeaders())->get('/docs')->headers->get('Content-Type'),
        );
    }

    public function test_xml_endpoints_keep_their_own_cache_headers(): void
    {
        // The sitemap is not part of the HTML/JSON exchange, so the middleware
        // should leave it alone.
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
