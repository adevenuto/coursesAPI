<?php

namespace App\Support\Scorecard;

/**
 * The contract for a parsed scorecard: the JSON Schema the model's output is
 * constrained to, and the reading instructions that go with it.
 *
 * Schema and prompt live together because they change together — a new field is
 * useless without the instruction that says how to read it, and a prompt tweak
 * that contradicts the schema is a silent accuracy regression. Keeping them in
 * one versioned class makes either change a reviewable diff.
 *
 * Departures from the shape a human would write by hand, all forced by
 * structured outputs (which requires `additionalProperties: false` and every key
 * in `required`, rejects numeric/length constraints, and — the binding one —
 * allows at most 16 union-typed parameters across the whole schema):
 *
 *  - Ranges (par 3-6, handicap 1-18) are stated in the instructions instead of
 *    the schema, and enforced afterwards by ScorecardVerifier.
 *  - Optional text uses "" rather than null. An empty string is an unambiguous
 *    "not printed" for text, and each nullable string would cost a union.
 *  - `cartPathOnly` is a three-state enum rather than boolean|null, preserving
 *    marked / not-marked / no-such-column at no union cost.
 *  - Par is not nullable. It is the one field every scorecard prints for every
 *    hole, and it is range-bound to 3-6, so requiring a value risks little.
 *  - Combination tees are not captured structurally at all. Your spec called
 *    them a nice-to-have that must never compromise the core parse, and they
 *    cost 9 of the 16 unions on their own; the instructions ask for them in
 *    parseNotes instead.
 *
 * The unions that remain are the ones where absence is real and unguessable:
 * ratings, slopes, printed Out/In/Total yardages, and stroke indexes.
 */
class ScorecardSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return self::object([
            // ---- card identity ---- ("" when the card doesn't print it)
            'name' => self::text(),
            'cardId' => self::text(),
            'printDate' => self::text(),
            'address' => self::text(),
            'phone' => self::text(),
            'website' => self::text(),

            // Always present so the key is never absent; only "metres" changes
            // how downstream treats the numbers, and nothing is ever converted.
            'units' => ['type' => 'string', 'enum' => ['yards', 'metres']],

            // ---- course totals ----
            // Par is required rather than nullable: every scorecard prints it
            // for every hole, and it is range-bound to 3-6.
            'par' => self::object([
                'out' => self::genderedRequired('integer'),
                'in' => self::genderedRequired('integer'),
                'total' => self::genderedRequired('integer'),
            ]),
            'paceOfPlay' => self::object([
                'out' => self::text(),
                'in' => self::text(),
                'total' => self::text(),
            ]),

            // ---- tees ----
            'tees' => ['type' => 'array', 'items' => self::object([
                'id' => ['type' => 'integer'],
                'slug' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'hex' => self::text(),
                // Genuinely absent on many cards — partial rating coverage is
                // normal, so these keep their nulls.
                'rating' => self::gendered('number'),
                'slope' => self::gendered('integer'),
                'yardage' => self::object([
                    'out' => self::nullable('integer'),
                    'in' => self::nullable('integer'),
                    'total' => self::nullable('integer'),
                ]),
            ])],

            // ---- holes ----
            'holes' => ['type' => 'array', 'items' => self::object([
                'number' => ['type' => 'integer'],
                'name' => self::text(),
                'nine' => ['type' => 'string', 'enum' => ['out', 'in']],
                'maxTime' => self::text(),
                // marked / has a column but unmarked / no such column on the card
                'cartPathOnly' => ['type' => 'string', 'enum' => ['yes', 'no', 'unknown']],
                'par' => self::genderedRequired('integer'),
                'handicap' => self::gendered('integer'),
                'yardages' => ['type' => 'array', 'items' => self::object([
                    'teeId' => ['type' => 'integer'],
                    'yards' => self::nullable('integer'),
                ])],
            ])],

            // Free-text channel for anything the schema can't express: a sum
            // that doesn't reconcile, an illegible cell, a combination tee. A
            // stated uncertainty is worth more than a clean-looking guess, and
            // this is where the editor gets to see it.
            'parseNotes' => self::text(),
        ]);
    }

    /**
     * How to read the card. Shape is already guaranteed by the schema, so this
     * is entirely about semantics and reading order.
     */
    public static function instructions(): string
    {
        return <<<'TXT'
        You are reading one golf scorecard. The images provided are of a single card —
        if there is more than one, they are different parts or sides of that same card.

        Work in this order.

        1. Read the tee rows first. Identify every tee set, its printed name, its
           rating/slope pairs, and its Out/In/Total yardage. This establishes how many
           `yardages` entries each hole needs. Cross-check against the ratings block: a
           rating with no matching yardage row is a combination tee, not a missed row.
        2. Read the par and handicap rows. Check carefully whether they are split by
           gender (4/5, 10/12, or separate M/W columns) or single-valued.
        3. Read yardages COLUMN BY COLUMN, hole by hole — not row by row. Column-wise
           reading catches misalignment: if a column has four values where five tees
           exist, you have found a problem a row-wise read would have smoothed over.
        4. Note structural markings — cart-path flags, hole names, pace-of-play times,
           combination-tee arrows, asterisks, footnotes.
        5. Verify before returning (below), then emit.

        Field rules

        - `tees[].id` is a course-local ordinal starting at 1, ordered longest to
          shortest by total yardage. It restarts at 1 on every card.
        - `tees[].slug` is camelCase from the printed name: black, blueWhite, whiteGold.
        - `tees[].hex` is an approximate display colour for that tee — the tee's actual
          colour, not a palette-cycled value.
        - `rating` and `slope` are always split into men and women. Cards commonly rate
          only some tees for each gender; a tee with no women's rating gets null, and
          that is not a parsing failure.
        - `par` and `handicap` are always gender-keyed, on both the hole and the course
          total. Many cards print a single row applying to everyone — in that case set
          men and women to the same value. Never collapse them.
        - `nine` is "out" for holes 1-9 and "in" for 10-18. On a nine-hole card use
          "out" for all nine and say so in parseNotes.
        - `handicap` is the stroke index (labelled "Course HCP", "Handicap", "Index" or
          "SI"). Valid values are 1-18 for an eighteen-hole card. Null it when the card
          prints no stroke index — many don't.
        - `par` is 3-6 and is always required. Every scorecard prints a par for every
          hole; read it rather than leaving it out.
        - `holes[].name` is the printed hole name where the card gives one, else "".
        - `holes[].maxTime` and `paceOfPlay` capture a cumulative pace-of-play clock
          where printed, as strings like "1:57", else "".
        - `cartPathOnly` is "yes" only where the card explicitly marks the hole, "no"
          where the card has a cart-path column and this hole is not marked, and
          "unknown" where the card has no such column at all. A card without the column
          is not a card full of "no".
        - Yardage lives on the hole, keyed by teeId — never duplicated onto the tee.
          Tees carry only their printed Out/In/Total.
        - `units` is "yards" unless the card is in metres. Do NOT convert; report the
          printed numbers and set units to "metres".

        Combination tees

        Some cards list a rating and slope for a combo tee (Wht/Slvr, Blue/White) with
        no yardage row of its own, defined instead by small arrow markers in the two
        parent rows showing which tee each hole plays from. There is no structured field
        for these. If the card has one, describe it in parseNotes — its name, its rating
        and slope, and which tees it draws from — and otherwise ignore it. Do not invent
        an entry in `tees` for it. A combo tee that DOES print a full yardage row is not
        a combination tee at all; that is an ordinary entry in `tees`.

        Missing data

        Text the card does not print is "" (empty string). Numbers the card does not
        print are null, except par, which is always read. Never infer a plausible value
        from surrounding numbers: a blank is correct, and a guess is a silent error that
        survives into the database.

        Verify before returning

        - Each tee's front-nine hole yardages sum to its printed Out, back nine to In,
          and both to Total.
        - Par values sum to the printed Out/In/Total par, checked separately for men
          and women.
        - Each gender's handicaps are exactly 1-18 with no repeats and no gaps (1-9 on
          a nine-hole card). Men's and women's indexes are independent sequences —
          verify each on its own.

        If a sum does not reconcile, say so explicitly in parseNotes and identify which
        tee and which nine. Do NOT adjust a digit to force a total to balance — a card
        that doesn't add up is either a misread or a printing error, and both are worth
        surfacing. State which you think it is.

        Expect these variations rather than forcing the default shape: gender-split par
        and stroke index (including the compact 4/5 and 10/12 notation); tee-varying par,
        where a hole plays par 5 from the back and par 4 from the forwards — this is a
        different axis from gender, so record each tee's own par via that hole's entry
        and note it; partial rating coverage; nine-hole, 27-hole or composite courses;
        metres; non-monotonic yardage, where a hole occasionally plays longer from a
        shorter tee (this is real, not a misread — report it in parseNotes so downstream
        validation doesn't reject it); and illegible cells, which are left blank with a
        note naming the cell and why. Never interpolate.
        TXT;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            // Structured outputs requires every key present and no extras, which
            // is exactly the "never omit a key" rule we want anyway.
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nullable(string $type): array
    {
        return ['type' => [$type, 'null']];
    }

    /**
     * Optional text. Not nullable — "" carries the same meaning at no union cost,
     * and unions are the scarce resource in a structured-output schema.
     *
     * @return array<string, mixed>
     */
    private static function text(): array
    {
        return ['type' => 'string'];
    }

    /**
     * A men/women pair. Both keys always present; either may be null.
     *
     * @return array<string, mixed>
     */
    private static function gendered(string $type): array
    {
        return self::object([
            'men' => self::nullable($type),
            'women' => self::nullable($type),
        ]);
    }

    /**
     * A men/women pair that must always carry a value.
     *
     * @return array<string, mixed>
     */
    private static function genderedRequired(string $type): array
    {
        return self::object([
            'men' => ['type' => $type],
            'women' => ['type' => $type],
        ]);
    }
}
