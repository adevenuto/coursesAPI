<script setup lang="ts">
import { ref } from 'vue';
import SectionHeading from './SectionHeading.vue';
import CodeBlock from './CodeBlock.vue';

const tabs = [
    {
        key: 'search',
        name: 'Search & filter',
        method: 'GET',
        path: '/api/v1/courses?q=pine&country=US',
        code: `{
  "data": [
    {
      "id": 8123,
      "name": "Pine Valley Golf Club",
      "city": "Pine Valley",
      "state": "New Jersey",
      "country": "US"
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "total": 214 }
}`,
    },
    {
        key: 'near',
        name: 'Near me',
        method: 'GET',
        path: '/api/v1/courses?lat=33.52&lng=-86.80&radius=25',
        code: `{
  "data": [
    {
      "id": 4711,
      "name": "Highland Park Golf Course",
      "city": "Birmingham",
      "distance_km": 3.4
    }
  ],
  "meta": { "radius_km": 25, "total": 12 }
}`,
    },
    {
        key: 'detail',
        name: 'Course detail',
        method: 'GET',
        path: '/api/v1/courses/4609',
        code: `{
  "id": 4609,
  "name": "Cherokee Country Club",
  "location": { "city": "Centre", "state": "Alabama", "country": "US" },
  "scorecard": {
    "hole_count": 18,
    "teeboxes": [
      { "name": "Blue", "rating": 72.6, "slope": 136,
        "holes": [{ "hole": 1, "par": 4, "yards": 401 }] }
    ]
  }
}`,
    },
    {
        key: 'green',
        name: 'Green centers',
        method: 'GET',
        path: '/api/v1/courses/4609/green-centers',
        premium: true,
        code: `{
  "data": [
    { "hole": 1, "lat": 34.11744, "lng": -86.43135 },
    { "hole": 2, "lat": 34.11937, "lng": -86.43487 },
    { "hole": 7, "lat": 34.11098, "lng": -85.64477 }
  ],
  "source": "golftrax"
}`,
    },
];

const active = ref(0);
</script>

<template>
    <section id="endpoints" class="border-y border-line bg-ink-850">
        <div class="mx-auto max-w-[1120px] px-5 py-20 sm:px-7 lg:py-24">
            <SectionHeading
                eyebrow="Endpoints"
                title="Predictable,"
                accent="boring in the best way."
                subtitle="REST + JSON. Query with plain params, page through results, and read exactly what you'd expect."
            />

            <div class="mt-12 grid gap-8 lg:grid-cols-[280px_1fr]">
                <!-- tab list -->
                <div class="flex gap-2 overflow-x-auto lg:flex-col lg:overflow-visible">
                    <button
                        v-for="(t, i) in tabs"
                        :key="t.key"
                        type="button"
                        class="flex shrink-0 items-center gap-2 rounded-xl border px-4 py-3 text-left text-sm transition"
                        :class="
                            active === i
                                ? 'border-line-lime bg-ink-800 text-fg'
                                : 'border-line text-fg-muted hover:text-fg'
                        "
                        @click="active = i"
                    >
                        <span class="font-medium">{{ t.name }}</span>
                        <span
                            v-if="t.premium"
                            class="ml-auto rounded-full px-1.5 py-0.5 font-mono text-[9px] tracking-widest uppercase"
                            style="
                                color: var(--lime-300);
                                border: 1px solid var(--border-lime);
                            "
                            >Paid</span
                        >
                    </button>
                </div>

                <!-- active panel -->
                <div class="min-w-0">
                    <CodeBlock
                        :key="tabs[active].key"
                        :method="tabs[active].method"
                        :label="tabs[active].path"
                        :code="tabs[active].code"
                    />
                </div>
            </div>
        </div>
    </section>
</template>
