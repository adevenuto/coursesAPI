<script setup lang="ts">
import { Loader2, X } from '@lucide/vue';
import { useDebounceFn, onClickOutside } from '@vueuse/core';
import { computed, ref, nextTick } from 'vue';
import type { SuggestOption } from '@/types/search';

/**
 * One typeahead field for the suggestion modal — used four times, over two
 * different backing stores. It knows nothing about where results come from; the
 * `search` prop is its only data dependency.
 *
 * Deliberately not a refactor of CourseSearch.vue. That component carries
 * explorer-specific behaviour this must not inherit (a page-owned query model,
 * revert-on-blur map semantics, sessionStorage restore), and it is the most-used
 * control on the page with no JS tests to catch a regression. The duplication
 * between them is the cheaper risk.
 */

const props = withDefaults(
    defineProps<{
        search: (q: string) => Promise<SuggestOption[]>;
        id: string;
        disabled?: boolean;
        placeholder?: string;
        /** Shown under the field instead of suggestions — e.g. parent unresolved. */
        hint?: string;
        minChars?: number;
        invalid?: boolean;
    }>(),
    { minChars: 2 },
);

const emit = defineEmits<{
    (e: 'select', option: SuggestOption): void;
    (e: 'clear'): void;
}>();

const query = defineModel<string>({ default: '' });

const options = ref<SuggestOption[]>([]);
const loading = ref(false);
const open = ref(false);
const active = ref(-1);
const root = ref<HTMLElement | null>(null);
const list = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);

const canSuggest = computed(() => !props.disabled && !props.hint);

const runSearch = useDebounceFn(async (q: string) => {
    if (!canSuggest.value || q.trim().length < props.minChars) {
        options.value = [];
        loading.value = false;

        return;
    }

    try {
        const found = await props.search(q);

        // Stale-response guard: a slower earlier request must not overwrite the
        // results for what's in the box now.
        if (q !== query.value) {
            return;
        }

        options.value = found;
        active.value = -1;
        open.value = true;

        // An in-flow dropdown near the bottom of the modal's scroll body would
        // otherwise open out of sight.
        await nextTick();
        list.value?.scrollIntoView({ block: 'nearest' });
    } catch {
        options.value = [];
    } finally {
        loading.value = false;
    }
}, 180);

/**
 * Bound to @input, never to a watcher on `query`. Programmatic writes — a
 * selection, a cascade clear, a reset — must not reopen the dropdown or
 * re-search the label we just put in the box.
 */
function onInput() {
    if (!canSuggest.value) {
        return;
    }

    // Typing invalidates any previous pick: the text no longer describes it.
    emit('clear');

    if (query.value.trim().length < props.minChars) {
        options.value = [];
        open.value = false;
        loading.value = false;

        return;
    }

    loading.value = true;
    void runSearch(query.value);
}

function choose(option: SuggestOption) {
    query.value = option.label;
    options.value = [];
    open.value = false;
    active.value = -1;
    emit('select', option);
}

function clear() {
    query.value = '';
    options.value = [];
    open.value = false;
    active.value = -1;
    loading.value = false;
    emit('clear');
    input.value?.focus();
}

function onKeydown(e: KeyboardEvent) {
    // Escape closes the dropdown, not the dialog. stopPropagation keeps it from
    // reaching reka-ui's dismissable layer, which would take the whole modal —
    // and everything typed into it — down with it.
    if (e.key === 'Escape' && open.value) {
        e.stopPropagation();
        open.value = false;

        return;
    }

    if (!open.value || options.value.length === 0) {
        return;
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = (active.value + 1) % options.value.length;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value =
            active.value <= 0 ? options.value.length - 1 : active.value - 1;
    } else if (e.key === 'Enter') {
        // Always prevent, active row or not: this field lives inside a <form>,
        // where a bare Enter would submit a half-filled suggestion.
        e.preventDefault();

        if (active.value >= 0) {
            choose(options.value[active.value]);
        }
    }
}

onClickOutside(root, () => (open.value = false));
</script>

<template>
    <div ref="root">
        <div class="relative">
            <input
                :id="id"
                ref="input"
                v-model="query"
                type="text"
                :disabled="disabled"
                :placeholder="placeholder"
                :aria-invalid="invalid || undefined"
                class="ds-input pr-10"
                autocomplete="off"
                spellcheck="false"
                @input="onInput"
                @keydown="onKeydown"
            />

            <!-- One slot, two states — they must never render together. -->
            <Loader2
                v-if="loading"
                class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-fg-subtle"
            />
            <button
                v-else-if="query.length > 0 && !disabled"
                type="button"
                aria-label="Clear"
                class="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full text-fg-subtle transition hover:bg-ink-700 hover:text-fg focus:outline-none focus-visible:ring-1 focus-visible:ring-line-lime"
                @click="clear"
            >
                <X class="size-4" />
            </button>
        </div>

        <!--
            In flow, not absolute. The modal's DialogContent is overflow-hidden
            and its body overflow-y-auto, so an absolutely positioned dropdown is
            clipped. In flow it also stays inside the dialog's focus trap, which
            a portaled one would escape.
        -->
        <div
            v-if="open && options.length > 0"
            ref="list"
            class="mt-2 max-h-56 overflow-y-auto overscroll-contain rounded-lg border border-line bg-ink-850 p-1"
        >
            <button
                v-for="(option, i) in options"
                :key="option.id"
                type="button"
                class="flex w-full flex-col rounded-md px-3 py-2 text-left transition-colors"
                :class="active === i ? 'bg-ink-700' : 'hover:bg-ink-800'"
                @mouseenter="active = i"
                @click="choose(option)"
            >
                <span
                    v-if="option.html"
                    class="hl text-sm text-fg"
                    v-html="option.html"
                />
                <span v-else class="text-sm text-fg">{{ option.label }}</span>
                <span v-if="option.sublabel" class="text-xs text-fg-subtle">{{
                    option.sublabel
                }}</span>
            </button>
        </div>

        <p v-if="hint" class="mt-1 text-xs text-fg-subtle">{{ hint }}</p>
    </div>
</template>

<style scoped>
/* Algolia returns <em> around matches; de-italicise and tint instead. */
.hl :deep(em) {
    font-style: normal;
    color: var(--lime-400);
}
</style>
