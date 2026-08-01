<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import HeroSection from '@/components/marketing/HeroSection.vue';
import StatBand from '@/components/marketing/StatBand.vue';
import FeatureGrid from '@/components/marketing/FeatureGrid.vue';
import EndpointExplorer from '@/components/marketing/EndpointExplorer.vue';
import GreenCenterShowcase from '@/components/marketing/GreenCenterShowcase.vue';
import PricingSection from '@/components/marketing/PricingSection.vue';
import CtaSection from '@/components/marketing/CtaSection.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import ScrollToTop from '@/components/marketing/ScrollToTop.vue';

withDefaults(
    defineProps<{
        stats?: {
            courses: number;
            countries: number;
            cities: number;
            greenCenters: number;
        };
    }>(),
    {
        stats: () => ({
            courses: 22066,
            countries: 88,
            cities: 152970,
            greenCenters: 9975,
        }),
    },
);
</script>

<template>
    <Head title="Fairway — The Golf Course Data API">
        <meta
            name="description"
            content="Thousands of golf courses worldwide — locations, scorecards and per-hole green-center GPS — behind one clean, fast REST API. Get a free key."
        />
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link
            rel="preconnect"
            href="https://fonts.gstatic.com"
            crossorigin
        />
    </Head>

    <MarketingLayout>
        <MarketingNav />
        <main>
            <HeroSection />
            <StatBand :stats="stats" />
            <FeatureGrid />
            <EndpointExplorer />
            <GreenCenterShowcase />
            <PricingSection />
            <CtaSection />
        </main>
        <MarketingFooter />
        <ScrollToTop />
    </MarketingLayout>
</template>
