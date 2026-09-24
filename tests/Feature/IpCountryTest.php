<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\IpCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The contract here is mostly about what must NOT happen: a country is a
 * nice-to-have, and nothing about resolving one may cost a registration.
 */
class IpCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ipcountry.endpoint' => 'https://example.test/{ip}/json']);
        config(['services.ipcountry.token' => 'test-token']);
    }

    public function test_it_resolves_and_caches_a_country(): void
    {
        Http::fake(['example.test/*' => Http::response(['country' => 'gb'])]);

        $service = app(IpCountry::class);

        $this->assertSame('GB', $service->lookup('81.2.69.142'));

        // Second call is served from geocode_cache, not the network.
        $this->assertSame('GB', $service->lookup('81.2.69.142'));
        $this->assertSame(1, $service->calls);
        $this->assertSame(1, $service->cacheHits);
    }

    /**
     * A miss is cached too — otherwise every signup from an address the provider
     * can't place pays for the lookup again.
     */
    public function test_a_miss_is_cached(): void
    {
        Http::fake(['example.test/*' => Http::response(['country' => null])]);

        $service = app(IpCountry::class);

        $this->assertNull($service->lookup('81.2.69.142'));
        $this->assertNull($service->lookup('81.2.69.142'));
        $this->assertSame(1, $service->calls);
        $this->assertSame(1, DB::table('geocode_cache')->where('provider', 'ipcountry')->count());
    }

    public function test_private_and_invalid_addresses_never_reach_the_network(): void
    {
        Http::fake();

        $service = app(IpCountry::class);

        foreach (['127.0.0.1', '192.168.1.10', '10.0.0.1', 'not-an-ip', '', null] as $ip) {
            $this->assertNull($service->lookup($ip));
        }

        Http::assertNothingSent();
    }

    public function test_it_returns_null_without_a_token_rather_than_calling_out(): void
    {
        config(['services.ipcountry.token' => null]);
        Http::fake();

        $this->assertNull(app(IpCountry::class)->lookup('81.2.69.142'));

        Http::assertNothingSent();
    }

    public function test_a_failing_provider_yields_null_instead_of_throwing(): void
    {
        Http::fake(['example.test/*' => Http::response('nope', 500)]);

        $this->assertNull(app(IpCountry::class)->lookup('81.2.69.142'));
    }

    public function test_a_nonsense_country_is_rejected(): void
    {
        Http::fake(['example.test/*' => Http::response(['country' => 'United Kingdom'])]);

        $this->assertNull(app(IpCountry::class)->lookup('81.2.69.142'));
    }

    /**
     * The one that actually matters: the provider being down must cost the
     * signup a nice-to-have, not the account.
     */
    public function test_registration_succeeds_when_the_lookup_fails(): void
    {
        Http::fake(['example.test/*' => Http::response('', 503)]);

        $this->post('/register', [
            'name' => 'Test Person',
            'email' => 'person@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $user = User::where('email', 'person@example.com')->first();

        $this->assertNotNull($user, 'the account must be created regardless');
        $this->assertNull($user->signup_country);
    }

    public function test_registration_records_the_country_when_it_resolves(): void
    {
        Http::fake(['example.test/*' => Http::response(['country' => 'ES'])]);

        $this->withServerVariables(['REMOTE_ADDR' => '81.2.69.142'])->post('/register', [
            'name' => 'Otra Persona',
            'email' => 'otra@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $this->assertSame('ES', User::where('email', 'otra@example.com')->first()?->signup_country);
    }
}
