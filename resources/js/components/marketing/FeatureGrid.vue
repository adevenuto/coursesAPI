<script setup lang="ts">
import {
    Globe,
    Navigation,
    ClipboardList,
    Crosshair,
    Zap,
    KeyRound,
} from '@lucide/vue';
import SectionHeading from './SectionHeading.vue';
import FeatureCard from './FeatureCard.vue';

const features = [
    {
        icon: Globe,
        title: 'Worldwide coverage',
        description:
            'Thousands of courses across dozens of countries — names, clubs, addresses, phone and website.',
    },
    {
        icon: Navigation,
        title: 'Precise geolocation',
        description:
            'Every course resolved to city, state/province and country, with lat/lng and radius “near me” search.',
    },
    {
        icon: ClipboardList,
        title: 'Full scorecards',
        description:
            'Teeboxes with course/slope ratings and per-hole par, yardage and handicap — ready to render.',
    },
    {
        icon: Crosshair,
        title: 'Green-center GPS',
        description:
            'Per-hole green coordinates for rangefinder-grade distance features. The data nobody else gives away.',
        premium: true,
    },
    {
        icon: Zap,
        title: 'Fast, simple REST',
        description:
            'Clean JSON, cursor-friendly pagination, versioned endpoints and predictable errors. No SDK required.',
    },
    {
        icon: KeyRound,
        title: 'Keys & tiers',
        description:
            'Sign in, mint a key, ship. Scale from a free daily quota up to 100k requests/day when you grow.',
    },
];
</script>

<template>
    <section id="features" class="mx-auto max-w-[1120px] px-5 py-20 sm:px-7 lg:py-24">
        <SectionHeading
            eyebrow="What you get"
            title="Everything a golf app needs,"
            accent="in one API."
            subtitle="From a course list to the exact center of the 7th green — consistent, documented, and quick to integrate."
        />
        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <FeatureCard
                v-for="f in features"
                :key="f.title"
                :icon="f.icon"
                :title="f.title"
                :description="f.description"
                :premium="f.premium"
            />
        </div>
    </section>
</template>
