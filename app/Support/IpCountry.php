<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * IP address → ISO 3166-1 alpha-2 country code.
 *
 * Used once per registration to record roughly where a user is. Only the
 * resulting country is stored; the address is discarded, which keeps this
 * consistent with the anonymisation stance in config/api.php — a country is not
 * identifying, an address arguably is.
 *
 * Three rules this has to obey, all of them about not mattering too much:
 *
 *  - It must never break a signup. Every failure path returns null, including a
 *    missing token, an unreachable host, a slow one, and a malformed reply. A
 *    country is a nice-to-have; registering is not.
 *  - It must never be slow. One request, a hard timeout of a couple of seconds,
 *    no retries.
 *  - It must not be queued. Nothing drains the `database` queue on this host, so
 *    a dispatched job would sit there forever. Inline and guarded instead.
 *
 * Responses are cached in geocode_cache the way ReverseGeocoder caches, keyed by
 * address, so repeated signups from one network cost one call.
 */
class IpCountry
{
    private const TIMEOUT_SECONDS = 2;

    public int $calls = 0;

    public int $cacheHits = 0;

    /**
     * The country for an address, or null when it cannot be determined.
     *
     * Null is an ordinary outcome, not an error: private and loopback addresses
     * have no country, and a local or CI request legitimately has neither a
     * token nor a route to the internet.
     */
    public function lookup(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $cacheKey = 'ipcountry:'.$ip;

        $cached = DB::table('geocode_cache')
            ->where('provider', 'ipcountry')
            ->where('query', $cacheKey)
            ->first();

        if ($cached !== null) {
            $this->cacheHits++;

            // A cached miss is still a miss — don't re-ask on every signup from
            // an address the provider couldn't place.
            return self::normalize($cached->response_json ?? null);
        }

        $country = $this->fetch($ip);

        // Cached either way, hit or miss, for the same reason.
        try {
            DB::table('geocode_cache')->insert([
                'query' => $cacheKey,
                'provider' => 'ipcountry',
                'response_json' => (string) $country,
                'status' => $country === null ? 'empty' : 'fetched',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // A duplicate from a concurrent signup, or any other write problem.
            // The lookup already succeeded; caching it is an optimisation.
        }

        return $country;
    }

    private function fetch(string $ip): ?string
    {
        $endpoint = (string) config('services.ipcountry.endpoint');
        $token = (string) config('services.ipcountry.token');

        if ($endpoint === '' || $token === '') {
            return null;
        }

        try {
            $this->calls++;

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(0)
                ->acceptJson()
                ->get(str_replace('{ip}', $ip, $endpoint), ['token' => $token]);

            if (! $response->successful()) {
                return null;
            }

            return self::normalize($response->json('country'));
        } catch (Throwable) {
            // Unreachable, timed out, DNS failure, invalid JSON — all the same
            // to a caller who only wanted a nice-to-have.
            return null;
        }
    }

    /** Two letters, upper case, or nothing. */
    private static function normalize(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }
}
