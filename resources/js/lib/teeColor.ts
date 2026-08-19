/**
 * Client-side mirror of App\Support\TeeColor, used to fill a tee's swatch as
 * its name is typed.
 *
 * Only the tokenizer lives here — the vocabulary itself is shipped from the
 * server in `TeeColorConfig`, so there is no second copy to go stale. PHP stays
 * authoritative: it runs on every scan and every save, and this only pre-fills a
 * field the editor can override. If the two ever disagree the swatch simply
 * doesn't prefill; it can't write a colour the server wouldn't have written.
 */
export interface TeeColorConfig {
    palette: { name: string; color: string }[];
    /** every recognised word → hex */
    vocabulary: Record<string, string>;
    /** words stripped before matching ("tees", "men's", "championship") */
    ignore: string[];
}

export interface ResolvedTeeColor {
    color: string | null;
    secondaryColor: string | null;
}

export function resolveTeeColor(
    name: string | null | undefined,
    config: TeeColorConfig,
): ResolvedTeeColor {
    const empty: ResolvedTeeColor = { color: null, secondaryColor: null };
    const text = String(name ?? '')
        .trim()
        .toLowerCase();

    if (!text) return empty;

    const ignore = new Set(config.ignore);
    // Every non-letter separates, brackets included — "Blue/White", "Blue - White"
    // and "Forward (Red)" all fall out of the one rule.
    const words = text
        .split(/[^\p{L}]+/u)
        .filter((word) => word !== '' && !ignore.has(word));

    const hits: string[] = [];
    for (const word of words) {
        const hex = config.vocabulary[word];
        if (hex && !hits.includes(hex)) hits.push(hex);
    }

    return { color: hits[0] ?? null, secondaryColor: hits[1] ?? null };
}
