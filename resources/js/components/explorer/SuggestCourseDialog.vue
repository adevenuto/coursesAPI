<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Lock, MapPinPlus } from '@lucide/vue';
import { computed, nextTick, reactive, ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/CourseSuggestionController';
import SuggestTypeahead from '@/components/explorer/SuggestTypeahead.vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { createCourseSearch, searchGeo } from '@/lib/suggestLookup';
import type { AlgoliaProps, CourseOption, SuggestOption } from '@/types/search';

/**
 * "Suggest a course" — the public counterpart to the editor action buttons in
 * the explorer header. Two shapes behind one dialog:
 *
 *   correction → one Algolia search picks a real course; its location fills in
 *                and locks, and the notes carry the actual correction.
 *   missing    → name + club, then a cascading country → state → city typeahead
 *                over our own geo tables.
 *
 * `algolia` is passed explicitly rather than read from usePage(): it is a page
 * prop of /explorer only, not shared by HandleInertiaRequests, so a nested
 * component would find nothing there.
 */
const props = defineProps<{ algolia: AlgoliaProps }>();

const page = usePage();
const courseSearch = createCourseSearch(props.algolia);

const open = ref(false);
const submitted = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const body = ref<HTMLElement | null>(null);

/**
 * Posted with fetch rather than Inertia's useForm, deliberately.
 *
 * This modal is a side action: it should confirm in place and leave the page
 * alone. An Inertia visit would redirect back to /explorer and re-render the
 * whole page under the open dialog, which makes the confirmation depend on
 * whether the component instance survived — and would put the map, the Algolia
 * client and the restored area at risk for no gain. Explorer.vue already talks
 * to /explore/* with plain fetch, so this is the established shape here.
 */
const initial = () => ({
    type: 'missing',
    course_name: '',
    club_name: '',
    course_id: null as number | null,
    country: '',
    state: '',
    city: '',
    country_id: null as number | null,
    state_id: null as number | null,
    city_id: null as number | null,
    website: '',
    notes: '',
    submitter_email: (page.props.auth?.user?.email as string | undefined) ?? '',
    // Honeypot. Named to be unrecognisable on purpose: anything like `company`
    // or `website_url` gets helpfully filled by browser autofill and password
    // managers, which would quietly flag real people as bots.
    contact_reference: '',
});

const form = reactive(initial());

/**
 * Display order is deliberate — "existing" first, as asked. The default stays
 * `missing`: someone arriving from the explorer header is far more likely to be
 * reporting a course we don't have than correcting one we do.
 */
const TYPE_OPTIONS = [
    { value: 'correction', label: 'Existing course/club suggestion' },
    { value: 'missing', label: 'New course/club suggestion' },
] as const;

const isCorrection = computed(() => form.type === 'correction');

const NO_SUGGESTIONS =
    "No suggestions for this — type it in and we'll match it up by hand.";

// A child whose parent didn't resolve can still be typed into; it just has
// nothing to scope suggestions to. Never a dead end: an unlisted village is
// exactly what this form exists to capture.
const stateHint = computed(() =>
    form.country && !form.country_id ? NO_SUGGESTIONS : undefined,
);
const cityHint = computed(() =>
    form.state && !form.state_id ? NO_SUGGESTIONS : undefined,
);

const searchCountries = (q: string) => searchGeo('countries', { q });
const searchStates = (q: string) =>
    searchGeo('states', { q, country: form.country_id });
const searchCities = (q: string) =>
    searchGeo('cities', { q, state: form.state_id });

function onCourseSelect(option: SuggestOption) {
    const { hit } = option as CourseOption;

    form.course_id = hit.id;
    form.course_name = hit.name ?? option.label;
    form.club_name = hit.club ?? '';
    form.country = hit.country ?? '';
    form.state = hit.state ?? '';
    form.city = hit.city ?? '';
}

/** Reset the search and everything derived from it — but not anyone's prose. */
function clearCourse() {
    form.course_id = null;
    form.club_name = '';
    form.country = '';
    form.state = '';
    form.city = '';
    form.country_id = null;
    form.state_id = null;
    form.city_id = null;
}

function onCountrySelect(option: SuggestOption) {
    if (form.country_id === option.id) {
        return;
    }

    form.country_id = option.id;
    // A different country invalidates whatever sat under the old one.
    form.state = '';
    form.state_id = null;
    form.city = '';
    form.city_id = null;
}

function onStateSelect(option: SuggestOption) {
    if (form.state_id === option.id) {
        return;
    }

    form.state_id = option.id;
    form.city = '';
    form.city_id = null;
}

/*
 * Typing clears only that field's own id — children keep their text and ids.
 * The server reads the whole trio off whichever row resolves, so a stale child
 * id can never be stored inconsistently; whereas wiping a city someone already
 * typed because they fixed a typo in the country would be the worse bug.
 */
function onCountryClear() {
    form.country_id = null;
}
function onStateClear() {
    form.state_id = null;
}
function onCityClear() {
    form.city_id = null;
}

/** The two modes mean different things by the same fields. */
function onTypeChange() {
    form.course_name = '';
    clearCourse();
    errors.value = {};
}

/** Laravel accepts the encrypted XSRF cookie back as a header, as axios sends it. */
function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

async function submit() {
    if (processing.value) {
        return;
    }

    processing.value = true;
    errors.value = {};

    try {
        const token = xsrfToken();

        const res = await fetch(store.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify(form),
        });

        if (res.status === 422) {
            const payload = (await res.json()) as {
                errors?: Record<string, string[]>;
            };

            errors.value = Object.fromEntries(
                Object.entries(payload.errors ?? {}).map(([field, list]) => [
                    field,
                    list[0],
                ]),
            );

            // The body scrolls, so an error on an off-screen field would
            // otherwise look like nothing happened at all.
            await nextTick();
            body.value
                ?.querySelector('[data-error]')
                ?.scrollIntoView({ block: 'center' });

            return;
        }

        if (!res.ok) {
            errors.value = {
                form: "Something went wrong on our end and it wasn't sent. Please try again.",
            };

            return;
        }

        submitted.value = true;
    } catch {
        errors.value = {
            form: "We couldn't reach the server. Check your connection and try again.",
        };
    } finally {
        processing.value = false;
    }
}

