<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import { useDebounceFn, onClickOutside } from '@vueuse/core';
import { Building2, Flag, Globe, Loader2, Map, Search } from '@lucide/vue';

interface Hit {
    objectID: string;
    id: number;
    type: 'course' | 'city' | 'state' | 'country';
    name?: string;
    club?: string;
    label?: string;
    city?: string;
    state?: string;
    country?: string;
    url: string;
    _highlightResult?: Record<string, { value: string }>;
}

interface Group {
    key: string;
    label: string;
    icon: unknown;
    index: string;
    hits: Hit[];
}

const props = defineProps<{
    algolia: {
        app_id: string;
        search_key: string;
        configured: boolean;
        indices: { courses: string; cities: string; states: string; countries: string };
    };
}>();

const emit = defineEmits<{ (e: 'select', hit: Hit): void }>();

// Fixed display order + labels/icons, matching the reference layout.
const ORDER = [
    { key: 'courses', label: 'Courses', icon: Flag },
    { key: 'cities', label: 'Cities', icon: Building2 },
    { key: 'states', label: 'States', icon: Map },
    { key: 'countries', label: 'Countries', icon: Globe },
] as const;

const query = ref('');
const groups = ref<Group[]>([]);
const loading = ref(false);
const open = ref(false);
const active = ref(-1);
const root = ref<HTMLElement | null>(null);

// Algolia lite client loaded client-only (browser-only bundle).
const client = shallowRef<{ search: (args: unknown) => Promise<{ results: Array<{ hits: Hit[] }> }> } | null>(null);

onMounted(async () => {
    if (typeof window === 'undefined' || !props.algolia.configured) return;
    const { liteClient } = await import('algoliasearch/lite');
    client.value = liteClient(props.algolia.app_id, props.algolia.search_key);
});

// Flattened hit list for keyboard navigation.
const flat = computed(() => groups.value.flatMap((g) => g.hits));

const runSearch = useDebounceFn(async (q: string) => {
    if (!client.value || q.trim().length < 2) {
        groups.value = [];
        loading.value = false;
        return;
    }

    const requests = ORDER.map((o) => ({
        indexName: props.algolia.indices[o.key as keyof typeof props.algolia.indices],
        query: q,
        hitsPerPage: 5,
    }));

    try {
        const { results } = await client.value.search({ requests });
        // Ignore results that arrive after the input changed (e.g. cleared).
        if (q !== query.value) return;
        groups.value = ORDER.map((o, i) => ({
            key: o.key,
            label: o.label,
            icon: o.icon,
            index: requests[i].indexName,
            hits: results[i]?.hits ?? [],
        })).filter((g) => g.hits.length > 0);
        active.value = -1;
    } catch {
        groups.value = [];
    } finally {
        loading.value = false;
    }
}, 180);

// Search only on real typing — setting `query` on select must NOT reopen the
// dropdown or re-search the label.
function onInput() {
    active.value = -1;

    // Cleared / too short → hide the dropdown immediately (no waiting on debounce).
    if (query.value.trim().length < 2) {
        groups.value = [];
        loading.value = false;
        open.value = false;
        return;
    }

    open.value = true;
    loading.value = true;
    runSearch(query.value);
}

onClickOutside(root, () => (open.value = false));

function choose(hit: Hit) {
    query.value = hit.type === 'course' ? (hit.name ?? '') : (hit.label ?? '');
    open.value = false;
    emit('select', hit);
}

function onKeydown(e: KeyboardEvent) {
    if (!open.value || flat.value.length === 0) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = (active.value + 1) % flat.value.length;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = active.value <= 0 ? flat.value.length - 1 : active.value - 1;
    } else if (e.key === 'Enter' && active.value >= 0) {
        e.preventDefault();
        choose(flat.value[active.value]);
    } else if (e.key === 'Escape') {
        open.value = false;
    }
}

// Highlighted markup: courses match on name/club; geos match on label.
function highlighted(hit: Hit): string {
    const hr = hit._highlightResult ?? {};
    if (hit.type === 'course') {
        const name = hr.name?.value || hit.name || '';
        const club = hr.club?.value || hit.club || '';
        // Lead with the club for context; append the course when it differs
        // (e.g. "Cog Hill · 2", "Cantigny Golf · Woodside/Lakeside").
        if (club && hit.club !== hit.name) {
            return name ? `${club} <span class="sep">·</span> ${name}` : club;
        }
        return name || club;
    }
    return hr.label?.value || hit.label || hit.name || '';
}

function secondary(hit: Hit): string {
    if (hit.type !== 'course') return '';
    return [hit.city, hit.state, hit.country].filter(Boolean).join(', ');
}

const flatIndex = (g: number, h: number) =>
    groups.value.slice(0, g).reduce((n, grp) => n + grp.hits.length, 0) + h;
</script>

<template>
    <div ref="root" class="relative">
        <div class="relative">
            <Search class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-fg-subtle" />
            <input
                v-model="query"
                type="text"
                :disabled="!algolia.configured"
                placeholder="Search courses, cities, states, countries…"
                class="w-full rounded-xl border border-line bg-ink-800 py-3.5 pr-10 pl-11 text-fg placeholder:text-fg-subtle focus:border-line-lime focus:outline-none disabled:opacity-50"
                autocomplete="off"
                spellcheck="false"
                @focus="open = groups.length > 0"
                @input="onInput"
                @keydown="onKeydown"
            />
            <Loader2 v-if="loading" class="absolute top-1/2 right-4 size-4 -translate-y-1/2 animate-spin text-fg-subtle" />
        </div>

        <!-- dropdown -->
        <div
            v-if="open && (groups.length > 0 || (query.trim().length >= 2 && !loading))"
            class="no-scrollbar absolute z-40 mt-2 max-h-[45vh] w-full overflow-y-auto rounded-xl border border-line bg-ink-850 p-2 shadow-2xl"
        >
            <template v-if="groups.length">
                <div v-for="(g, gi) in groups" :key="g.key" class="mb-1 last:mb-0">
                    <div class="flex items-center gap-2 px-3 py-2 font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">
                        <component :is="g.icon" class="size-3.5 text-lime-500" />
                        {{ g.label }}
                    </div>
                    <button
                        v-for="(hit, hi) in g.hits"
                        :key="hit.objectID"
                        type="button"
                        class="flex w-full flex-col rounded-lg px-3 py-2 text-left transition-colors"
                        :class="active === flatIndex(gi, hi) ? 'bg-ink-700' : 'hover:bg-ink-800'"
                        @mouseenter="active = flatIndex(gi, hi)"
                        @click="choose(hit)"
                    >
                        <span class="hl text-sm text-fg" v-html="highlighted(hit)" />
                        <span v-if="secondary(hit)" class="text-xs text-fg-subtle">{{ secondary(hit) }}</span>
                    </button>
                </div>
            </template>
            <div v-else class="px-3 py-6 text-center text-sm text-fg-subtle">
                No matches for “{{ query }}”.
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Hide the native scrollbar while keeping the dropdown scrollable. */
.no-scrollbar {
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* legacy Edge/IE */
}
.no-scrollbar::-webkit-scrollbar {
    display: none; /* Chrome, Safari */
}

/* Muted separator between club and course name. */
.hl :deep(.sep) {
    color: var(--fg-subtle);
    padding: 0 2px;
}

/* Algolia wraps matched text in <em>; tint it lime instead of italic. */
.hl :deep(em) {
    font-style: normal;
    border-radius: 3px;
    padding: 0 2px;
    background: color-mix(in oklab, var(--lime-500) 26%, transparent);
    color: var(--lime-300);
}
</style>
