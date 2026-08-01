<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';

const props = withDefaults(
    defineProps<{
        code: string;
        method?: string;
        label?: string;
    }>(),
    { method: '', label: '' },
);

const { copy, copied } = useClipboard({ source: () => props.code });
</script>

<template>
    <div class="ds-card overflow-hidden" style="border-radius: 16px">
        <div class="flex items-center gap-3 border-b border-line px-4 py-2.5">
            <span
                v-if="method"
                class="font-mono text-[11px] font-semibold tracking-wide"
                style="color: var(--lime-400)"
            >
                {{ method }}
            </span>
            <span class="truncate font-mono text-xs text-fg-muted">{{ label }}</span>
            <button
                type="button"
                class="ml-auto inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-fg-muted transition hover:bg-ink-700 hover:text-fg"
                @click="copy()"
            >
                <component :is="copied ? Check : Copy" class="size-3.5" />
                {{ copied ? 'Copied' : 'Copy' }}
            </button>
        </div>
        <pre
            class="overflow-x-auto px-4 py-4 font-mono text-[12.5px] leading-relaxed"
            style="color: rgba(245, 247, 244, 0.9)"
        ><code>{{ code }}</code></pre>
    </div>
</template>
