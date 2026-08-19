<?php

namespace Tests\Unit\Support;

use App\Support\ApiIp;
use Tests\TestCase;

/**
 * Boots the app rather than extending PHPUnit's bare TestCase: the hashed mode
 * derives its HMAC key from config('app.key'). The anonymize path needs nothing.
 */
class ApiIpTest extends TestCase
{
    public function test_it_anonymizes_ipv4_to_a_24_by_default(): void
    {
        $this->assertSame('203.0.113.0', ApiIp::store('203.0.113.42', 'anonymized'));
        $this->assertSame('192.168.1.0', ApiIp::store('192.168.1.254', 'anonymized'));
    }

    public function test_it_anonymizes_ipv6_to_a_48(): void
    {
        // Routing prefix kept, interface identifier discarded.
        $this->assertSame('2001:db8:85a3::', ApiIp::store('2001:db8:85a3::8a2e:370:7334', 'anonymized'));
    }

    public function test_full_mode_stores_the_address_verbatim(): void
    {
        $this->assertSame('203.0.113.42', ApiIp::store('203.0.113.42', 'full'));
    }

    public function test_hashed_mode_is_stable_and_distinguishes_addresses(): void
    {
        $a = ApiIp::store('203.0.113.42', 'hashed');
        $b = ApiIp::store('203.0.113.42', 'hashed');
        $c = ApiIp::store('203.0.113.43', 'hashed');

        $this->assertSame($a, $b, 'the same address must group together');
        $this->assertNotSame($a, $c);
        $this->assertNotSame('203.0.113.42', $a);
        $this->assertSame(40, strlen((string) $a));
    }

    public function test_it_returns_null_for_anything_that_is_not_an_ip(): void
    {
        $this->assertNull(ApiIp::store(null));
        $this->assertNull(ApiIp::store(''));
        $this->assertNull(ApiIp::store('not-an-ip'));
        $this->assertNull(ApiIp::store('999.999.999.999'));
    }
}
