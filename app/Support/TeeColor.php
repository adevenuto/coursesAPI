<?php

namespace App\Support;

/**
 * Turns a printed tee name into a stored hex.
 *
 * A scorecard prints the tee's name, not its swatch. Asking the model for a
 * colour instead produced a fresh plausible shade on every scan — Blue came
 * back as #1F4FA8, #1F5FBF and #2A6EBB across three cards, none of them the
 * palette blue — so the editor had to re-pick it by hand every time. The name
 * is the reliable signal: resolve from it and a re-scan of the same card
 * produces the same colours.
 *
 * Pure and free of the database, so the scan mapper, the editor and the
 * backfill command all agree by construction.
 */
final class TeeColor
{
    /** @var array<string, string>|null */
    private static ?array $lookup = null;

    /** @var array<string, true>|null */
    private static ?array $ignore = null;

    /**
     * The swatches the editor renders, in display order.
     *
     * @return array<int, array{name: string, color: string}>
     */
    public static function palette(): array
    {
        return array_values(array_map(
            fn (array $entry) => [
                'name' => (string) $entry['name'],
                'color' => strtoupper((string) $entry['color']),
            ],
            (array) config('tee_colors.palette', []),
        ));
    }

    /**
     * Every word this resolver understands, mapped to its hex.
     *
     * Shipped to the editor so the browser can resolve a name as it's typed
     * without a second copy of the vocabulary going stale.
     *
     * @return array<string, string>
     */
    public static function vocabulary(): array
    {
        return self::lookup();
    }

    /**
     * The words stripped before matching.
     *
     * @return array<int, string>
     */
    public static function ignored(): array
    {
        return array_keys(self::ignoreSet());
    }

    /**
     * Drop the memoized vocabulary.
     *
     * Only needed by a test that rewrites config('tee_colors.*') at runtime —
     * the tables are built once per request otherwise, because the backfill
     * command calls resolve() ninety thousand times.
     */
    public static function flush(): void
    {
        self::$lookup = null;
        self::$ignore = null;
    }

    /**
     * Resolve a tee name to a primary and secondary colour.
     *
     * "Blue" → Blue. "Blue/White" → Blue + White, in the printed order, because
     * Blue/White and White/Blue are different tees. A name carrying no colour
     * at all ("Championship", "Forward") returns two nulls, which is the
     * caller's signal to fall back to whatever it already had.
     *
     * @return array{color: string|null, secondaryColor: string|null}
     */
    public static function resolve(?string $name): array
    {
        $hits = [];

        foreach (self::words($name) as $word) {
            $hex = self::lookup()[$word] ?? null;

            if ($hex !== null && ! in_array($hex, $hits, true)) {
                $hits[] = $hex;
            }
        }

        return [
            'color' => $hits[0] ?? null,
            'secondaryColor' => $hits[1] ?? null,
        ];
    }

    /**
     * The candidate colour words in a name, in the order they were printed.
     *
     * @return array<int, string>
     */
    private static function words(?string $name): array
    {
        $text = mb_strtolower(trim((string) $name));

        if ($text === '') {
            return [];
        }

        // Anything that isn't a letter is a separator. That covers "Blue/White",
        // "Blue-White", "Blue & White" and "Tee #2" in one rule, and drops the
        // bare numbers that trail so many tee names.
        //
        // Parentheses are separators too, deliberately. A qualifier drops out
        // via the ignore list — "Blue (Men's)" → Blue — while the several
        // hundred tees named "Forward (Red)" or "Back (Blue)" put the only
        // colour they have inside the brackets.
        $parts = preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $ignore = self::ignoreSet();

        return array_values(array_filter(
            $parts,
            fn (string $word) => ! isset($ignore[$word]),
        ));
    }

    /**
     * @return array<string, true>
     */
    private static function ignoreSet(): array
    {
        if (self::$ignore !== null) {
            return self::$ignore;
        }

        self::$ignore = [];

        foreach ((array) config('tee_colors.ignore', []) as $word) {
            self::$ignore[mb_strtolower((string) $word)] = true;
        }

        return self::$ignore;
    }

    /**
     * Every recognised word → hex, built once per request.
     *
     * Ordering matters on collision: palette first so a word that is both a
     * swatch and an extended shade resolves to the swatch, then extended, then
     * aliases pointing at either.
     *
     * @return array<string, string>
     */
    private static function lookup(): array
    {
        if (self::$lookup !== null) {
            return self::$lookup;
        }

        self::$lookup = [];

        foreach ((array) config('tee_colors.palette', []) as $entry) {
            self::$lookup[mb_strtolower((string) $entry['name'])] = strtoupper((string) $entry['color']);
        }

        foreach ((array) config('tee_colors.extended', []) as $word => $hex) {
            self::$lookup[mb_strtolower((string) $word)] ??= strtoupper((string) $hex);
        }

        foreach ((array) config('tee_colors.aliases', []) as $word => $target) {
            $hex = self::$lookup[mb_strtolower((string) $target)] ?? null;

            if ($hex !== null) {
                self::$lookup[mb_strtolower((string) $word)] ??= $hex;
            }
        }

        return self::$lookup;
    }
}
