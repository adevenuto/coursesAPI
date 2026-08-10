<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Parses a free-text course address into the same component shape Google Places
 * hands the editor, so both sources feed one resolver (GeoResolver::fromAddressComponents).
 *
 * Only the parts an address reliably encodes are extracted: the country from the
 * tail segment, and the subdivision code from the "OH 45208" / "SK S0M 1A0" /
 * "NSW 2000" shape. Anything looser collides with street and postal-town names.
 *
 * Resolve this once and reuse it — the constructor builds the country token map.
 */
class AddressComponents
{
    /** @var array<string, int> lowercased token => country id */
    private array $countryMap = [];

    /** @var array<int, array{iso2:?string, name:string}> */
    private array $countries = [];

    public function __construct()
    {
        foreach (DB::table('countries')->get(['id', 'name', 'iso2', 'iso3']) as $c) {
            $this->countries[$c->id] = ['iso2' => $c->iso2, 'name' => $c->name];

            foreach ([$c->name, $c->iso2, $c->iso3] as $token) {
                $key = self::normalizeToken($token);
                if ($key !== '' && ! isset($this->countryMap[$key])) {
                    $this->countryMap[$key] = $c->id;
                }
            }
        }

        // Address tails that aren't the canonical name or an ISO code.
        $aliases = [
            'usa' => 'US', 'us' => 'US', 'united states of america' => 'US', 'u.s.a.' => 'US', 'u.s.' => 'US',
            'uk' => 'GB', 'u.k.' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB',
            'northern ireland' => 'GB', 'great britain' => 'GB',
            'uae' => 'AE', 'south korea' => 'KR', 'north korea' => 'KP', 'russia' => 'RU',
            'the netherlands' => 'NL', 'holland' => 'NL', 'ivory coast' => 'CI', 'czech republic' => 'CZ',
        ];
        foreach ($aliases as $token => $iso2) {
            $id = $this->countryMap[self::normalizeToken($iso2)] ?? null;
            if ($id !== null) {
                $this->countryMap[$token] = $id;
            }
        }
    }

    /**
     * @return array{country_code:?string, country_name:?string, state_code:?string, state_name:?string, city_candidates:array<int, string>}
     */
    public function parse(?string $address): array
    {
        $empty = [
            'country_code' => null,
            'country_name' => null,
            'state_code' => null,
            'state_name' => null,
            'city_candidates' => [],
        ];

        if ($address === null || trim($address) === '') {
            return $empty;
        }

        $segs = array_values(array_filter(
            array_map('trim', explode(',', $address)),
            fn (string $s) => $s !== ''
        ));

        if (count($segs) < 2) {
            return $empty; // no structure to read
        }

        $countryId = $this->countryIdFromTail($address);

        // The subdivision segment: a 2-3 letter code followed by something short
        // containing a digit. The digit requirement is what stops "St Andrews
        // Blvd" from reading as the state code "ST".
        $stateCode = null;
        $stateIdx = null;
        foreach ($segs as $i => $seg) {
            if (preg_match('/^([A-Za-z]{2,3})\s+(.{2,10})$/', $seg, $m) && preg_match('/\d/', $m[2])) {
                $stateCode = strtoupper($m[1]);
                $stateIdx = $i;
            }
        }

        return [
            'country_code' => $countryId !== null ? $this->countries[$countryId]['iso2'] : null,
            'country_name' => $countryId !== null ? $this->countries[$countryId]['name'] : null,
            'state_code' => $stateCode,
            'state_name' => null, // an address carries the code, never the full name
            'city_candidates' => ($stateIdx !== null && $stateIdx > 0) ? [$segs[$stateIdx - 1]] : [],
        ];
    }

    /** Resolve the country from an address's last comma segment. */
    public function countryIdFromTail(?string $address): ?int
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $address));

        return $this->countryMap[self::normalizeToken(end($parts))] ?? null;
    }

    public function iso2For(int $countryId): ?string
    {
        return $this->countries[$countryId]['iso2'] ?? null;
    }

    public static function normalizeToken(?string $token): string
    {
        return trim(strtolower((string) $token), " \t\n\r\0\x0B.");
    }
}
