<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { register } from '@/routes';
</script>

<template>
    <section class="mx-auto max-w-[1120px] px-5 pb-24 sm:px-7">
        <div
            class="ds-card ds-card--glow relative overflow-hidden px-8 py-16 text-center aurora sm:px-12"
        >
            <div class="relative">
                <h2
                    class="mx-auto max-w-2xl font-display text-3xl font-bold tracking-tight text-balance text-fg sm:text-4xl md:text-5xl"
                    style="letter-spacing: -0.02em; line-height: 1.08"
                >
                    Build your golf app on data
                    <span style="color: var(--lime-400)">you can trust.</span>
                </h2>
                <p class="mx-auto mt-4 max-w-lg text-pretty text-fg-muted">
                    Grab a free key and pull your first course in the next five
                    minutes.
                </p>
                <Link
                    :href="register()"
                    class="ds-btn ds-btn--primary group mt-8 px-6 py-3.5 text-[15px]"
                >
                    Get your free key
                    <ArrowRight class="size-4 transition group-hover:translate-x-0.5" />
                </Link>
            </div>
        </div>
    </section>
</template>
