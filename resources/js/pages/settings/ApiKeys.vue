<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useClipboard } from '@vueuse/core';
import { Check, Copy, KeyRound, Plus, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface TokenRow {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
}

const props = defineProps<{
    usage: { today: number; limit: number };
    tokens: TokenRow[];
    newToken: string | null;
    maxKeys: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API keys', href: '/settings/api-keys' }],
    },
});

const form = useForm({ name: '' });
const page = usePage();

const submit = () =>
    form.post('/settings/api-keys', {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
    });

const revoke = (id: number) => {
    if (confirm('Revoke this API key? Any app using it will stop working.')) {
        router.delete(`/settings/api-keys/${id}`, { preserveScroll: true });
    }
};

const { copy, copied } = useClipboard({ source: () => props.newToken ?? '' });

const nf = (n: number) => n.toLocaleString('en-US');
const errors = computed(() => ({ ...form.errors, ...(page.props.errors as Record<string, string>) }));
</script>

<template>
    <Head title="API keys" />

    <div class="flex flex-col space-y-8">
        <Heading
            variant="small"
            title="API keys"
            description="Create and manage the keys that authenticate your API requests."
        />

        <!-- compact usage line (full breakdown lives on the dashboard) -->
        <p class="text-sm text-muted-foreground">
            <span class="font-medium text-foreground">{{ nf(usage.today) }}</span>
            of {{ nf(usage.limit) }} requests used today.
            <a href="/dashboard" class="text-emerald-600 hover:underline dark:text-emerald-400">View usage →</a>
        </p>

        <!-- One-time token reveal -->
        <div
            v-if="newToken"
            class="rounded-xl border border-emerald-500/40 bg-emerald-500/5 p-5"
        >
            <div class="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                <KeyRound class="size-4" /> Your new API key
            </div>
            <p class="mt-1 text-sm text-muted-foreground">
                Copy it now — for your security it won't be shown again.
            </p>
            <div class="mt-3 flex items-center gap-2">
                <code class="flex-1 overflow-x-auto rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm">{{ newToken }}</code>
                <Button type="button" variant="secondary" size="sm" @click="copy()">
                    <component :is="copied ? Check : Copy" class="size-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </div>

        <!-- Create key -->
        <form class="flex items-end gap-3" @submit.prevent="submit">
            <div class="flex-1">
                <Label for="key-name">New key name</Label>
                <Input
                    id="key-name"
                    v-model="form.name"
                    class="mt-1"
                    placeholder="e.g. Production, Staging, Mobile app"
                    maxlength="50"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>
            <Button type="submit" :disabled="form.processing">
                <Plus class="size-4" /> Create key
            </Button>
        </form>

        <!-- Key list -->
        <div class="rounded-xl border border-border">
            <div class="flex items-center justify-between border-b border-border px-5 py-3">
                <h3 class="text-sm font-medium">Your keys</h3>
                <span class="text-xs text-muted-foreground">{{ tokens.length }} / {{ maxKeys }}</span>
            </div>
            <ul v-if="tokens.length" class="divide-y divide-border">
                <li v-for="t in tokens" :key="t.id" class="flex items-center gap-4 px-5 py-3.5">
                    <KeyRound class="size-4 shrink-0 text-muted-foreground" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ t.name }}</div>
                        <div class="text-xs text-muted-foreground">
                            Created {{ t.created_at }} · Last used {{ t.last_used_at ?? 'never' }}
                        </div>
                    </div>
                    <Button type="button" variant="ghost" size="sm" class="text-destructive" @click="revoke(t.id)">
                        <Trash2 class="size-4" /> Revoke
                    </Button>
                </li>
            </ul>
            <p v-else class="px-5 py-8 text-center text-sm text-muted-foreground">
                No API keys yet. Create one above to start calling the API.
            </p>
        </div>
    </div>
</template>
