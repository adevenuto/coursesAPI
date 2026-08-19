<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Check, CreditCard, Crown, ExternalLink, Zap } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { nf } from '@/lib/format';

interface PlanCard {
    key: string;
    label: string;
    price: number;
    per_day: number;
    per_minute: number;
    premium: boolean;
    billable: boolean;
}

interface Subscription {
    active: boolean;
    on_grace_period: boolean;
    canceled: boolean;
    past_due: boolean;
    ends_at: string | null;
    renews_at?: string | null;
}

interface Invoice {
    id: string;
    date: string;
    total: string;
    url: string;
}

const props = defineProps<{
    configured: boolean;
    currentPlan: string;
    plans: PlanCard[];
    subscription: Subscription | null;
    invoices: Invoice[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Billing', href: '/settings/billing' }],
    },
});

const money = (n: number) =>
    n === 0 ? 'Free' : `$${n.toFixed(2)}`;

const current = computed(() => props.plans.find((p) => p.key === props.currentPlan) ?? null);
const subscribed = computed(() => !!props.subscription?.active);

// ?checkout=success|cancelled after returning from Stripe.
const checkoutResult = computed(() => new URLSearchParams(window.location.search).get('checkout'));

const iconFor = (key: string) => (key === 'max' ? Crown : key === 'pro' ? Zap : Check);

// Swap confirmation (only for an in-place plan change on an existing sub).
const pending = ref<PlanCard | null>(null);
const confirmOpen = ref(false);
const swapping = ref(false);
const willResume = computed(() => !!props.subscription?.on_grace_period);

const choose = (plan: PlanCard) => {
    if (plan.key === props.currentPlan || !plan.billable || !props.configured) return;

    // Already subscribed → this is an in-place swap that bills the card on
    // file. Confirm first so the change (and any resume) is never a surprise.
    if (subscribed.value) {
        pending.value = plan;
        confirmOpen.value = true;
        return;
    }

    // No subscription yet → hosted Stripe checkout handles confirmation itself.
    router.post('/settings/billing/checkout', { plan: plan.key }, { preserveScroll: true });
};

const confirmSwap = () => {
    if (!pending.value) return;
    swapping.value = true;
    router.post(
        '/settings/billing/checkout',
        { plan: pending.value.key },
        {
            preserveScroll: true,
            onFinish: () => {
                swapping.value = false;
                confirmOpen.value = false;
                pending.value = null;
            },
        },
    );
};

const manage = () => router.post('/settings/billing/portal', {}, { preserveScroll: true });

const ctaLabel = (plan: PlanCard): string => {
    if (plan.key === props.currentPlan) return 'Current plan';
    if (!plan.billable) return 'Free tier';
    if (!subscribed.value) return `Upgrade to ${plan.label}`;
    // Already subscribed to a different paid tier.
    const currentPrice = current.value?.price ?? 0;
    return plan.price > currentPrice ? `Upgrade to ${plan.label}` : `Switch to ${plan.label}`;
};
</script>

