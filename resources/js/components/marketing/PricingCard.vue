<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { register } from '@/routes';

defineProps<{
    name: string;
    price: string;
    period?: string;
    requests: string;
    blurb: string;
    features: string[];
    cta: string;
    highlighted?: boolean;
}>();
</script>

<template>
    <div
        class="ds-card flex flex-col p-7"
        :class="highlighted ? 'ds-card--glow' : ''"
        :style="highlighted ? 'border-color: var(--border-lime)' : ''"
    >
        <div class="relative flex flex-1 flex-col">
            <span
                v-if="highlighted"
                class="ds-badge absolute -top-1 right-0 font-body text-[10px] font-semibold tracking-widest uppercase"
                style="background: var(--grad-lime); color: var(--text-on-lime)"
                >Most popular</span
            >
            <h3 class="font-display text-xl font-semibold text-fg">{{ name }}</h3>
            <p class="mt-1 text-sm text-fg-muted">{{ blurb }}</p>

            <div class="mt-5 flex items-end gap-1">
                <span class="font-display text-4xl font-bold text-fg">{{ price }}</span>
                <span v-if="period" class="mb-1 text-sm text-fg-muted">{{ period }}</span>
            </div>
            <div class="mt-2 font-mono text-xs" style="color: var(--lime-400)">
                {{ requests }}
            </div>

            <Link
                :href="register()"
                class="ds-btn mt-6 px-4 py-2.5 text-sm"
                :class="highlighted ? 'ds-btn--primary' : 'ds-btn--secondary'"
            >
                {{ cta }}
            </Link>

            <ul class="mt-7 space-y-3 border-t border-line pt-6">
                <li
                    v-for="f in features"
                    :key="f"
                    class="flex items-start gap-2.5 text-sm text-fg-muted"
                >
                    <Check class="mt-0.5 size-4 shrink-0" style="color: var(--lime-400)" />
                    <span>{{ f }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
