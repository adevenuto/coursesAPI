import { onMounted, ref } from 'vue';
import { useIntersectionObserver } from '@vueuse/core';

/**
 * Reveal-on-scroll helper. Returns a template ref to attach to an element and a
 * boolean that flips true (once) when it enters the viewport.
 */
export function useInView(threshold = 0.2) {
    const target = ref<HTMLElement | null>(null);
    const inView = ref(false);

    onMounted(() => {
        const { stop } = useIntersectionObserver(
            target,
            ([entry]) => {
                if (entry?.isIntersecting) {
                    inView.value = true;
                    stop();
                }
            },
            { threshold },
        );
    });

    return { target, inView };
}