<template>
    <div class="flex flex-col space-y-8">
        <Heading
            variant="small"
            title="Billing"
            description="Manage your subscription, payment method, and invoices."
        />

        <!-- Not-configured (dev) banner -->
        <div
            v-if="!configured"
            class="rounded-xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm text-amber-700 dark:text-amber-400"
        >
            Billing isn't fully configured yet. Add your Stripe keys and price IDs to
            <code class="font-mono">.env</code> to enable checkout.
        </div>

        <!-- Return-from-checkout banner -->
        <div
            v-if="checkoutResult === 'success'"
            class="rounded-xl border border-emerald-500/40 bg-emerald-500/5 p-4 text-sm text-emerald-700 dark:text-emerald-400"
        >
            Payment received — welcome aboard. Your new plan is active.
        </div>
        <div
            v-else-if="checkoutResult === 'cancelled'"
            class="rounded-xl border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
        >
            Checkout cancelled. You can pick a plan whenever you're ready.
        </div>

        <!-- Current subscription summary -->
        <div class="rounded-xl border border-border p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <component :is="iconFor(currentPlan)" class="size-4 text-emerald-600 dark:text-emerald-400" />
                        <span class="text-sm font-medium">Current plan</span>
                    </div>
                    <p class="mt-1 text-2xl font-semibold tracking-tight">
                        {{ current?.label ?? currentPlan }}
                        <span v-if="current" class="text-base font-normal text-muted-foreground">
                            · {{ money(current.price) }}<span v-if="current.price > 0">/mo</span>
                        </span>
                    </p>
                    <p v-if="current" class="mt-1 text-sm text-muted-foreground">
                        {{ nf(current.per_day) }} requests/day · {{ nf(current.per_minute) }}/min burst
                    </p>
                </div>

                <Button v-if="subscribed" variant="outline" @click="manage">
                    <CreditCard class="size-4" /> Manage billing
                </Button>
            </div>

            <!-- status line -->
            <div v-if="subscription" class="mt-4 border-t border-border pt-4 text-sm">
                <p v-if="subscription.on_grace_period" class="text-amber-600 dark:text-amber-400">
                    Your subscription is set to cancel and ends on
                    <span class="font-medium">{{ subscription.ends_at }}</span>. You keep access until then.
                </p>
                <p v-else-if="subscription.past_due" class="text-destructive">
                    Your last payment failed. Update your payment method to keep your plan active.
                </p>
                <p v-else-if="subscription.renews_at" class="text-muted-foreground">
                    Renews on <span class="font-medium text-foreground">{{ subscription.renews_at }}</span>.
                </p>
            </div>
        </div>

        <!-- Plan chooser -->
        <div>
            <h3 class="text-sm font-medium">Plans</h3>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                <div
                    v-for="plan in plans"
                    :key="plan.key"
                    class="group flex flex-col rounded-xl border p-5 transition-colors duration-200"
                    :class="
                        plan.key === currentPlan
                            ? 'border-emerald-500/50 bg-emerald-500/5'
                            : 'border-border hover:border-emerald-500/40 hover:bg-emerald-500/5'
                    "
                >
                    <div class="flex items-center gap-2">
                        <component
                            :is="iconFor(plan.key)"
                            class="size-4 transition-colors duration-200"
                            :class="
                                plan.key === currentPlan
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-muted-foreground group-hover:text-emerald-600 dark:group-hover:text-emerald-400'
                            "
                        />
                        <span class="font-medium">{{ plan.label }}</span>
                        <span
                            v-if="plan.key === currentPlan"
                            class="ml-auto rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-medium tracking-wide text-emerald-700 uppercase dark:text-emerald-400"
                        >Active</span>
                    </div>
                    <p class="mt-3 text-2xl font-semibold tracking-tight">
                        {{ money(plan.price) }}<span v-if="plan.price > 0" class="text-sm font-normal text-muted-foreground">/mo</span>
                    </p>
                    <ul class="mt-4 flex-1 space-y-1.5 text-sm text-muted-foreground">
                        <li>{{ nf(plan.per_day) }} requests / day</li>
                        <li>{{ nf(plan.per_minute) }} / min burst</li>
                        <li>{{ plan.premium ? 'Green-center data' : 'Course + geo data' }}</li>
                    </ul>
                    <Button
                        class="mt-5"
                        :variant="plan.key === currentPlan ? 'secondary' : 'default'"
                        :disabled="plan.key === currentPlan || !plan.billable || !configured"
                        @click="choose(plan)"
                    >
                        {{ ctaLabel(plan) }}
                    </Button>
                </div>
            </div>
            <p v-if="subscribed" class="mt-3 text-xs text-muted-foreground">
                To downgrade to Free or cancel, use <button type="button" class="underline" @click="manage">Manage billing</button>.
            </p>
        </div>

        <!-- Swap confirmation -->
        <Dialog v-model:open="confirmOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Switch to {{ pending?.label }}?</DialogTitle>
                    <DialogDescription>
                        You'll move from {{ current?.label ?? currentPlan }} to {{ pending?.label }} immediately.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3 text-sm">
                    <p class="text-muted-foreground">
                        The prorated difference is billed to your card on file, and your plan becomes
                        <span class="font-medium text-foreground">${{ pending?.price.toFixed(2) }}/mo</span>.
                    </p>
                    <p
                        v-if="willResume"
                        class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-amber-700 dark:text-amber-400"
                    >
                        Heads up: your subscription is set to cancel on {{ subscription?.ends_at }}.
                        Switching plans will <span class="font-medium">resume</span> it — it will renew instead of ending.
                    </p>
                </div>

                <DialogFooter class="gap-2 sm:gap-2">
                    <Button variant="outline" :disabled="swapping" @click="confirmOpen = false">Cancel</Button>
                    <Button :disabled="swapping" @click="confirmSwap">
                        {{ swapping ? 'Switching…' : `Switch to ${pending?.label}` }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Invoices -->
        <div v-if="invoices.length" class="rounded-xl border border-border">
            <div class="border-b border-border px-5 py-3">
                <h3 class="text-sm font-medium">Invoices</h3>
            </div>
            <ul class="divide-y divide-border">
                <li v-for="inv in invoices" :key="inv.id" class="flex items-center gap-4 px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">{{ inv.date }}</div>
                        <div class="text-xs text-muted-foreground">{{ inv.total }}</div>
                    </div>
                    <a
                        :href="inv.url"
                        class="inline-flex items-center gap-1 text-sm text-emerald-600 hover:underline dark:text-emerald-400"
                    >
                        <ExternalLink class="size-3.5" /> Download
                    </a>
                </li>
            </ul>
        </div>
    </div>
</template>
