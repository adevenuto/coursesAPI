<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, ExternalLink, Save, Trash2 } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import TeeboxEditor from '@/components/editor/TeeboxEditor.vue';
import GreenCenterEditor from '@/components/editor/GreenCenterEditor.vue';

interface Hole {
    hole: number;
    par: number | null;
    length: number | null;
    handicap: number | null;
}
interface Teebox {
    name: string;
    color: string | null;
    secondaryColor: string | null;
    courseRating: number | null;
    slope: number | null;
    totalYardage: number | null;
    holes: Hole[];
}
interface GreenCenter {
    hole: number;
    lat: number;
    lng: number;
}
interface EditorCourse {
    id: number;
    course_name: string | null;
    club_name: string | null;
    address: string | null;
    postal_code: string | null;
    phone: string | null;
    website: string | null;
    lat: number | null;
    lng: number | null;
    hole_count: number | null;
    teeboxes: Teebox[];
    green_centers: GreenCenter[];
    location?: { city: string | null; state: string | null; country: string | null };
}

const props = defineProps<{
    mode: 'create' | 'edit';
    course: EditorCourse | null;
    mapsKey: string | null;
}>();

const c = props.course;
const isEdit = computed(() => props.mode === 'edit');

const form = useForm({
    course_name: c?.course_name ?? '',
    club_name: c?.club_name ?? '',
    address: c?.address ?? '',
    postal_code: c?.postal_code ?? '',
    phone: c?.phone ?? '',
    website: c?.website ?? '',
    lat: c?.lat ?? null,
    lng: c?.lng ?? null,
    hole_count: c?.hole_count ?? 18,
    teeboxes: (c?.teeboxes ?? []) as Teebox[],
    green_centers: (c?.green_centers ?? []) as GreenCenter[],
});

const title = computed(() => (isEdit.value ? c?.course_name || 'Edit course' : 'New course'));

function save() {
    if (isEdit.value && props.course) {
        form.put(`/courses/${props.course.id}`, { preserveScroll: true });
    } else {
        form.post('/courses', { preserveScroll: true });
    }
}

function destroy() {
    if (props.course && confirm('Delete this course? This cannot be undone.')) {
        router.delete(`/courses/${props.course.id}`);
    }
}
</script>

<template>
    <Head :title="`${title} — GCA`" />

    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[1120px] px-5 py-8 sm:px-7">
            <Link href="/explorer" class="inline-flex items-center gap-1.5 text-sm text-fg-muted transition hover:text-fg">
                <ArrowLeft class="size-4" /> Back to explorer
            </Link>

            <!-- sticky action bar -->
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="truncate font-display text-2xl font-bold tracking-tight text-fg sm:text-3xl">{{ title }}</h1>
                    <p v-if="c?.location" class="mt-1 text-sm text-fg-subtle">
                        {{ [c.location.city, c.location.state, c.location.country].filter(Boolean).join(', ') || 'Location set from coordinates on save' }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <a
                        v-if="isEdit && course"
                        :href="`/courses/${course.id}`"
                        target="_blank"
                        class="ds-btn ds-btn--dark px-3 py-2 text-sm"
                    >
                        <ExternalLink class="size-4" /> View
                    </a>
                    <Button v-if="isEdit" type="button" variant="ghost" class="text-destructive" @click="destroy">
                        <Trash2 class="size-4" /> Delete
                    </Button>
                    <Button type="button" :disabled="form.processing" @click="save">
                        <Save class="size-4" /> {{ form.processing ? 'Saving…' : 'Save' }}
                    </Button>
                </div>
            </div>

            <div class="mt-8 space-y-6">
                <!-- Details -->
                <section class="ds-card p-6">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Details</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <Label for="course_name">Course name *</Label>
                            <Input id="course_name" v-model="form.course_name" class="mt-1" maxlength="255" />
                            <InputError class="mt-1" :message="form.errors.course_name" />
                        </div>
                        <div>
                            <Label for="club_name">Club name</Label>
                            <Input id="club_name" v-model="form.club_name" class="mt-1" maxlength="255" />
                            <InputError class="mt-1" :message="form.errors.club_name" />
                        </div>
                        <div>
                            <Label for="website">Website</Label>
                            <Input id="website" v-model="form.website" class="mt-1" placeholder="https://…" />
                            <InputError class="mt-1" :message="form.errors.website" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="address">Address</Label>
                            <Input id="address" v-model="form.address" class="mt-1" maxlength="255" />
                            <InputError class="mt-1" :message="form.errors.address" />
                        </div>
                        <div>
                            <Label for="postal_code">Postal code</Label>
                            <Input id="postal_code" v-model="form.postal_code" class="mt-1" maxlength="20" />
                            <InputError class="mt-1" :message="form.errors.postal_code" />
                        </div>
                        <div>
                            <Label for="phone">Phone</Label>
                            <Input id="phone" v-model="form.phone" class="mt-1" maxlength="50" />
                            <InputError class="mt-1" :message="form.errors.phone" />
                        </div>
                        <div>
                            <Label for="lat">Latitude *</Label>
                            <Input id="lat" v-model="form.lat" type="number" step="any" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.lat" />
                        </div>
                        <div>
                            <Label for="lng">Longitude *</Label>
                            <Input id="lng" v-model="form.lng" type="number" step="any" class="mt-1" />
                            <InputError class="mt-1" :message="form.errors.lng" />
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-fg-subtle">
                        City, state, and country are assigned automatically from the coordinates when you save.
                    </p>
                </section>

                <!-- Teeboxes -->
                <section class="ds-card p-6">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Teeboxes &amp; scorecard</h2>
                    <InputError class="mt-1" :message="form.errors.teeboxes" />
                    <div class="mt-4">
                        <TeeboxEditor v-model="form.teeboxes" v-model:hole-count="form.hole_count" />
                    </div>
                </section>

                <!-- Green centers -->
                <section class="ds-card p-6">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Green centers</h2>
                    <p class="mt-1 text-xs text-fg-subtle">Place the center of each hole's green on the satellite map.</p>
                    <InputError class="mt-1" :message="form.errors.green_centers" />
                    <div class="mt-4">
                        <GreenCenterEditor
                            v-model="form.green_centers"
                            :maps-key="mapsKey"
                            :lat="form.lat"
                            :lng="form.lng"
                            :hole-count="form.hole_count"
                        />
                    </div>
                </section>
            </div>
        </div>
    </MarketingLayout>
</template>
