/**
 * Shared display formatting.
 *
 * `nf` was independently redeclared in five components; the analytics work would
 * have added two more. Dates stay formatted server-side in PHP per the app's
 * existing convention — the only raw dates crossing the wire are the YYYY-MM-DD
 * chart categories, which `shortDate` handles.
 */

/** 1234567 → "1,234,567" */
export const nf = (n: number): string => n.toLocaleString('en-US');

/** 0.4213 → "42%" */
export const pct = (value: number, total: number): string =>
    total > 0 ? `${Math.round((value / total) * 100)}%` : '0%';

/** 1234 → "1.2s", 65 → "65ms" */
export const ms = (n: number): string =>
    n >= 1000 ? `${(n / 1000).toFixed(1)}s` : `${Math.round(n)}ms`;

/**
 * "2026-08-18" → "8/18".
 *
 * The explicit T00:00:00 matters: `new Date('2026-08-18')` is parsed as UTC
 * midnight and renders as the previous day west of Greenwich.
 */
export const shortDate = (iso: string): string =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('en-US', {
        month: 'numeric',
        day: 'numeric',
    });
