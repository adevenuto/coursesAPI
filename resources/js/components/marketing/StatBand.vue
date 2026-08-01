<script setup lang="ts">
import StatItem from './StatItem.vue';

defineProps<{
    stats: {
        courses: number;
        countries: number;
        cities: number;
        greenCenters: number;
    };
}>();
</script>

<template>
    <section class="border-y border-line bg-ink-850">
        <div
            class="mx-auto grid max-w-[1120px] grid-cols-1 gap-8 px-5 py-14 sm:grid-cols-3 sm:px-7"
        >
            <StatItem :value="stats.courses" label="Golf courses" />
            <StatItem :value="stats.countries" label="Countries" />
            <StatItem :value="stats.greenCenters" label="Green-center GPS" />
        </div>
    </section>
</template>
