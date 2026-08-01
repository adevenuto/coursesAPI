<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    icon: Component;
    title: string;
    description: string;
    premium?: boolean;
}>();
</script>

<template>
    <div class="ds-card ds-card--hover group p-6" :class="premium ? 'ds-card--glow' : ''">
        <div class="relative">
            <span
                v-if="premium"
                class="ds-badge ds-badge--lime absolute top-0 right-0 !px-2 !py-0.5 font-body text-[10px]"
                >Paid</span
            >
            <div class="ds-icon-tile">
                <component :is="icon" class="size-5" />
            </div>
            <h3 class="mt-5 font-display text-lg font-semibold text-fg">
                {{ title }}
            </h3>
            <p class="mt-2 text-sm leading-relaxed text-fg-muted">
                {{ description }}
            </p>
        </div>
    </div>
</template>
