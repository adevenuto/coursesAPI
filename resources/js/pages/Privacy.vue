<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';

const props = defineProps<{
    retentionDays: number;
    ipMode: string;
    updated: string;
}>();

// Described from config rather than hardcoded prose, so the published
// commitment can't drift from what the code actually does.
const ipDescription = computed(() => {
    switch (props.ipMode) {
        case 'full':
            return 'your full IP address';
        case 'hashed':
            return 'a one-way hash of your IP address';
        default:
            return 'a truncated form of your IP address (the final block is removed, so it identifies a network rather than a device)';
    }
});
</script>

<template>
    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[760px] px-5 py-16 sm:px-7">
            <h1 class="font-display text-3xl font-bold tracking-tight text-fg sm:text-4xl">Privacy Policy</h1>
            <p class="mt-2 text-sm text-fg-subtle">Last updated {{ updated }}</p>

            <div class="mt-10 space-y-10 text-fg-muted">
                <section>
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Account information</h2>
                    <p class="mt-3 text-sm leading-relaxed">
                        When you register we store your name, email address and password (hashed, never
                        readable). If you subscribe to a paid plan, payment details are handled by Stripe
                        and never touch our servers — we keep only a customer reference and your plan.
                    </p>
                </section>

                <section>
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">API request logs</h2>
                    <p class="mt-3 text-sm leading-relaxed">
                        Every call to the API is recorded so we can enforce rate limits, investigate abuse,
                        and understand which parts of the service are used. Each record contains:
                    </p>
                    <ul class="mt-4 space-y-2 text-sm">
                        <li class="flex gap-2"><span class="text-lime-500">·</span> the endpoint called, the HTTP status returned, and how long it took</li>
                        <li class="flex gap-2"><span class="text-lime-500">·</span> which of your API keys was used</li>
                        <li class="flex gap-2"><span class="text-lime-500">·</span> the search terms and filters you sent (for example a course name)</li>
                        <li class="flex gap-2"><span class="text-lime-500">·</span> {{ ipDescription }}</li>
                        <li class="flex gap-2"><span class="text-lime-500">·</span> your client's user-agent string (for example <code class="font-mono text-xs">curl</code> or <code class="font-mono text-xs">python-requests</code>)</li>
                    </ul>
                    <p class="mt-4 text-sm leading-relaxed">
                        Coordinates sent to location searches are rounded to roughly one kilometre before
                        being stored.
                    </p>
                    <p class="mt-4 text-sm leading-relaxed">
                        We rely on legitimate interest as the lawful basis for this: running a metered API
                        is not possible without counting and attributing requests.
                    </p>
                </section>

                <section>
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">How long we keep it</h2>
                    <p class="mt-3 text-sm leading-relaxed">
                        Individual request records are deleted after
                        <strong class="text-fg">{{ retentionDays }} days</strong>.
                    </p>
                    <p class="mt-4 text-sm leading-relaxed">
                        We keep a daily total of how many requests each account made indefinitely, because
                        it underpins billing. That total is a single number per account per day and contains
                        no IP address, search term or other personal detail.
                    </p>
                </section>

                <section>
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Who can see it</h2>
                    <p class="mt-3 text-sm leading-relaxed">
                        You can see your own usage on your dashboard, broken down by API key and endpoint.
                        Administrators see aggregate figures — totals, trends and per-account request
                        counts — not the contents of individual requests. We do not sell your data or share
                        it with third parties for advertising.
                    </p>
                </section>

                <section>
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Deleting your data</h2>
                    <p class="mt-3 text-sm leading-relaxed">
                        Deleting your account removes your profile, your API keys and every request record
                        associated with them. You can do this yourself from
                        <Link href="/settings/profile" class="text-lime-500 underline underline-offset-2">your profile settings</Link>.
                    </p>
                </section>

                <section>
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Cookies</h2>
                    <p class="mt-3 text-sm leading-relaxed">
                        We use cookies only to keep you signed in and to remember interface preferences such
                        as light or dark mode. There are no advertising or third-party tracking cookies.
                    </p>
                </section>

                <section>
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Contact</h2>
                    <p class="mt-3 text-sm leading-relaxed">
                        For any question about this policy, or to request a copy or deletion of your data,
                        email
                        <a href="mailto:support@golfcoursesapi.com" class="text-lime-500 underline underline-offset-2">support@golfcoursesapi.com</a>.
                    </p>
                </section>
            </div>
        </div>

        <MarketingFooter />
    </MarketingLayout>
</template>
