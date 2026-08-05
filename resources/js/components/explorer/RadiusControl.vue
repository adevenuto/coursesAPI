<script setup lang="ts">
import { Radius } from '@lucide/vue';

const props = withDefaults(
    defineProps<{
        enabled: boolean;
        miles: number;
        city: string;
        min?: number;
        max?: number;
    }>(),
    { min: 5, max: 60 },
);

const emit = defineEmits<{
    (e: 'update:enabled', v: boolean): void;
    (e: 'update:miles', v: number): void;
}>();
</script>

<template>
    <div class="ds-card p-4">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <Radius class="size-4 text-lime-500" />
                <span class="text-sm font-medium text-fg">Search nearby</span>
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="enabled"
                aria-label="Toggle radius search"
                class="relative h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors"
                :class="enabled ? 'bg-lime-500' : 'bg-ink-600'"
                @click="emit('update:enabled', !enabled)"
            >
                <span
                    class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white transition-transform"
                    :class="enabled ? 'translate-x-5' : ''"
                />
            </button>
        </div>

        <div v-if="enabled" class="mt-4">
            <p class="mb-2 text-xs text-fg-muted">
                Within <span class="font-medium text-fg tabular-nums">{{ miles }} mi</span> of {{ city }}
            </p>
            <input
                type="range"
                :min="min"
                :max="max"
                step="1"
                :value="miles"
                class="radius-slider w-full"
                aria-label="Search radius in miles"
                @input="emit('update:miles', Number(($event.target as HTMLInputElement).value))"
            />
        </div>
    </div>
</template>

<style scoped>
.radius-slider {
    -webkit-appearance: none;
    appearance: none;
    height: 4px;
    border-radius: 999px;
    background: var(--ink-600);
    outline: none;
    cursor: pointer;
}
.radius-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 16px;
    height: 16px;
    border-radius: 999px;
    background: var(--lime-500);
    border: 2px solid var(--ink-900);
    cursor: pointer;
}
.radius-slider::-moz-range-thumb {
    width: 16px;
    height: 16px;
    border-radius: 999px;
    background: var(--lime-500);
    border: none;
    cursor: pointer;
}
</style>
