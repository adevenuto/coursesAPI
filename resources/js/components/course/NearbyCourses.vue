<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Flag, MapPinOff } from '@lucide/vue';

/**
 * Courses near this one — other routings of the same facility first, then real
 * neighbours, in one distance-ordered list.
 *
 * Shared by the editor and the public course page. The two differ only in where
 * a row links and whether the placeholder-coordinate warning is worth showing:
 * that notice is a prompt to go and fix the position, which means nothing to a
 * visitor, so the public variant renders nothing at all in that case.
 */

export interface NearbyCourse {
    id: number;
    course_name: string | null;
    club_name: string | null;
    hole_count: number | null;
    green_centers_available: boolean;
    distance_mi: number;
    same_club: boolean;
    edit_url: string;
    url: string;
}

export interface Nearby {
    radius_mi: number;
    // Set when this course sits on a shared geocoding placeholder, in which case
    // there is no meaningful neighbour list to show.
    placeholder: { courses: number; clubs: number } | null;
    courses: NearbyCourse[];
}

const props = withDefaults(
    defineProps<{
        nearby?: Nearby | null;
        /** 'edit' links rows to the editor and surfaces the placeholder notice. */
        linkTo?: 'edit' | 'public';
    }>(),
    { nearby: null, linkTo: 'public' },
);

const href = (course: NearbyCourse) => (props.linkTo === 'edit' ? course.edit_url : course.url);
</script>

<template>
    <section
        v-if="nearby && ((linkTo === 'edit' && nearby.placeholder) || nearby.courses.length)"
        class="ds-card p-6"
    >
        <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Nearby courses</h2>

        <!-- A coordinate shared by many different clubs is a geocoding
             placeholder, so listing "neighbours" would be noise. Say so
             instead — it's a prompt to fix the location. -->
        <div
            v-if="linkTo === 'edit' && nearby.placeholder"
            class="mt-4 flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-fg"
        >
            <MapPinOff class="mt-0.5 size-4 shrink-0 text-amber-400" />
            <span>
                These coordinates are shared with {{ nearby.placeholder.courses }} other course{{ nearby.placeholder.courses === 1 ? '' : 's' }}
                across {{ nearby.placeholder.clubs }} different clubs, so they're almost certainly a placeholder
                rather than this course's real location. Fix the position below to see genuine neighbours.
            </span>
        </div>

        <template v-else>
            <p class="mt-1 text-xs text-fg-subtle">
                {{ nearby.courses.length }} course{{ nearby.courses.length === 1 ? '' : 's' }} within
                {{ nearby.radius_mi }} miles, closest first.
            </p>
            <div class="mt-4 space-y-2">
                <Link
                    v-for="s in nearby.courses"
                    :key="s.id"
                    :href="href(s)"
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
                            <span v-if="s.same_club" class="ds-badge ds-badge--lime shrink-0 text-[10px]">
                                same property
                            </span>
                        </span>
                        <span class="flex flex-wrap items-center gap-x-2 text-xs text-fg-subtle">
                            <span v-if="s.hole_count">{{ s.hole_count }} holes</span>
                            <span v-if="!s.same_club && s.club_name" class="min-w-0 truncate">{{ s.club_name }}</span>
                        </span>
                    </span>
                    <span class="shrink-0 font-mono text-[11px] text-fg-subtle">
                        {{ s.distance_mi === 0 ? 'same spot' : `${s.distance_mi} mi` }}
                    </span>
                </Link>
            </div>
        </template>
    </section>
</template>
