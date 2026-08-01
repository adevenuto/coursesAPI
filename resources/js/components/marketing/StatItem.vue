<script setup lang="ts">
import { ref, watch } from 'vue';
import { useInView } from '@/composables/useInView';

const props = withDefaults(
    defineProps<{ value: number; label: string; suffix?: string }>(),
    { suffix: '' },
);

const { target, inView } = useInView(0.4);
const display = ref(0);

watch(inView, (v) => {
    if (!v) return;
    const duration = 1400;
    const start = performance.now();
    const from = 0;
    const to = props.value;
    const tick = (now: number) => {
        const t = Math.min(1, (now - start) / duration);
        // easeOutExpo
        const eased = t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
        display.value = Math.round(from + (to - from) * eased);
        if (t < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
});

const formatted = (n: number) => n.toLocaleString('en-US');
</script>

<template>
    <div ref="target" class="text-center">
        <div
            class="font-display text-4xl font-bold tracking-tight sm:text-5xl"
            style="color: var(--lime-400); letter-spacing: -0.02em"
        >
            {{ formatted(display) }}<span>{{ suffix }}</span>
        </div>
        <div class="mt-2 text-sm text-fg-muted">{{ label }}</div>
    </div>
</template>
