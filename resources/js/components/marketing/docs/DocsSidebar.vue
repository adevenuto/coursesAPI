<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{ sections: { id: string; label: string }[] }>();

const active = ref(props.sections[0]?.id ?? '');
let observer: IntersectionObserver | null = null;

onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) active.value = entry.target.id;
            }
        },
        // Trigger when a section reaches the upper third (below the sticky nav).
        { rootMargin: '-80px 0px -70% 0px', threshold: 0 },
    );
    props.sections.forEach((s) => {
        const el = document.getElementById(s.id);
        if (el) observer!.observe(el);
    });
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <nav class="sticky top-24 hidden lg:block" aria-label="Documentation">
        <div class="mb-3 font-mono text-[11px] tracking-[0.2em] text-fg-subtle uppercase">
            On this page
        </div>
        <ul class="space-y-0.5 border-l border-line">
            <li v-for="s in sections" :key="s.id">
                <a
                    :href="`#${s.id}`"
                    class="-ml-px block border-l py-1.5 pl-4 text-sm transition"
                    :class="
                        active === s.id
                            ? 'border-mk-accent font-medium text-mk-accent'
                            : 'border-transparent text-fg-muted hover:text-fg'
                    "
                >
                    {{ s.label }}
                </a>
            </li>
        </ul>
    </nav>
</template>
