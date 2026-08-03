<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Flag, MapPin } from '@lucide/vue';

interface ResultCourse {
    id: number;
    name: string;
    club: string | null;
    city: string | null;
    state: string | null;
    url: string;
}

defineProps<{
    area: { type: string; name: string; label: string } | null;
    courses: ResultCourse[];
    count: number;
    capped: boolean;
    loading: boolean;
}>();
</script>

<template>
    <div>
        <div v-if="loading" class="space-y-2">
            <div v-for="i in 5" :key="i" class="h-16 animate-pulse rounded-xl border border-line bg-ink-800" />
        </div>

        <template v-else-if="area">
            <div class="mb-3 flex items-baseline justify-between">
                <h2 class="font-display text-lg font-semibold text-fg">{{ area.label }}</h2>
                <span class="font-mono text-xs text-fg-subtle">
                    {{ count.toLocaleString() }} course{{ count === 1 ? '' : 's' }}
                </span>
            </div>

            <div v-if="courses.length" class="space-y-2">
                <Link
                    v-for="course in courses"
                    :key="course.id"
                    :href="course.url"
                    class="ds-card ds-card--hover flex items-center gap-3 p-4"
                >
                    <span class="ds-icon-tile shrink-0">
                        <Flag class="size-4 text-lime-500" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-fg">{{ course.name }}</span>
                        <span class="flex items-center gap-1 text-xs text-fg-subtle">
                            <MapPin class="size-3 shrink-0" />
                            <span class="truncate">{{ [course.city, course.state].filter(Boolean).join(', ') || '—' }}</span>
                        </span>
                    </span>
                </Link>
            </div>
            <p v-else class="rounded-xl border border-line bg-ink-800 p-6 text-center text-sm text-fg-subtle">
                No mapped courses in this area yet.
            </p>

            <p v-if="capped" class="mt-3 text-center font-mono text-[11px] text-fg-subtle">
                Showing the first {{ courses.length }} of {{ count.toLocaleString() }}.
            </p>
        </template>

        <div v-else class="rounded-xl border border-dashed border-line p-10 text-center">
            <p class="text-sm text-fg-muted">Search above to explore courses by place.</p>
            <p class="mt-1 text-xs text-fg-subtle">Pick a city, state, or country to list its courses.</p>
        </div>
    </div>
</template>
