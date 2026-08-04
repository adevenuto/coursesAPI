<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';

interface EditorCourse {
    id: number;
    course_name: string | null;
    club_name: string | null;
    [key: string]: unknown;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    course: EditorCourse | null;
    mapsKey: string | null;
}>();

const title = computed(() =>
    props.mode === 'create' ? 'New course' : props.course?.course_name || 'Edit course',
);
</script>

<template>
    <Head :title="`${title} — GCA`" />

    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[1120px] px-5 py-10 sm:px-7">
            <Link href="/explorer" class="inline-flex items-center gap-1.5 text-sm text-fg-muted transition hover:text-fg">
                <ArrowLeft class="size-4" /> Back to explorer
            </Link>

            <h1 class="mt-6 font-display text-3xl font-bold tracking-tight text-fg" style="letter-spacing: -0.02em">
                {{ title }}
            </h1>
            <p class="mt-2 text-fg-muted">Course editor — details, teeboxes, and green centers.</p>

            <div class="mt-8 ds-card p-6">
                <p class="text-sm text-fg-subtle">Editor form loads here.</p>
            </div>
        </div>
    </MarketingLayout>
</template>
