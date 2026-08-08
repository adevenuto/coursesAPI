<?php

namespace App\Head\Schemas;

use Laravel\Head\Schema\SchemaObject;
use Laravel\Head\SchemaType;

/**
 * schema.org/GolfCourse JSON-LD. Not one of Laravel Head's built-in schema
 * types, so it is registered explicitly in HeadServiceProvider.
 *
 * Course rows vary a lot in completeness and the schema validator rejects null
 * or empty values, so every setter here drops absent data rather than writing a
 * blank property.
 */
#[SchemaType('GolfCourse')]
class GolfCourse extends SchemaObject
{
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    public function url(string $url): static
    {
        return $this->set('url', $url);
    }

    public function telephone(?string $telephone): static
    {
        return blank($telephone) ? $this : $this->set('telephone', $telephone);
    }

    /**
     * The course's own website, if it has one.
     */
    public function sameAs(?string $url): static
    {
        return blank($url) ? $this : $this->set('sameAs', $url);
    }

    /**
     * A nested PostalAddress built from whichever parts are known.
     */
    public function address(
        ?string $street = null,
        ?string $locality = null,
        ?string $region = null,
        ?string $postalCode = null,
        ?string $country = null,
    ): static {
        $address = array_filter([
            'streetAddress' => $street,
            'addressLocality' => $locality,
            'addressRegion' => $region,
            'postalCode' => $postalCode,
            'addressCountry' => $country,
        ], fn (?string $value): bool => filled($value));

        return $address === []
            ? $this
            : $this->set('address', ['@type' => 'PostalAddress'] + $address);
    }

    /**
     * Nested GeoCoordinates. Both values are required or neither is written.
     */
    public function geo(?float $latitude, ?float $longitude): static
    {
        return $latitude === null || $longitude === null
            ? $this
            : $this->set('geo', [
                '@type' => 'GeoCoordinates',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
    }
}
