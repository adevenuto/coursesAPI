<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import { ArrowLeft, ExternalLink, Flag, History, Save, ScanLine, Trash2 } from '@lucide/vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import TeeboxEditor from '@/components/editor/TeeboxEditor.vue';
import GreenCenterEditor from '@/components/editor/GreenCenterEditor.vue';
import LocatorMap from '@/components/editor/LocatorMap.vue';

interface Hole {
    hole: number;
    par: number | null;
    length: number | null;
    handicap: number | null;
    handicapWomen: number | null;
}
interface Teebox {
    name: string;
    color: string | null;
    secondaryColor: string | null;
    courseRating: number | null;
    courseRatingWomen: number | null;
    slope: number | null;
    slopeWomen: number | null;
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
    last_editor?: { name: string | null; at: string | null } | null;
}
interface Revision {
    user: string;
    action: string;
    changes: Array<{ label: string; detail: string }>;
    at: string | null;
}
interface SiblingCourse {
    id: number;
    course_name: string | null;
    hole_count: number | null;
    green_centers_available: boolean;
    edit_url: string;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    course: EditorCourse | null;
    mapsKey: string | null;
    history?: Revision[];
    siblings?: SiblingCourse[];
}>();

const c = props.course;
const isEdit = computed(() => props.mode === 'edit');
const lastEditor = computed(() => props.course?.last_editor ?? null);
const showHistory = ref(false);

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
    // Address components of the place picked in the locator, if any. The server
    // uses them to correct city/state/country instead of guessing from lat/lng.
    place_country_code: '',
    place_country_name: '',
    place_state_code: '',
    place_state_name: '',
    place_city_candidates: [] as string[],
});

// Child editors normalize their data on mount (e.g. reconciling holes); reset
// the dirty baseline afterwards so Save starts disabled until a real change.
onMounted(() => nextTick(() => form.defaults()));

const title = computed(() => (isEdit.value ? c?.course_name || 'Edit course' : 'New course'));

// Normalize the website for the "open" link (accept bare domains too).
const websiteHref = computed(() => {
    const w = String(form.website ?? '').trim();
    if (!w) return '';
    return /^https?:\/\//i.test(w) ? w : `https://${w}`;
});

function save() {
    // Reset the dirty baseline on success so Save disables again.
    const opts = { preserveScroll: true, onSuccess: () => form.defaults() };
    if (isEdit.value && props.course) {
        form.put(`/courses/${props.course.id}`, opts);
    } else {
        form.post('/courses', opts);
    }
}

function destroy() {
    if (props.course && confirm('Delete this course? This cannot be undone.')) {
        router.delete(`/courses/${props.course.id}`);
    }
}

// Selecting a place in the locator fills matching fields (only where the
// place actually provides a value, so existing data is never blanked out).
// The country/state/city components ride along to the server, which reconciles
// the stored city_id / state_prov_id / country_id against them on save.
function onPlace(d: {
    address?: string;
    postal_code?: string;
    phone?: string;
    website?: string;
    country_code?: string;
    country_name?: string;
    state_code?: string;
    state_name?: string;
    city_candidates?: string[];
}) {
    if (d.address) form.address = d.address;
    if (d.postal_code) form.postal_code = d.postal_code;
    if (d.phone) form.phone = d.phone;
    if (d.website) form.website = d.website;

    form.place_country_code = d.country_code ?? '';
    form.place_country_name = d.country_name ?? '';
    form.place_state_code = d.state_code ?? '';
    form.place_state_name = d.state_name ?? '';
    form.place_city_candidates = d.city_candidates ?? [];
}

// Moving the pin by hand invalidates the picked place's components.
function onPlaceCleared() {
    form.place_country_code = '';
    form.place_country_name = '';
    form.place_state_code = '';
    form.place_state_name = '';
    form.place_city_candidates = [];
}
</script>

