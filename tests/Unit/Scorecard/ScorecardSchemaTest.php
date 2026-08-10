<?php

namespace Tests\Unit\Scorecard;

use App\Support\Scorecard\ScorecardSchema;
use PHPUnit\Framework\TestCase;

/**
 * Guards the two things about this schema that fail late and expensively:
 * the API's union-parameter ceiling (a 400 on every parse, only discoverable
 * against the real endpoint), and drift between the schema and the committed
 * fixture every other test builds on.
 */
class ScorecardSchemaTest extends TestCase
{
    /**
     * Structured outputs reject a schema with more than 16 union-typed
     * parameters — "exponential compilation cost". Nothing local catches this:
     * the SDK serializes it happily and the API 400s. Keep headroom.
     */
    public function test_the_schema_stays_under_the_union_parameter_ceiling(): void
    {
        $this->assertLessThanOrEqual(16, $this->countUnions(ScorecardSchema::jsonSchema()));
    }

    public function test_the_committed_fixture_conforms_to_the_schema(): void
    {
        $card = json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/scorecards/bolingbrook.json'),
            true,
        );

        $this->assertSame([], $this->violations(ScorecardSchema::jsonSchema(), $card, ''));
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function countUnions(array $schema): int
    {
        $count = 0;

        if (isset($schema['anyOf']) || (isset($schema['type']) && is_array($schema['type']))) {
            $count++;
        }
        foreach ($schema['properties'] ?? [] as $property) {
            $count += $this->countUnions($property);
        }
        foreach ($schema['anyOf'] ?? [] as $branch) {
            $count += $this->countUnions($branch);
        }
        if (isset($schema['items'])) {
            $count += $this->countUnions($schema['items']);
        }

        return $count;
    }

    /**
     * A deliberately small structural validator: required keys, no extras, and
     * type/enum agreement. Enough to catch the fixture and the schema drifting
     * apart without pulling in a JSON Schema library.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function violations(array $schema, mixed $value, string $path): array
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $branch) {
                if ($this->violations($branch, $value, $path) === []) {
                    return [];
                }
            }

            return [$path.': matches no anyOf branch'];
        }

        $types = (array) ($schema['type'] ?? []);
        $actual = match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            array_is_list($value) => 'array',
            default => 'object',
        };
        // An integer satisfies "number", and a whole float read from JSON is
        // still an integer as far as the card is concerned.
        $ok = in_array($actual, $types, true)
            || ($actual === 'integer' && in_array('number', $types, true))
            || ($actual === 'number' && in_array('integer', $types, true) && floor($value) === $value);

        if (! $ok) {
            return [sprintf('%s: expected %s, got %s', $path, implode('|', $types), $actual)];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return [sprintf('%s: %s is not one of %s', $path, var_export($value, true), implode('|', $schema['enum']))];
        }

        $issues = [];

        if ($actual === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $issues[] = "{$path}.{$key}: missing";
                }
            }
            foreach ($value as $key => $child) {
                $property = $schema['properties'][$key] ?? null;
                if ($property === null) {
                    $issues[] = "{$path}.{$key}: not in the schema";

                    continue;
                }
                $issues = array_merge($issues, $this->violations($property, $child, "{$path}.{$key}"));
            }
        }

        if ($actual === 'array' && isset($schema['items'])) {
            foreach ($value as $i => $child) {
                $issues = array_merge($issues, $this->violations($schema['items'], $child, "{$path}[{$i}]"));
            }
        }

        return $issues;
    }
}
