<script setup lang="ts">
import { computed } from 'vue';
import { useWindowScroll } from '@vueuse/core';
import { ArrowUp } from '@lucide/vue';

const { y } = useWindowScroll();
const show = computed(() => y.value > 600);

function toTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<template>
    <Transition name="stt">
        <button
            v-if="show"
            type="button"
            aria-label="Back to top"
            class="fixed right-5 bottom-5 z-50 grid size-11 place-items-center rounded-full border border-line-2 bg-ink-800 shadow-lg transition hover:border-line-lime sm:right-8 sm:bottom-8"
            @click="toTop"
        >
            <ArrowUp class="size-5" style="color: var(--lime-400)" />
        </button>
    </Transition>
</template>

<style scoped>
.stt-enter-active,
.stt-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}
.stt-enter-from,
.stt-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.9);
}
</style>
