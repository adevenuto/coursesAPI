<script setup lang="ts">
import SectionHeading from './SectionHeading.vue';
import PricingCard from './PricingCard.vue';

const plans = [
    {
        name: 'Free',
        price: '$0',
        period: '',
        requests: '250 requests / day',
        blurb: 'For prototypes and hobby projects.',
        cta: 'Get free key',
        features: [
            'Course search, filters & near-me',
            'Full scorecards & teeboxes',
            'City / state / country geo data',
            'Community support',
        ],
    },
    {
        name: 'Pro',
        price: '$49',
        period: '/mo',
        requests: '10,000 requests / day',
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
    {
        name: 'Max',
        price: '$199',
        period: '/mo',
        requests: '100,000 requests / day',
        blurb: 'For scale and heavy workloads.',
        cta: 'Start free',
        features: [
            'Everything in Pro',
            'Maximum daily quota',
            'Priority support',
            'Early access to new data',
        ],
    },
];
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
                v-for="p in plans"
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
