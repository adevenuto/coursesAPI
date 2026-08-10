<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Loader2, ScanLine, Sparkles, Trash2 } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import { Button } from '@/components/ui/button';
import ScanUploader from '@/components/scorecard/ScanUploader.vue';
import ScanDiff, { type DiffSection } from '@/components/scorecard/ScanDiff.vue';
import ScanIssues from '@/components/scorecard/ScanIssues.vue';

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

interface Diff {
    sections: DiffSection[];
    unmapped: Array<{ label: string; detail: string }>;
    is_new: boolean;
}

const props = defineProps<{
    scan: Scan | null;
    course: ScanCourse | null;
    diff?: Diff | null;
    // True when parses run on a worker, so a `parsing` status means work is in
    // flight rather than a request that died.
    queuedParsing: boolean;
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

const parsing = ref(false);

const isParsing = computed(() => props.scan?.status === 'parsing');

// A worker is doing the work; wait for it.
const inFlight = computed(() => isParsing.value && props.queuedParsing);

// Nothing drains the queue in inline mode, so the parse only ever happens in the
// request that started it. A scan still sitting in `parsing` on a fresh page
// load therefore means that request died — most likely a server timeout on a
// slow card — not that work is happening somewhere.
const wasInterrupted = computed(() => isParsing.value && !props.queuedParsing);

const canParse = computed(
    () => props.scan?.status === 'pending'
        || props.scan?.status === 'failed'
        || wasInterrupted.value,
);
const hasParsed = computed(() => props.scan?.status === 'parsed');

function parse() {
    if (!props.scan) return;
    parsing.value = true;
    router.post(
        `/scorecard-scans/${props.scan.id}/parse`,
        {},
        { onFinish: () => (parsing.value = false) },
    );
}

// Poll while a worker is reading the card. Only the scan and the diff are
// refetched, so the page doesn't flicker. Give up after five minutes rather
// than polling a dead session forever — the job's failed() hook will have
// marked it failed by then, and a reload shows that.
const POLL_MS = 4000;
const POLL_LIMIT = (5 * 60 * 1000) / POLL_MS;

let timer: ReturnType<typeof setInterval> | null = null;
let ticks = 0;

function stopPolling() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

onMounted(() => {
    if (!inFlight.value) return;

    timer = setInterval(() => {
        if (++ticks > POLL_LIMIT) {
            stopPolling();
            return;
        }
        router.reload({ only: ['scan', 'diff'] });
    }, POLL_MS);
});

// The reload swaps the props in place, so stop as soon as it's no longer parsing.
watch(inFlight, (still) => {
    if (!still) stopPolling();
});

onUnmounted(stopPolling);

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

                    <div
                        v-if="scan.error"
                        class="mt-4 flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-fg"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0 text-destructive" />
                        <span>{{ scan.error }}</span>
                    </div>

                    <div
                        v-if="wasInterrupted && !parsing"
                        class="mt-4 flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-fg"
                    >
                        <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-400" />
                        <span>
                            The last attempt didn't finish — most likely the server cut the request off before the card
                            was read. Try again; if it keeps happening, split the card across two images so each one
                            takes less time.
                        </span>
                    </div>

                    <!-- Queued: a worker has it. Safe to leave. -->
                    <div v-if="inFlight" class="mt-5 flex items-center gap-3">
                        <Loader2 class="size-4 shrink-0 animate-spin text-lime-500" />
                        <p class="text-sm text-fg-muted">
                            Reading the card. This usually takes a minute or two — you can leave this page and come
                            back, or queue another card while you wait.
                        </p>
                    </div>

                    <div v-else class="mt-5 flex flex-wrap items-center gap-3">
                        <Button v-if="canParse" type="button" :disabled="parsing" @click="parse">
                            <Loader2 v-if="parsing" class="size-4 animate-spin" />
                            <Sparkles v-else class="size-4" />
                            {{
                                parsing
                                    ? 'Reading the card…'
                                    : scan.status === 'pending'
                                      ? 'Read this scorecard'
                                      : 'Try again'
                            }}
                        </Button>
                        <p v-if="parsing" class="text-sm text-fg-subtle">
                            {{
                                queuedParsing
                                    ? 'Queueing…'
                                    : 'This takes up to a minute or so. Keep this tab open.'
                            }}
                        </p>
                        <p v-else-if="canParse" class="text-sm text-fg-subtle">
                            Nothing is written to a course — you'll review the changes first.
                        </p>
                    </div>
                </section>

                <!-- Verification findings + what the card carried that a course can't hold -->
                <ScanIssues
                    v-if="hasParsed && diff"
                    :verification="scan?.verification ?? null"
                    :unmapped="diff.unmapped"
                />

                <!-- The diff the editor confirms against -->
                <ScanDiff
                    v-if="hasParsed && diff && scan"
                    :scan-id="scan.id"
                    :sections="diff.sections"
                    :is-new="diff.is_new"
                />
            </div>
        </div>
    </MarketingLayout>
</template>
