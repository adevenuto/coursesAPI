<script setup lang="ts">
withDefaults(defineProps<{ dot?: boolean }>(), { dot: true });
</script>

<template>
    <span class="ds-badge ds-badge--lime font-body">
        <span v-if="dot" class="relative flex size-1.5">
            <span
                class="absolute inline-flex size-full rounded-full mk-ping"
                style="background: var(--lime-400)"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full"
                style="background: var(--lime-400)"
            />
        </span>
        <slot />
    </span>
</template>
