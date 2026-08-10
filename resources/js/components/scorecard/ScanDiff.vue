<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Check, ChevronDown, Loader2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import TeeDiffGrid from './TeeDiffGrid.vue';

export interface DiffField {
    label: string;
    before: string | number | null;
    after: string | number | null;
    state: 'added' | 'changed' | 'unchanged' | 'skip';
}
export interface DiffHole {
    hole: number;
    cells: Record<string, DiffField>;
}
export interface DiffSection {
    key: string;
    label: string;
    kind: 'details' | 'tee';
    status: 'new' | 'update';
    color: string | null;
    counts: { added: number; changed: number; unchanged: number };
    fields: DiffField[];
    holes: DiffHole[];
}

const props = defineProps<{
    scanId: number;
    sections: DiffSection[];
    isNew: boolean;
}>();

// Everything is accepted by default: the common case is a good parse, and the
// editor is opting *out* of the parts that look wrong.
const accepted = ref<string[]>(props.sections.map((s) => s.key));
const expanded = ref<string[]>([]);
const processing = ref(false);

const allSelected = computed(() => accepted.value.length === props.sections.length);

function toggle(key: string, on: boolean | 'indeterminate') {
    accepted.value = on === true
        ? [...new Set([...accepted.value, key])]
        : accepted.value.filter((k) => k !== key);
}

function toggleAll() {
    accepted.value = allSelected.value ? [] : props.sections.map((s) => s.key);
}

function toggleExpanded(key: string) {
    expanded.value = expanded.value.includes(key)
        ? expanded.value.filter((k) => k !== key)
        : [...expanded.value, key];
}

function summary(section: DiffSection): string {
    const bits: string[] = [];
    if (section.counts.added) bits.push(`${section.counts.added} new`);
    if (section.counts.changed) bits.push(`${section.counts.changed} changed`);
    if (section.counts.unchanged) bits.push(`${section.counts.unchanged} unchanged`);
    return bits.join(' · ');
}

function show(value: string | number | null): string {
    return value === null || value === '' ? '—' : String(value);
}

function apply() {
    processing.value = true;
    router.post(
        `/scorecard-scans/${props.scanId}/apply`,
        { sections: accepted.value },
        { onFinish: () => (processing.value = false) },
    );
}
</script>

<template>
    <section class="ds-card p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">
                    {{ isNew ? 'Will be created' : 'Proposed changes' }}
                </h2>
                <p class="mt-2 text-sm text-fg-muted">
                    Uncheck anything that looks misread. Only what's checked gets saved — everything else stays as it is.
                </p>
            </div>
            <button
                type="button"
                class="shrink-0 text-sm text-fg-muted underline underline-offset-2 transition hover:text-fg"
                @click="toggleAll"
            >
                {{ allSelected ? 'Clear all' : 'Select all' }}
            </button>
        </div>

        <p v-if="!sections.length" class="mt-6 rounded-lg border border-line p-4 text-sm text-fg-subtle">
            This card matches what's already on the course — there's nothing to change.
        </p>

        <ul v-else class="mt-5 space-y-2">
            <li v-for="section in sections" :key="section.key" class="rounded-lg border border-line">
                <div class="flex items-center gap-3 px-4 py-3">
                    <Checkbox
                        :id="`section-${section.key}`"
                        :model-value="accepted.includes(section.key)"
                        @update:model-value="(v) => toggle(section.key, v)"
                    />
                    <label :for="`section-${section.key}`" class="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                        <span
                            v-if="section.kind === 'tee'"
                            class="size-3 shrink-0 rounded-full border border-line"
                            :style="{ backgroundColor: section.color ?? 'transparent' }"
                        />
                        <span class="truncate text-sm font-medium text-fg">{{ section.label }}</span>
                        <span
                            v-if="section.status === 'new'"
                            class="ds-badge ds-badge--lime shrink-0 text-[10px]"
                        >new</span>
                    </label>
                    <span class="hidden shrink-0 font-mono text-[11px] text-fg-subtle sm:inline">
                        {{ summary(section) }}
                    </span>
                    <button
                        type="button"
                        class="shrink-0 rounded p-1 text-fg-subtle transition hover:text-fg"
                        :aria-label="`Show details for ${section.label}`"
                        @click="toggleExpanded(section.key)"
                    >
                        <ChevronDown
                            class="size-4 transition-transform"
                            :class="expanded.includes(section.key) && 'rotate-180'"
                        />
                    </button>
                </div>

                <div v-if="expanded.includes(section.key)" class="border-t border-line px-4 py-3">
                    <dl v-if="section.fields.length" class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        <div
                            v-for="field in section.fields"
                            :key="field.label"
                            class="flex items-baseline justify-between gap-3 text-sm"
                        >
                            <dt class="text-fg-subtle">{{ field.label }}</dt>
                            <dd class="font-mono text-xs">
                                <span
                                    v-if="field.state === 'changed'"
                                    class="text-fg-subtle line-through"
                                >{{ show(field.before) }}</span>
                                <span
                                    :class="[
                                        'ml-2',
                                        field.state === 'added' && 'text-lime-500',
                                        field.state === 'changed' && 'text-amber-400',
                                        field.state === 'unchanged' && 'text-fg-subtle',
                                    ]"
                                >{{ show(field.after) }}</span>
                            </dd>
                        </div>
                    </dl>

                    <TeeDiffGrid v-if="section.holes.length" class="mt-4" :holes="section.holes" />
                </div>
            </li>
        </ul>

        <div v-if="sections.length" class="mt-6 flex flex-wrap items-center gap-3 border-t border-line pt-5">
            <Button type="button" :disabled="!accepted.length || processing" @click="apply">
                <Loader2 v-if="processing" class="size-4 animate-spin" />
                <Check v-else class="size-4" />
                {{ processing ? 'Saving…' : `Apply ${accepted.length} of ${sections.length}` }}
            </Button>
            <p v-if="isNew" class="text-sm text-fg-subtle">
                A scorecard has no location — you'll set that on the course afterwards.
            </p>
        </div>
    </section>
</template>
