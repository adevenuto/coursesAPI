<script setup lang="ts">
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

interface PlanConfig {
    label: string;
    per_day: number;
    per_minute: number;
    premium: boolean;
}

withDefaults(
    defineProps<{
        stats?: {
            courses: number;
            countries: number;
            cities: number;
            greenCenters: number;
        };
        plans?: Record<'free' | 'pro' | 'max', PlanConfig>;
    }>(),
    {
        stats: () => ({
            courses: 22066,
            countries: 88,
            cities: 152970,
            greenCenters: 9975,
        }),
        plans: () => ({
            free: { label: 'Free', per_day: 30, per_minute: 30, premium: false },
            pro: { label: 'Pro', per_day: 10000, per_minute: 120, premium: true },
            max: { label: 'Max', per_day: 100000, per_minute: 600, premium: true },
        }),
    },
);
</script>

<template>
    <MarketingLayout>
        <MarketingNav />
        <main>
            <HeroSection :free-per-day="plans.free.per_day" :courses="stats.courses" />
            <StatBand :stats="stats" />
            <FeatureGrid />
            <EndpointExplorer />
            <GreenCenterShowcase />
            <PricingSection :plans="plans" />
            <CtaSection />
        </main>
        <MarketingFooter />
        <ScrollToTop />
    </MarketingLayout>
</template>
