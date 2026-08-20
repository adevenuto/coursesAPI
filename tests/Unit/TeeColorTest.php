<?php

namespace Tests\Unit;

use App\Support\TeeColor;
use Tests\TestCase;

/**
 * Boots the app rather than extending PHPUnit's TestCase directly: the
 * vocabulary lives in config, which is the whole point of it being tunable.
 */
class TeeColorTest extends TestCase
{
    private function color(?string $name): ?string
    {
        return TeeColor::resolve($name)['color'];
    }

    private function secondary(?string $name): ?string
    {
        return TeeColor::resolve($name)['secondaryColor'];
    }

    public function test_a_palette_word_resolves_to_its_swatch(): void
    {
        $this->assertSame('#1D4ED8', $this->color('Blue'));
        $this->assertSame('#E5E7EB', $this->color('white'));
        $this->assertSame('#CA8A04', $this->color('GOLD'));
    }

    public function test_a_colour_with_no_swatch_still_resolves(): void
    {
        // The cases that used to need a hand-picked hex.
        $this->assertSame('#800020', $this->color('Burgundy'));
        $this->assertSame('#D2B48C', $this->color('Tan'));
        $this->assertSame('#B87333', $this->color('Copper'));
    }

    public function test_non_english_colour_words_resolve_to_the_same_swatch(): void
    {
        $blue = $this->color('Blue');

        $this->assertSame($blue, $this->color('Azul'));   // Spanish, 362 teeboxes
        $this->assertSame($blue, $this->color('Bleu'));   // French
        $this->assertSame($blue, $this->color('Blu'));    // Italian
        $this->assertSame($this->color('Yellow'), $this->color('Gialli'));
        $this->assertSame($this->color('White'), $this->color('weiß'));
        $this->assertSame($this->color('Gold'), $this->color('Doradas'));
    }

    public function test_noise_words_are_stripped(): void
    {
        $this->assertSame($this->color('White'), $this->color('White Tees'));
        $this->assertSame($this->color('Gold'), $this->color("Men's Gold"));
        $this->assertSame($this->color('Blue'), $this->color('Championship Blue'));
        $this->assertSame($this->color('Red'), $this->color('Red Tee #2'));
    }

    public function test_a_two_tone_name_fills_both_fields(): void
    {
        $this->assertSame('#1D4ED8', $this->color('Blue/White'));
        $this->assertSame('#E5E7EB', $this->secondary('Blue/White'));
    }

    /**
     * Blue/White and White/Blue are different tees on the same card. Sorting or
     * de-ordering the hits would silently merge them.
     */
    public function test_the_printed_order_is_preserved(): void
    {
        $this->assertSame('#E5E7EB', $this->color('White/Blue'));
        $this->assertSame('#1D4ED8', $this->secondary('White/Blue'));
    }

    public function test_any_non_letter_separates_words(): void
    {
        foreach (['Blue/White', 'Blue - White', 'Blue & White', 'Blue|White'] as $name) {
            $this->assertSame('#1D4ED8', $this->color($name), $name);
            $this->assertSame('#E5E7EB', $this->secondary($name), $name);
        }
    }

    /**
     * Several hundred tees are named this way, and the only colour they carry
     * is inside the brackets.
     */
    public function test_a_parenthetical_can_carry_the_colour(): void
    {
        $this->assertSame($this->color('Red'), $this->color('Forward (Red)'));
        $this->assertSame($this->color('Blue'), $this->color('Back (Blue)'));

        // ...while a qualifier in brackets still drops out.
        $this->assertSame($this->color('Blue'), $this->color("Blue (Men's)"));
        $this->assertNull($this->secondary("Blue (Men's)"));
    }

    public function test_a_repeated_colour_does_not_become_two_tone(): void
    {
        $this->assertSame('#B91C1C', $this->color('Red/Red'));
        $this->assertNull($this->secondary('Red/Red'));
    }

    public function test_a_name_with_no_colour_resolves_to_nothing(): void
    {
        // The caller's signal to fall back to whatever it already had.
        foreach (['Championship', 'Forward', 'Tournament', 'Members', 'III', '', null] as $name) {
            $this->assertNull($this->color($name), var_export($name, true));
            $this->assertNull($this->secondary($name), var_export($name, true));
        }
    }

    public function test_the_palette_is_shaped_for_the_editor(): void
    {
        $palette = TeeColor::palette();

        $this->assertNotEmpty($palette);
        $this->assertSame(['name', 'color'], array_keys($palette[0]));
        $this->assertSame('Black', $palette[0]['name']);
        $this->assertContains('Blue', array_column($palette, 'name'));
    }

    /**
     * The config is hand-maintained, so guard the two mistakes that are easy to
     * make in it and invisible until a tee renders with a broken swatch.
     */
    public function test_every_configured_hex_is_storable(): void
    {
        foreach (TeeColor::vocabulary() as $word => $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $hex, "{$word} => {$hex}");
        }
    }

    public function test_every_alias_points_at_a_word_that_exists(): void
    {
        $vocabulary = TeeColor::vocabulary();

        foreach ((array) config('tee_colors.aliases') as $alias => $target) {
            $this->assertArrayHasKey(
                mb_strtolower((string) $target),
                $vocabulary,
                "alias '{$alias}' points at unknown colour '{$target}'",
            );
        }
    }

    /**
     * An ignore word that is also a colour would delete that colour from every
     * name containing it.
     */
    public function test_no_ignored_word_is_also_a_colour(): void
    {
        $vocabulary = TeeColor::vocabulary();

        foreach (TeeColor::ignored() as $word) {
            $this->assertArrayNotHasKey($word, $vocabulary, "'{$word}' is both ignored and a colour");
        }
    }
}
