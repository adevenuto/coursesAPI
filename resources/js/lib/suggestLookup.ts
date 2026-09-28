import type {
    AlgoliaProps,
    CourseHit,
    CourseOption,
    SuggestOption,
} from '@/types/search';

/**
 * The two backing stores behind the suggestion modal's typeaheads.
 *
 * Courses come from Algolia. Geo comes from our own `/geo/*` endpoints and NOT
 * from Algolia, because `shouldBeSearchable()` restricts the geo indices to
 * course-bearing rows — so a town with no courses, which is exactly the town a
 * "missing course" submitter needs, is absent there.
 */

type GeoKind = 'countries' | 'states' | 'cities';

/** Just the slice of the Algolia lite client this module uses. */
interface CourseSearchClient {
    search: (
        args: unknown,
    ) => Promise<{ results: Array<{ hits: CourseHit[] }> }>;
}

/** Cached for the tab's lifetime: geo names don't change, and backspacing is free. */
const geoCache = new Map<string, SuggestOption[]>();

/** One in-flight request per field, so an abandoned keystroke stops mattering. */
const inFlight = new Map<string, AbortController>();

export async function searchGeo(
    kind: GeoKind,
    params: { q: string; country?: number | null; state?: number | null },
): Promise<SuggestOption[]> {
    // A child whose parent never resolved has nothing to scope to; the server
    // requires the parent id, so asking would only earn a 422.
    if (kind === 'states' && !params.country) {
        return [];
    }

    if (kind === 'cities' && !params.state) {
        return [];
    }

    const query = new URLSearchParams({ q: params.q });

    if (kind === 'states' && params.country) {
        query.set('country', String(params.country));
    }

    if (kind === 'cities' && params.state) {
        query.set('state', String(params.state));
    }

    const url = `/geo/${kind}?${query.toString()}`;

    const cached = geoCache.get(url);

    if (cached) {
        return cached;
    }

    inFlight.get(kind)?.abort();
    const controller = new AbortController();
    inFlight.set(kind, controller);

    try {
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        if (!res.ok) {
            return [];
        }

        const body = (await res.json()) as { results?: SuggestOption[] };
        const results = body.results ?? [];

        geoCache.set(url, results);

        return results;
    } catch {
        // Includes the abort, which is a newer keystroke winning, not an error.
        return [];
    }
}

/**
 * Algolia over the `courses` index only. Returns `configured: false` when the
 * keys are absent so the modal can degrade to a plain text field rather than
 * offering a box that silently never responds.
 */
export function createCourseSearch(algolia: AlgoliaProps) {
    let client: CourseSearchClient | null = null;
    let ready: Promise<unknown> | null = null;

    function boot() {
        if (ready || typeof window === 'undefined' || !algolia.configured) {
            return ready;
        }

        ready = import('algoliasearch/lite').then(({ liteClient }) => {
            // Narrowed on purpose: liteClient's own `search` is generically typed
            // over every response shape Algolia can return, which won't assign to
            // the one shape we use. The cast is what keeps that generic signature
            // out of every call site.
            client = liteClient(
                algolia.app_id,
                algolia.search_key,
            ) as unknown as CourseSearchClient;
        });

        return ready;
    }

    return {
        configured: algolia.configured,
        async search(q: string): Promise<CourseOption[]> {
            await boot();

            if (!client) {
                return [];
            }

            const { results } = await client.search({
                requests: [
                    {
                        indexName: algolia.indices.courses,
                        query: q,
                        hitsPerPage: 8,
                    },
                ],
            });

            return (results[0]?.hits ?? []).map((hit) => ({
                id: hit.id,
                // What lands in the box: the course name, or the club when they match.
                label: hit.name || hit.club || '',
                sublabel: [hit.city, hit.state, hit.country]
                    .filter(Boolean)
                    .join(', '),
                html: highlighted(hit),
                // Carried through so the modal can fill club and location on select.
                hit,
            }));
        },
    };
}

/**
 * Algolia's own highlight markup, leading with the club for context and
 * appending the course when it differs — "Cog Hill · No. 4".
 *
 * Safe for v-html only because every branch reads Algolia's `_highlightResult`
 * or a field Algolia itself indexed, never raw user input.
 */
function highlighted(hit: CourseHit): string {
    const hr = hit._highlightResult ?? {};
    const name = hr.name?.value || hit.name || '';
    const club = hr.club?.value || hit.club || '';

    if (club && hit.club !== hit.name) {
        return name
            ? `${club} <span class="opacity-40">·</span> ${name}`
            : club;
    }

    return name || club;
}
