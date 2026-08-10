<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ScanLine, Trash2 } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import { Button } from '@/components/ui/button';
import ScanUploader from '@/components/scorecard/ScanUploader.vue';

interface ScanImage {
    index: number;
    url: string;
    original_name: string;
    width: number;
    height: number;
    bytes: number;
}
interface VerificationIssue {
    level: 'error' | 'warning';
    scope: string;
    message: string;
}
interface Scan {
    id: number;
    status: 'pending' | 'parsing' | 'parsed' | 'failed' | 'applied' | 'discarded';
    course_id: number | null;
    error: string | null;
    verification: { passed: boolean; issues: VerificationIssue[] } | null;
    applied_at: string | null;
    images: ScanImage[];
}
interface ScanCourse {
    id: number;
    course_name: string | null;
    club_name: string | null;
}

const props = defineProps<{
    scan: Scan | null;
    course: ScanCourse | null;
    maxImages: number;
    maxImageMb: number;
}>();

const title = computed(() =>
    props.course ? `Scan a scorecard` : 'Scan a new course',
);

const subtitle = computed(() => {
    if (!props.course) return 'The parsed card will be staged for a new course.';
    return [props.course.course_name, props.course.club_name].filter(Boolean).join(' · ');
});

const backHref = computed(() => (props.course ? `/courses/${props.course.id}/edit` : '/explorer'));

function discard() {
    if (props.scan && confirm('Discard this scan and its images?')) {
        router.delete(`/scorecard-scans/${props.scan.id}`);
    }
}
</script>

<template>
    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[1120px] px-5 py-8 sm:px-7">
            <Link :href="backHref" class="inline-flex items-center gap-1.5 text-sm text-fg-muted transition hover:text-fg">
                <ArrowLeft class="size-4" /> {{ course ? 'Back to course' : 'Back to explorer' }}
            </Link>

            <div class="mt-4 flex flex-wrap items-start justify-between gap-3 border-b border-line pb-5">
                <div class="min-w-0">
                    <h1 class="flex items-center gap-2 font-display text-2xl font-bold tracking-tight text-fg sm:text-3xl">
                        <ScanLine class="size-6 text-lime-500" /> {{ title }}
                    </h1>
                    <p class="mt-1 truncate text-sm text-fg-subtle">{{ subtitle }}</p>
                </div>
                <Button v-if="scan" type="button" variant="ghost" class="text-destructive" @click="discard">
                    <Trash2 class="size-4" /> Discard
                </Button>
            </div>

            <div class="mt-8 space-y-6">
                <!-- Upload -->
                <ScanUploader
                    v-if="!scan"
                    :course-id="course?.id ?? null"
                    :max-images="maxImages"
                    :max-image-mb="maxImageMb"
                />

                <!-- Uploaded, awaiting parse -->
                <section v-else class="ds-card p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">
                            Uploaded images
                        </h2>
                        <span class="rounded-full border border-line px-2.5 py-0.5 font-mono text-[11px] text-fg-muted">
                            {{ scan.status }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <figure
                            v-for="image in scan.images"
                            :key="image.index"
                            class="overflow-hidden rounded-lg border border-line"
                        >
                            <img
                                :src="image.url"
                                :alt="image.original_name"
                                class="w-full bg-ink-850 object-contain"
                            />
                            <figcaption class="flex items-center justify-between gap-2 px-3 py-2">
                                <span class="truncate text-xs text-fg-muted">{{ image.original_name }}</span>
                                <span class="shrink-0 font-mono text-[11px] text-fg-subtle">
                                    {{ image.width }}×{{ image.height }}
                                </span>
                            </figcaption>
                        </figure>
                    </div>

                    <p v-if="scan.error" class="mt-4 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-fg">
                        {{ scan.error }}
                    </p>

                    <p class="mt-5 text-sm text-fg-subtle">
                        Parsing is added in the next step — these images are staged and nothing has been written to a
                        course.
                    </p>
                </section>
            </div>
        </div>
    </MarketingLayout>
</template>