<template>
    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[1120px] px-5 py-8 sm:px-7">
            <Link href="/explorer" class="inline-flex items-center gap-1.5 text-sm text-fg-muted transition hover:text-fg">
                <ArrowLeft class="size-4" /> Back to explorer
            </Link>

            <!-- sticky action bar -->
            <div class="sticky top-16 z-30 -mx-5 mt-4 flex flex-wrap items-center justify-between gap-3 border-b border-line bg-ink-900/85 px-5 py-3 backdrop-blur sm:-mx-7 sm:px-7">
                <div class="min-w-0">
                    <h1 class="truncate font-display text-2xl font-bold tracking-tight text-fg sm:text-3xl">{{ title }}</h1>
                    <p v-if="c?.location" class="mt-1 text-sm text-fg-subtle">
                        {{ [c.location.city, c.location.state, c.location.country].filter(Boolean).join(', ') || 'Location set from coordinates on save' }}
                    </p>
                    <p v-if="lastEditor" class="mt-0.5 text-xs text-fg-subtle">
                        Last edited by <span class="text-fg-muted">{{ lastEditor.name || 'someone' }}</span> · {{ lastEditor.at }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="isEdit && course"
                        :href="`/scorecard-scans/create?course_id=${course.id}`"
                        class="ds-btn ds-btn--dark px-3 py-2 text-sm"
                    >
                        <ScanLine class="size-4" /> Scan card
                    </Link>
                    <Button v-if="isEdit" type="button" variant="ghost" size="sm" @click="showHistory = true">
                        <History class="size-4" /> History
                    </Button>
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
                    <Button type="button" :disabled="!form.isDirty || form.processing" @click="save">
                        <Save class="size-4" /> {{ form.processing ? 'Saving…' : 'Save' }}
                    </Button>
                </div>
            </div>

            <!-- Edit history -->
            <Dialog v-model:open="showHistory">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit history</DialogTitle>
                        <DialogDescription>Recent changes to this course.</DialogDescription>
                    </DialogHeader>
                    <div class="max-h-[60vh] space-y-3 overflow-y-auto">
                        <p v-if="!history || !history.length" class="py-6 text-center text-sm text-fg-subtle">
                            No edits recorded yet.
                        </p>
                        <div v-for="(rev, i) in history" :key="i" class="rounded-lg border border-line p-3">
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span class="font-medium text-fg">{{ rev.user }}</span>
                                <span class="font-mono text-xs text-fg-subtle">{{ rev.action }} · {{ rev.at }}</span>
                            </div>
                            <div v-if="rev.changes.length" class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="(ch, j) in rev.changes"
                                    :key="j"
                                    class="rounded-full border border-line px-2 py-0.5 text-xs text-fg-muted"
                                >
                                    <span class="text-fg-subtle">{{ ch.label }}:</span> {{ ch.detail }}
                                </span>
                            </div>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

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
                            <div class="relative mt-1">
                                <Input id="website" v-model="form.website" class="pr-9" placeholder="https://…" />
                                <a
                                    v-if="websiteHref"
                                    :href="websiteHref"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="absolute top-1/2 right-2.5 -translate-y-1/2 text-fg-subtle transition hover:text-lime-500"
                                    aria-label="Open website in a new tab"
                                >
                                    <ExternalLink class="size-4" />
                                </a>
                            </div>
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
                        <div class="sm:col-span-2">
                            <Label>Location</Label>
                            <div class="mt-1">
                                <LocatorMap
                                    v-model:lat="form.lat"
                                    v-model:lng="form.lng"
                                    :maps-key="mapsKey"
                                    @place="onPlace"
                                    @place-cleared="onPlaceCleared"
                                />
                            </div>
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
                        City, state, and country are assigned on save — from the searched place when you pick one,
                        otherwise from the coordinates.
                    </p>
                </section>

                <!-- Teeboxes -->
                <section class="ds-card p-6">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Teeboxes &amp; scorecard</h2>
                    <InputError class="mt-1" :message="form.errors.teeboxes" />
                    <div class="mt-4">
                        <TeeboxEditor
                            v-model="form.teeboxes"
                            v-model:hole-count="form.hole_count"
                            :can-set-hole-count="!isEdit"
                        />
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

                <!-- Other courses on this property (siblings sharing club + coordinates) -->
                <section v-if="siblings && siblings.length" class="ds-card p-6">
                    <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">
                        Other courses on this property
                    </h2>
                    <p class="mt-1 text-xs text-fg-subtle">
                        {{ siblings.length }} other course{{ siblings.length === 1 ? '' : 's' }} share this club and location.
                    </p>
                    <div class="mt-4 space-y-2">
                        <Link
                            v-for="s in siblings"
                            :key="s.id"
                            :href="s.edit_url"
                            class="ds-card ds-card--hover flex items-center gap-3 p-4"
                        >
                            <span class="ds-icon-tile shrink-0">
                                <Flag class="size-4 text-lime-500" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5">
                                    <span class="min-w-0 truncate text-sm font-medium text-fg">
                                        {{ s.course_name || 'Untitled course' }}
                                    </span>
                                    <span
                                        v-if="s.green_centers_available"
                                        class="size-1.5 shrink-0 rounded-full bg-lime-400"
                                        title="Green centers mapped"
                                    />
                                </span>
                                <span v-if="s.hole_count" class="text-xs text-fg-subtle">{{ s.hole_count }} holes</span>
                            </span>
                        </Link>
                    </div>
                </section>
            </div>
        </div>
    </MarketingLayout>
</template>
