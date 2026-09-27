/** Shapes shared by the suggestion modal's typeaheads. */

/** One row in a typeahead dropdown, whatever the backing store. */
export interface SuggestOption {
    id: number;
    /** Plain text put into the input when this row is chosen. */
    label: string;
    /** Quieter second line — a course's "City, State, Country". */
    sublabel?: string;
    /**
     * Pre-highlighted markup for the primary line, from Algolia's
     * `_highlightResult`. Rendered with v-html, so it must only ever be built
     * from Algolia's own output — never from user input.
     */
    html?: string;
}

/**
 * A course row, carrying the raw hit so the modal can fill the club name and the
 * locked location from the same selection.
 */
export interface CourseOption extends SuggestOption {
    hit: CourseHit;
}

/** A course hit off the Algolia `courses` index (see Course::toSearchableArray). */
export interface CourseHit {
    objectID: string;
    id: number;
    name?: string;
    club?: string;
    city?: string;
    state?: string;
    country?: string;
    _highlightResult?: Record<string, { value: string }>;
}

/** The browser-safe Algolia credentials the explorer page hands down. */
export interface AlgoliaProps {
    app_id: string;
    search_key: string;
    configured: boolean;
    indices: {
        courses: string;
        cities: string;
        states: string;
        countries: string;
    };
}
