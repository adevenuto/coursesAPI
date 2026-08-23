/**
 * Remembers the explorer's search across page transitions.
 *
 * The explorer's main job for an editor is a loop: search a city, open a
 * course, edit it, come back for the next one. Every Inertia visit unmounted
 * the page and threw the search away, so that loop meant retyping the city
 * every single time.
 *
 * sessionStorage rather than localStorage on purpose. The explorer is public,
 * so most visitors have no login session to end, and sessionStorage empties
 * when the tab closes — which is the "cleared when the session ends" behaviour
 * we want, with no cleanup code of our own to get wrong.
 *
 * Only the *selection* is stored, never the loaded courses: a radius search
 * around a dense city returns hundreds of rows, and re-fetching through the
 * page's existing loadArea() is both smaller and fresher than serialising them.
 */

/** The parts of a search hit needed to re-fetch its area. */
export interface StoredHit {
    id: number;
    type: 'course' | 'city' | 'state' | 'country';
    name?: string;
    label?: string;
    url: string;
}

/** Where the map was left: clicking a cluster zooms and pans, so both matter. */
export interface StoredView {
    lat: number;
    lng: number;
    zoom: number;
}

export interface StoredSearch {
    q: string;
    hit: StoredHit | null;
    radiusOn: boolean;
    radiusMiles: number;
    view: StoredView | null;
}

const KEY = 'gca.explorer.search';

/**
 * The app builds an SSR bundle, and sessionStorage doesn't exist on the server.
 * Every accessor goes through this, and reads must happen in onMounted rather
 * than at setup scope.
 */
function storage(): Storage | null {
    return typeof window === 'undefined' ? null : window.sessionStorage;
}

export function readExplorerSearch(): StoredSearch | null {
    const store = storage();
    if (!store) return null;

    try {
        const raw = store.getItem(KEY);
        if (!raw) return null;

        const parsed = JSON.parse(raw) as Partial<StoredSearch>;

        // Anything hand-edited or half-written degrades to "no saved search"
        // rather than a broken page.
        if (typeof parsed?.q !== 'string') return null;

        // `view` is absent from records written before it existed, so it
        // defaults rather than invalidating them.
        const view = parsed.view;
        const hasView =
            !!view && [view.lat, view.lng, view.zoom].every((n) => typeof n === 'number' && isFinite(n));

        return {
            q: parsed.q,
            hit: parsed.hit ?? null,
            radiusOn: !!parsed.radiusOn,
            radiusMiles: Number(parsed.radiusMiles) || 25,
            view: hasView ? { lat: view.lat, lng: view.lng, zoom: view.zoom } : null,
        };
    } catch {
        return null;
    }
}

export function writeExplorerSearch(state: StoredSearch): void {
    const store = storage();
    if (!store) return;

    try {
        store.setItem(KEY, JSON.stringify(state));
    } catch {
        // Safari in private browsing has historically thrown on any write.
        // Losing the saved search is fine; throwing inside a watcher is not.
    }
}

export function clearExplorerSearch(): void {
    try {
        storage()?.removeItem(KEY);
    } catch {
        // As above — never let storage break the page.
    }
}