function onOpenChange(next: boolean) {
    open.value = next;

    if (!next) {
        // Reopening should start clean rather than on last time's confirmation.
        submitted.value = false;
        errors.value = {};
        Object.assign(form, initial());
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogTrigger as-child>
            <button
                type="button"
                class="ds-btn ds-btn--primary px-4 py-2.5 text-sm"
            >
                <MapPinPlus class="size-4" /> Suggest a course
            </button>
        </DialogTrigger>

        <!--
            `dark` is deliberate: DialogContent is teleported to <body> through
            DialogPortal, which escapes the `dark` wrapper MarketingLayout puts
            around this page. Without it the modal renders light on a near-black
            page.
        -->
        <DialogContent
            class="dark flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-xl"
        >
            <template v-if="submitted">
                <DialogHeader class="shrink-0 border-b border-line p-6 pr-12">
                    <DialogTitle>Thanks — we'll take a look</DialogTitle>
                    <DialogDescription>
                        Your suggestion is with us. If we need to check anything
                        we'll reply to the email address you gave.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="shrink-0 border-t border-line p-6">
                    <button
                        type="button"
                        class="ds-btn ds-btn--primary px-4 py-2.5 text-sm"
                        @click="onOpenChange(false)"
                    >
                        Done
                    </button>
                </DialogFooter>
            </template>

            <!--
                min-h-0 on the form and the scroll area: a flex child defaults to
                min-height:auto and refuses to shrink below its content, which
                would push the footer off-screen instead of scrolling the body.
            -->
            <form v-else class="flex min-h-0 flex-col" @submit.prevent="submit">
                <DialogHeader class="shrink-0 border-b border-line p-6 pr-12">
                    <DialogTitle>Suggest a course</DialogTitle>
                    <DialogDescription>
                        Missing a course or club, or spotted something wrong?
                        Tell us and we'll get it sorted.
                    </DialogDescription>
                </DialogHeader>

                <div
                    ref="body"
                    class="min-h-0 flex-1 space-y-4 overflow-y-auto p-6"
                >
                    <p
                        v-if="errors.form"
                        class="rounded-lg border border-red-500/40 bg-red-500/5 p-3 text-sm text-red-400"
                    >
                        {{ errors.form }}
                    </p>

                    <!--
                        A fieldset of real radios rather than styled buttons:
                        arrow-key navigation between the two, and the group name
                        announced to a screen reader, both come free from the
                        browser. The inputs are sr-only, not hidden, so they stay
                        focusable — has-focus-visible puts the ring on the
                        segment the focus is actually inside.
                    -->
                    <fieldset>
                        <legend class="text-sm font-medium text-fg">
                            What are you suggesting?
                        </legend>
                        <div class="mt-1 grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="option in TYPE_OPTIONS"
                                :key="option.value"
                                class="flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2.5 text-center text-sm transition has-focus-visible:ring-1 has-focus-visible:ring-line-lime"
                                :class="
                                    form.type === option.value
                                        ? 'border-line-lime bg-mk-accent/10 font-medium text-fg'
                                        : 'border-line bg-ink-800 text-fg-muted hover:text-fg'
                                "
                            >
                                <input
                                    v-model="form.type"
                                    type="radio"
                                    name="suggest-type"
                                    :value="option.value"
                                    class="sr-only"
                                    @change="onTypeChange"
                                />
                                {{ option.label }}
                            </label>
                        </div>
                        <InputError
                            class="mt-1"
                            :message="errors.type"
                            :data-error="errors.type ? true : undefined"
                        />
                    </fieldset>

                    <!-- ── correction ──────────────────────────────────── -->
                    <template v-if="isCorrection">
                        <div>
                            <label
                                for="suggest-course-search"
                                class="text-sm font-medium text-fg"
                                >Which course or club? *</label
                            >
                            <SuggestTypeahead
                                v-if="courseSearch.configured"
                                id="suggest-course-search"
                                v-model="form.course_name"
                                class="mt-1"
                                placeholder="Search by course or club name…"
                                :search="courseSearch.search"
                                :invalid="!!errors.course_name"
                                @select="onCourseSelect"
                                @clear="clearCourse"
                            />
                            <!-- Search unavailable: a plain field beats a dead box. -->
                            <input
                                v-else
                                id="suggest-course-search"
                                v-model="form.course_name"
                                class="ds-input mt-1"
                                maxlength="255"
                                placeholder="Course or club name"
                                autocomplete="off"
                            />
                            <InputError
                                class="mt-1"
                                :message="errors.course_name"
                                :data-error="
                                    errors.course_name ? true : undefined
                                "
                            />
                        </div>

                        <div
                            v-if="form.course_id"
                            class="space-y-3 rounded-lg border border-line bg-ink-850/60 p-4"
                        >
                            <div
                                v-for="field in [
                                    'country',
                                    'state',
                                    'city',
                                ] as const"
                                :key="field"
                            >
                                <label
                                    :for="`suggest-locked-${field}`"
                                    class="text-xs font-medium text-fg-muted"
                                >
                                    {{
                                        field === 'state'
                                            ? 'State / province'
                                            : field === 'city'
                                              ? 'City'
                                              : 'Country'
                                    }}
                                </label>
                                <div class="relative">
                                    <input
                                        :id="`suggest-locked-${field}`"
                                        :value="form[field]"
                                        class="ds-input mt-1 pr-9"
                                        readonly
                                        aria-readonly="true"
                                    />
                                    <Lock
                                        class="pointer-events-none absolute top-1/2 right-3 size-3.5 translate-y-[2px] text-fg-subtle"
                                        aria-hidden="true"
                                    />
                                </div>
                            </div>
                            <p class="text-xs text-fg-subtle">
                                These come from the course you picked. Tell us
                                what's wrong below.
                            </p>
                        </div>
                    </template>

                    <!-- ── missing ─────────────────────────────────────── -->
                    <template v-else>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    for="suggest-course"
                                    class="text-sm font-medium text-fg"
                                    >Course name *</label
                                >
                                <input
                                    id="suggest-course"
                                    v-model="form.course_name"
                                    class="ds-input mt-1"
                                    maxlength="255"
                                    autocomplete="off"
                                />
                                <InputError
                                    class="mt-1"
                                    :message="errors.course_name"
                                    :data-error="
                                        errors.course_name ? true : undefined
                                    "
                                />
                            </div>
                            <div>
                                <label
                                    for="suggest-club"
                                    class="text-sm font-medium text-fg"
                                    >Club name</label
                                >
                                <input
                                    id="suggest-club"
                                    v-model="form.club_name"
                                    class="ds-input mt-1"
                                    maxlength="255"
                                    autocomplete="off"
                                />
                                <InputError
                                    class="mt-1"
                                    :message="errors.club_name"
                                    :data-error="
                                        errors.club_name ? true : undefined
                                    "
                                />
                            </div>
                        </div>

                        <!--
                            Stacked full width, not a 3-up grid: an in-flow
                            dropdown in a third-width column is cramped, and the
                            cascade only reads clearly top-down, where you can see
                            state and city disabled under an empty country.
                        -->
                        <div>
                            <label
                                for="suggest-country"
                                class="text-sm font-medium text-fg"
                                >Country *</label
                            >
                            <SuggestTypeahead
                                id="suggest-country"
                                v-model="form.country"
                                class="mt-1"
                                placeholder="Start typing a country…"
                                :search="searchCountries"
                                :invalid="!!errors.country"
                                @select="onCountrySelect"
                                @clear="onCountryClear"
                            />
                            <InputError
                                class="mt-1"
                                :message="errors.country"
                                :data-error="errors.country ? true : undefined"
                            />
                        </div>

                        <div>
                            <label
                                for="suggest-state"
                                class="text-sm font-medium text-fg"
                                >State / province</label
                            >
                            <SuggestTypeahead
                                id="suggest-state"
                                v-model="form.state"
                                class="mt-1"
                                :disabled="!form.country"
                                :placeholder="
                                    form.country
                                        ? 'Start typing…'
                                        : 'Choose a country first'
                                "
                                :hint="stateHint"
                                :search="searchStates"
                                @select="onStateSelect"
                                @clear="onStateClear"
                            />
                            <InputError
                                class="mt-1"
                                :message="errors.state"
                                :data-error="errors.state ? true : undefined"
                            />
                        </div>

                        <div>
                            <label
                                for="suggest-city"
                                class="text-sm font-medium text-fg"
                                >City</label
                            >
                            <SuggestTypeahead
                                id="suggest-city"
                                v-model="form.city"
                                class="mt-1"
                                :disabled="!form.state"
                                :placeholder="
                                    form.state
                                        ? 'Start typing…'
                                        : 'Choose a state or province first'
                                "
                                :hint="cityHint"
                                :search="searchCities"
                                @clear="onCityClear"
                            />
                            <InputError
                                class="mt-1"
                                :message="errors.city"
                                :data-error="errors.city ? true : undefined"
                            />
                        </div>
                    </template>

                    <div>
                        <label
                            for="suggest-website"
                            class="text-sm font-medium text-fg"
                            >Website</label
                        >
                        <input
                            id="suggest-website"
                            v-model="form.website"
                            type="url"
                            class="ds-input mt-1"
                            maxlength="255"
                            placeholder="https://"
                            autocomplete="off"
                        />
                        <InputError
                            class="mt-1"
                            :message="errors.website"
                            :data-error="errors.website ? true : undefined"
                        />
                    </div>

                    <div>
                        <label
                            for="suggest-notes"
                            class="text-sm font-medium text-fg"
                        >
                            {{
                                isCorrection
                                    ? 'What needs fixing? *'
                                    : 'Anything else?'
                            }}
                        </label>
                        <textarea
                            id="suggest-notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="2000"
                            class="ds-input mt-1 resize-y"
                        />
                        <InputError
                            class="mt-1"
                            :message="errors.notes"
                            :data-error="errors.notes ? true : undefined"
                        />
                    </div>

                    <div>
                        <label
                            for="suggest-email"
                            class="text-sm font-medium text-fg"
                            >Your email *</label
                        >
                        <input
                            id="suggest-email"
                            v-model="form.submitter_email"
                            type="email"
                            class="ds-input mt-1"
                            maxlength="255"
                            autocomplete="off"
                        />
                        <p class="mt-1 text-xs text-fg-subtle">
                            Only used to follow up on this suggestion.
                        </p>
                        <InputError
                            class="mt-1"
                            :message="errors.submitter_email"
                            :data-error="
                                errors.submitter_email ? true : undefined
                            "
                        />
                    </div>
                </div>

                <!--
                    Honeypot. Off-screen rather than display:none — bots
                    increasingly skip hidden inputs. tabindex and aria-hidden
                    keep it away from keyboards and screen readers.

                    Deliberately outside the scroll container: an off-screen
                    absolute child of an overflow-y-auto box forces overflow-x to
                    auto, which would give the body a horizontal scrollbar.
                -->
                <div
                    class="absolute top-0 -left-[9999px] h-px w-px overflow-hidden"
                    aria-hidden="true"
                >
                    <label for="suggest-contact-reference"
                        >Leave this field blank</label
                    >
                    <input
                        id="suggest-contact-reference"
                        v-model="form.contact_reference"
                        type="text"
                        tabindex="-1"
                        autocomplete="off"
                    />
                </div>

                <DialogFooter class="shrink-0 gap-2 border-t border-line p-6">
                    <button
                        type="button"
                        class="ds-btn ds-btn--secondary px-4 py-2.5 text-sm"
                        @click="onOpenChange(false)"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="ds-btn ds-btn--primary px-4 py-2.5 text-sm"
                        :disabled="processing"
                    >
                        {{ processing ? 'Sending…' : 'Send suggestion' }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
