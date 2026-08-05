<script setup lang="ts">
import { ref } from 'vue';
import SectionHeading from './SectionHeading.vue';
import CodeBlock from './CodeBlock.vue';

// Responses below are captured verbatim from the live API (arrays trimmed
// for brevity). Keep these in sync with the actual endpoints.
const tabs = [
    {
        key: 'search',
        name: 'Search & filter',
        method: 'GET',
        path: '/api/v1/courses?q=bowling',
        code: `{
  "data": [
    {
      "id": 4,
      "name": "Bowling Green Country Club",
      "club": "Bowling Green Country Club",
      "city": "Bowling Green",
      "state": "Kentucky",
      "country": "US",
      "latitude": 37.0132,
      "longitude": -86.43378
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "last_page": 1, "total": 6 }
}`,
    },
    {
        key: 'near',
        name: 'Near me',
        method: 'GET',
        path: '/api/v1/courses?lat=37.014&lng=-86.434&radius=25',
        code: `{
  "data": [
    {
      "id": 4,
      "name": "Bowling Green Country Club",
      "club": "Bowling Green Country Club",
      "city": "Bowling Green",
      "state": "Kentucky",
      "country": "US",
      "latitude": 37.0132,
      "longitude": -86.43378,
      "distance_km": 0.09
    },
    {
      "id": 331,
      "name": "Indian Hills Country Club",
      "city": "Bowling Green",
      "state": "Kentucky",
      "country": "US",
      "distance_km": 3.72
    }
  ],
  "meta": { "current_page": 1, "per_page": 25, "last_page": 1, "total": 5 }
}`,
    },
    {
        key: 'detail',
        name: 'Course detail',
        method: 'GET',
        path: '/api/v1/courses/4',
        code: `{
  "data": {
    "id": 4,
    "name": "Bowling Green Country Club",
    "club": "Bowling Green Country Club",
    "address": "251 Beech Bend Rd, Bowling Green, KY 42101, USA",
    "postal_code": "42101",
    "phone": null,
    "website": null,
    "location": {
      "city": "Bowling Green",
      "state": "Kentucky",
      "country": { "name": "United States", "iso2": "US" }
    },
    "coordinates": { "latitude": 37.0132, "longitude": -86.43378 },
    "scorecard": {
      "hole_count": 18,
      "teeboxes": [
        {
          "name": "Gold",
          "rating": 73.3, "rating_women": 71.2,
          "slope": 128, "slope_women": 120,
          "total_yards": 6800,
          "holes": [
            { "hole": 1, "par": 4, "yards": 437, "handicap": 7, "handicap_women": 9 },
            { "hole": 2, "par": 5, "yards": 518, "handicap": 1, "handicap_women": 1 }
          ]
        }
      ]
    },
    "green_centers_available": true
  }
}`,
    },
    {
        key: 'green',
        name: 'Green centers',
        method: 'GET',
        path: '/api/v1/courses/4/green-centers',
        premium: true,
        code: `{
  "data": {
    "course_id": 4,
    "holes": [
      { "hole": 1, "lat": 37.017442644114, "lng": -86.43135309219 },
      { "hole": 2, "lat": 37.019378640182, "lng": -86.43487751483 },
      { "hole": 3, "lat": 37.017583990629, "lng": -86.43266201019 }
    ]
  }
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
