<script setup lang="ts">
import { computed } from 'vue';
import SectionHeading from './SectionHeading.vue';
import PricingCard from './PricingCard.vue';

interface PlanConfig {
    label: string;
    per_day: number;
    per_minute: number;
    premium: boolean;
}

const props = defineProps<{
    plans: Record<'free' | 'pro' | 'max', PlanConfig>;
}>();

// Marketing copy lives here; the per-day quota comes from config/api.php.
const copy: Record<'free' | 'pro' | 'max', {
    price: string;
    period: string;
    blurb: string;
    cta: string;
    highlighted?: boolean;
    features: string[];
}> = {
    free: {
        price: '$0',
        period: '',
        blurb: 'For prototypes and hobby projects.',
        cta: 'Get free key',
        features: [
            'Course search, filters & near-me',
            'Full scorecards & teeboxes',
            'City / state / country geo data',
            'Community support',
        ],
    },
    pro: {
        price: '$49',
        period: '/mo',
        blurb: 'For production apps that ship.',
        cta: 'Start free',
        highlighted: true,
        features: [
            'Everything in Free',
            'Green-center GPS (per-hole)',
            'Higher rate limits & burst',
            'Email support',
        ],
    },
    max: {
        price: '$199',
        period: '/mo',
        blurb: 'For scale and heavy workloads.',
        cta: 'Start free',
        features: [
            'Everything in Pro',
            'Maximum daily quota',
            'Priority support',
            'Early access to new data',
        ],
    },
};

const tiers = computed(() =>
    (['free', 'pro', 'max'] as const).map((key) => ({
        name: props.plans[key].label,
        requests: `${props.plans[key].per_day.toLocaleString()} requests / day`,
        ...copy[key],
    })),
);
</script>

<template>
    <section id="pricing" class="mx-auto max-w-[1120px] px-5 py-20 sm:px-7 lg:py-24">
        <SectionHeading
            align="center"
            eyebrow="Pricing"
            title="Start free."
            accent="Scale when you're ready."
            subtitle="Plans are set by daily request volume. Green-center GPS unlocks on Pro and up."
        />
        <div class="mt-14 grid gap-6 lg:grid-cols-3">
            <PricingCard
                v-for="p in tiers"
                :key="p.name"
                v-bind="p"
            />
        </div>
        <p class="mt-8 text-center font-mono text-xs text-fg-subtle">
            Early access — everyone starts on Free today; paid tiers are being
            onboarded manually while billing launches.
        </p>
    </section>
</template>
