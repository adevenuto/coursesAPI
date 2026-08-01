<script setup lang="ts">
import { Check } from '@lucide/vue';
import GlowBadge from './GlowBadge.vue';
import ContourGreen from './ContourGreen.vue';

const points = [
    'Per-hole green-center coordinates, not just a course pin',
    'Rangefinder-grade accuracy — validated to a few meters',
    'Sourced, cross-checked and labeled by hole',
    'One field away from “distance to the green” in your app',
];
</script>

<template>
    <section class="mx-auto max-w-[1120px] px-5 py-20 sm:px-7 lg:py-24">
        <div class="grid items-center gap-12 lg:grid-cols-[0.9fr_1.1fr]">
            <div class="order-2 lg:order-1">
                <ContourGreen
                    class="mx-auto max-w-sm"
                    coord="34.11744, -86.43135"
                    hole="HOLE 1 · GREEN"
                />
            </div>
            <div class="order-1 lg:order-2">
                <GlowBadge>The premium layer</GlowBadge>
                <h2
                    class="mt-6 font-display text-3xl font-bold tracking-tight text-balance text-fg sm:text-4xl"
                    style="letter-spacing: -0.02em; line-height: 1.12"
                >
                    Green-center GPS most APIs
                    <span style="color: var(--lime-400)">simply don't have.</span>
                </h2>
                <p class="mt-4 max-w-xl text-lg text-pretty text-fg-muted">
                    The center of every green, hole by hole — the hard-won data
                    that powers real distance features. Available on Pro and Max
                    plans.
                </p>
                <ul class="mt-8 space-y-3">
                    <li
                        v-for="p in points"
                        :key="p"
                        class="flex items-start gap-3 text-fg"
                    >
                        <span
                            class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full"
                            style="
                                background: rgba(138, 230, 60, 0.15);
                                color: var(--lime-400);
                            "
                        >
                            <Check class="size-3.5" />
                        </span>
                        <span class="text-sm sm:text-base">{{ p }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
