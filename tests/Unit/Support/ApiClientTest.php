<?php

namespace Tests\Unit\Support;

use App\Support\ApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    /**
     * @return list<array{0:?string, 1:?string}>
     */
    public static function agents(): array
    {
        return [
            ['curl/8.4.0', 'curl'],
            ['python-requests/2.31.0', 'python'],
            ['httpx/0.27.0', 'python'],
            ['PostmanRuntime/7.36.0', 'postman'],
            ['node-fetch/3.3.2', 'node'],
            ['axios/1.6.7', 'node'],
            ['GuzzleHttp/7.8', 'php'],
            ['Go-http-client/2.0', 'go'],
            ['Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120.0 Safari/537.36', 'browser'],
            ['SomeBespokeAgent/1.0', 'other'],
            // Absent or blank is genuinely unknown, not "other".
            [null, null],
            ['', null],
            ['   ', null],
        ];
    }

    #[DataProvider('agents')]
    public function test_it_buckets_user_agents(?string $agent, ?string $expected): void
    {
        $this->assertSame($expected, ApiClient::label($agent));
    }

    public function test_a_browser_agent_mentioning_curl_is_not_mislabelled_first(): void
    {
        // Ordering guard: 'curl' is checked before 'mozilla', so a UA containing
        // both resolves to curl. Documented rather than accidental.
        $this->assertSame('curl', ApiClient::label('curl/8.4.0 (compatible; Mozilla/5.0)'));
    }
}
