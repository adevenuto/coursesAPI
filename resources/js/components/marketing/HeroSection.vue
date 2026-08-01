<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, CircleCheck } from '@lucide/vue';
import { register } from '@/routes';
import GlowBadge from './GlowBadge.vue';
import ContourGreen from './ContourGreen.vue';
</script>

<template>
    <section id="top" class="relative overflow-hidden aurora">
        <div
            class="mx-auto grid max-w-[1120px] items-center gap-12 px-5 pt-16 pb-12 sm:px-7 lg:grid-cols-[1.05fr_0.95fr] lg:pt-24 lg:pb-20"
        >
            <!-- copy -->
            <div>
                <div class="mk-reveal" style="animation-delay: 0.05s">
                    <GlowBadge>Live · 22,066 courses</GlowBadge>
                </div>
                <h1
                    class="mk-reveal mt-6 font-display font-bold text-balance text-fg"
                    style="
                        font-size: clamp(2.75rem, 6vw, 4rem);
                        line-height: 1.04;
                        letter-spacing: -0.03em;
                    "
                    :style="{ animationDelay: '0.12s' }"
                >
                    Golf course data.<br />
                    Down to the <span style="color: var(--lime-400)">green.</span>
                </h1>
                <p
                    class="mk-reveal mt-6 max-w-xl text-lg text-pretty text-fg-muted"
                    style="line-height: 1.6; animation-delay: 0.2s"
                >
                    Thousands of courses worldwide — locations, scorecards, and
                    per-hole green-center GPS — behind one clean, fast REST API.
                    Get a free key and make your first call in minutes.
                </p>
                <div
                    class="mk-reveal mt-8 flex flex-wrap items-center gap-3"
                    style="animation-delay: 0.28s"
                >
                    <Link
                        :href="register()"
                        class="ds-btn ds-btn--primary group px-6 py-3.5 text-[15px]"
                    >
                        Get your free key
                        <ArrowRight class="size-4 transition group-hover:translate-x-0.5" />
                    </Link>
                    <a href="#endpoints" class="ds-btn ds-btn--secondary px-6 py-3.5 text-[15px]">
                        Explore endpoints
                    </a>
                </div>
                <div
                    class="mk-reveal mt-6 flex flex-wrap gap-6"
                    style="animation-delay: 0.34s"
                >
                    <span class="flex items-center gap-2 text-sm text-fg-subtle">
                        <CircleCheck class="size-4" style="color: var(--lime-500)" />
                        No credit card
                    </span>
                    <span class="flex items-center gap-2 text-sm text-fg-subtle">
                        <CircleCheck class="size-4" style="color: var(--lime-500)" />
                        250 requests/day free
                    </span>
                </div>
            </div>

            <!-- visual -->
            <div class="mk-reveal flex justify-center" style="animation-delay: 0.24s">
                <ContourGreen class="w-full max-w-md" />
            </div>
        </div>
    </section>
</template>
