<script setup lang="ts">
// Eyebrow pill + two-line Sora title (optional lime payoff line) + lead.
withDefaults(
    defineProps<{
        eyebrow?: string;
        title: string;
        accent?: string;
        subtitle?: string;
        align?: 'left' | 'center';
    }>(),
    { eyebrow: '', accent: '', subtitle: '', align: 'left' },
);
</script>

<template>
    <div :class="align === 'center' ? 'mx-auto max-w-2xl text-center' : 'max-w-2xl'">
        <span v-if="eyebrow" class="ds-badge ds-badge--neutral font-body">
            {{ eyebrow }}
        </span>
        <h2
            class="mt-4 font-display text-3xl font-bold tracking-tight text-balance text-fg sm:text-4xl"
            style="letter-spacing: -0.02em; line-height: 1.12"
        >
            {{ title }}
            <span v-if="accent" style="color: var(--lime-400)">{{ accent }}</span>
        </h2>
        <p
            v-if="subtitle"
            class="mt-4 text-base text-pretty text-fg-muted sm:text-lg"
            style="line-height: 1.6"
        >
            {{ subtitle }}
        </p>
    </div>
</template>
