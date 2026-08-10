<script setup lang="ts">
import type { DiffHole } from './ScanDiff.vue';

defineProps<{ holes: DiffHole[] }>();

const rows = [
    { key: 'par', label: 'Par' },
    { key: 'length', label: 'Yards' },
    { key: 'handicap', label: 'SI' },
    { key: 'handicapWomen', label: 'SI (W)' },
] as const;

function show(value: string | number | null): string {
    return value === null || value === '' ? '—' : String(value);
}
</script>

<template>
    <!-- Wide by nature: 18 holes never fit a phone, so the grid scrolls inside
         itself rather than pushing the page sideways. -->
    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] border-collapse text-right font-mono text-[11px]">
            <thead>
                <tr class="border-b border-line text-fg-subtle">
                    <th class="py-1.5 pr-3 text-left font-normal">Hole</th>
                    <th v-for="hole in holes" :key="hole.hole" class="px-1 py-1.5 font-normal">
                        {{ hole.hole }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.key" class="border-b border-line/50 last:border-0">
                    <th class="py-1.5 pr-3 text-left font-normal text-fg-subtle">{{ row.label }}</th>
                    <td v-for="hole in holes" :key="hole.hole" class="px-1 py-1.5">
                        <template v-if="hole.cells[row.key].state === 'changed'">
                            <span class="block text-[10px] text-fg-subtle line-through">
                                {{ show(hole.cells[row.key].before) }}
                            </span>
                            <span class="block text-amber-400">{{ show(hole.cells[row.key].after) }}</span>
                        </template>
                        <span
                            v-else
                            :class="[
                                hole.cells[row.key].state === 'added' && 'text-lime-500',
                                hole.cells[row.key].state === 'unchanged' && 'text-fg-subtle',
                                hole.cells[row.key].state === 'skip' && 'text-fg-subtle/40',
                            ]"
                        >
                            {{ show(hole.cells[row.key].after) }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
