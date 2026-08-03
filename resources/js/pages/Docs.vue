<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { register } from '@/routes';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import DocsSidebar from '@/components/marketing/docs/DocsSidebar.vue';
import CodeBlock from '@/components/marketing/CodeBlock.vue';
import GlowBadge from '@/components/marketing/GlowBadge.vue';

interface PlanConfig {
    label: string;
    per_day: number;
    per_minute: number;
    premium: boolean;
}

const props = defineProps<{
    plans: Record<'free' | 'pro' | 'max', PlanConfig>;
    baseUrl: string;
    pagination: { default_per_page: number; max_per_page: number };
    maxRadiusKm: number;
}>();

const api = computed(() => `${props.baseUrl}/api/v1`);
const nf = (n: number) => n.toLocaleString('en-US');
const planRows = computed(() =>
    (['free', 'pro', 'max'] as const).map((k) => props.plans[k]),
);

const sections = [
    { id: 'introduction', label: 'Introduction' },
    { id: 'authentication', label: 'Authentication' },
    { id: 'rate-limits', label: 'Rate limits & plans' },
    { id: 'pagination', label: 'Pagination' },
    { id: 'errors', label: 'Errors' },
    { id: 'ep-courses', label: 'List & search courses' },
    { id: 'ep-detail', label: 'Get a course' },
    { id: 'ep-green', label: 'Green centers' },
    { id: 'ep-geo', label: 'Geo lookups' },
];

const curl = (path: string) =>
    `curl "${api.value}${path}" \\\n  -H "Authorization: Bearer YOUR_API_KEY" \\\n  -H "Accept: application/json"`;

// --- Real responses (captured from the live API; arrays trimmed) ----------
const resList = `{
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
  "links": {
    "first": "${api.value}/courses?q=bowling&page=1",
    "last": "${api.value}/courses?q=bowling&page=1",
    "prev": null,
    "next": null
  },
  "meta": { "current_page": 1, "per_page": 25, "last_page": 1, "total": 6 }
}`;

const resNear = `{
  "data": [
    { "id": 4, "name": "Bowling Green Country Club",
      "club": "Bowling Green Country Club", "city": "Bowling Green",
      "state": "Kentucky", "country": "US", "latitude": 37.0132,
      "longitude": -86.43378, "distance_km": 0.09 },
    { "id": 331, "name": "Indian Hills Country Club",
      "club": "Indian Hills Country Club", "city": "Bowling Green",
      "state": "Kentucky", "country": "US", "latitude": 36.993816,
      "longitude": -86.400566, "distance_km": 3.72 }
  ],
  "meta": { "current_page": 1, "per_page": 25, "last_page": 1, "total": 5 }
}`;

const resDetail = `{
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
          "name": "Gold", "rating": 73.3, "slope": 128, "total_yards": 6800,
          "holes": [
            { "hole": 1, "par": 4, "yards": 437, "handicap": 7 },
            { "hole": 2, "par": 5, "yards": 518, "handicap": 1 }
          ]
        }
      ]
    },
    "green_centers_available": true
  }
}`;

const resGreen = `{
  "data": {
    "course_id": 4,
    "holes": [
      { "hole": 1, "lat": 37.017442644114, "lng": -86.43135309219 },
      { "hole": 2, "lat": 37.019378640182, "lng": -86.43487751483 },
      { "hole": 3, "lat": 37.017583990629, "lng": -86.43266201019 }
    ]
  }
}`;

const resGreenForbidden = `{
  "message": "Green-center data requires a Pro or Max plan.",
  "upgrade_url": "${props.baseUrl}/#pricing"
}`;

const resCountries = `{
  "data": [
    { "id": 1, "name": "Afghanistan", "iso2": "AF", "iso3": "AFG" },
    { "id": 2, "name": "Aland Islands", "iso2": "AX", "iso3": "ALA" }
  ]
}`;

const resStates = `{
  "data": [
    { "id": 1456, "name": "Alabama", "iso2": "AL", "country_id": 233 },
    { "id": 1400, "name": "Alaska", "iso2": "AK", "country_id": 233 }
  ]
}`;

const resPagination = `{
  "data": [ /* ... */ ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 25, "last_page": 4, "total": 92 }
}`;

const res422 = `{
  "message": "The radius field must not be greater than 100.",
  "errors": {
    "radius": ["The radius field must not be greater than 100."]
  }
}`;

const resCities = `{
  "data": [
    { "id": 110968, "name": "Abbeville", "state_id": 1456, "country_id": 233,
      "latitude": 31.57184, "longitude": -85.25049 },
    { "id": 111032, "name": "Adamsville", "state_id": 1456, "country_id": 233,
      "latitude": 33.60094, "longitude": -86.95611 }
  ],
  "meta": { "current_page": 1, "per_page": 25, "last_page": 13, "total": 311 }
}`;
</script>

<template>
    <Head title="API Documentation — Fairway">
        <meta name="description" content="Fairway golf course API documentation: authentication, rate limits, pagination, and endpoint reference with live examples." />
    </Head>

    <MarketingLayout>
        <MarketingNav />

        <div class="mx-auto max-w-[1120px] px-5 sm:px-7">
            <div class="grid gap-10 py-12 lg:grid-cols-[210px_1fr] lg:py-16">
                <DocsSidebar :sections="sections" />

                <main class="min-w-0 space-y-16">
                    <!-- Intro -->
                    <section id="introduction" class="scroll-mt-24">
                        <GlowBadge>API v1</GlowBadge>
                        <h1 class="mt-5 font-display text-4xl font-bold tracking-tight text-fg" style="letter-spacing: -0.02em">
                            Fairway API
                        </h1>
                        <p class="mt-4 max-w-2xl text-lg text-fg-muted">
                            A fast, REST + JSON API for golf course data — locations,
                            scorecards, and per-hole green-center GPS. All endpoints are
                            served under a single versioned base URL and return JSON.
                        </p>
                        <div class="mt-6 flex flex-wrap items-center gap-3">
                            <div class="rounded-lg border border-line bg-ink-800 px-4 py-2 font-mono text-sm">
                                <span class="text-fg-subtle">Base URL</span>
                                <span class="ml-2 text-mk-accent">{{ api }}</span>
                            </div>
                            <Link :href="register()" class="ds-btn ds-btn--primary px-4 py-2 text-sm">
                                Get a free key
                            </Link>
                        </div>
                        <div class="mt-6">
                            <CodeBlock method="GET" label="/courses?q=bowling — request" :code="curl('/courses?q=bowling')" />
                        </div>
                    </section>

                    <!-- Auth -->
                    <section id="authentication" class="scroll-mt-24">
                        <h2 class="font-display text-2xl font-bold text-fg">Authentication</h2>
                        <p class="mt-3 max-w-2xl text-fg-muted">
                            Every request must include a <strong class="text-fg">Bearer token</strong>.
                            Sign in, then create a key under
                            <Link href="/settings/api-keys" class="text-mk-accent hover:underline">Settings → API keys</Link>
                            (shown once — copy it right away). A key belongs to your account, so
                            all of your keys share your plan and quota.
                        </p>
                        <div class="mt-5">
                            <CodeBlock method="HEADER" label="every request" :code="`Authorization: Bearer YOUR_API_KEY\nAccept: application/json`" />
                        </div>
                        <p class="mt-3 text-sm text-fg-subtle">
                            Requests without a valid key return <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">401 Unauthenticated</code>.
                        </p>
                    </section>

                    <!-- Rate limits -->
                    <section id="rate-limits" class="scroll-mt-24">
                        <h2 class="font-display text-2xl font-bold text-fg">Rate limits & plans</h2>
                        <p class="mt-3 max-w-2xl text-fg-muted">
                            Limits are enforced <strong class="text-fg">per account</strong> (all your keys draw from one pool),
                            with a daily quota and a per-minute burst cap. Green-center endpoints require a paid plan.
                        </p>
                        <div class="mt-5 overflow-x-auto rounded-xl border border-line">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-line text-fg-subtle">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">Plan</th>
                                        <th class="px-4 py-3 font-medium">Requests / day</th>
                                        <th class="px-4 py-3 font-medium">Burst / min</th>
                                        <th class="px-4 py-3 font-medium">Green centers</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr v-for="p in planRows" :key="p.label">
                                        <td class="px-4 py-3 font-medium text-fg">{{ p.label }}</td>
                                        <td class="px-4 py-3 text-fg-muted">{{ nf(p.per_day) }}</td>
                                        <td class="px-4 py-3 text-fg-muted">{{ nf(p.per_minute) }}</td>
                                        <td class="px-4 py-3">
                                            <span :class="p.premium ? 'text-mk-accent' : 'text-fg-subtle'">{{ p.premium ? 'Included' : '—' }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-3 text-sm text-fg-subtle">
                            When you exceed a limit you get <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">429 Too Many Requests</code>
                            with a <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">Retry-After</code> header. Every response
                            includes <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">X-RateLimit-Limit</code> and
                            <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">X-RateLimit-Remaining</code>.
                        </p>
                    </section>

                    <!-- Pagination -->
                    <section id="pagination" class="scroll-mt-24">
                        <h2 class="font-display text-2xl font-bold text-fg">Pagination</h2>
                        <p class="mt-3 max-w-2xl text-fg-muted">
                            List endpoints are paginated. Use <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">page</code>
                            and <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">per_page</code>
                            (default {{ pagination.default_per_page }}, max {{ pagination.max_per_page }}). Responses carry
                            <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">links</code> and
                            <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">meta</code>:
                        </p>
                        <div class="mt-5">
                            <CodeBlock method="200" label="pagination envelope" :code="resPagination" />
                        </div>
                    </section>

                    <!-- Errors -->
                    <section id="errors" class="scroll-mt-24">
                        <h2 class="font-display text-2xl font-bold text-fg">Errors</h2>
                        <p class="mt-3 max-w-2xl text-fg-muted">
                            Errors use standard HTTP status codes and a JSON body with a
                            <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">message</code>.
                        </p>
                        <div class="mt-5 overflow-x-auto rounded-xl border border-line">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-line text-fg-subtle">
                                    <tr><th class="px-4 py-3 font-medium">Status</th><th class="px-4 py-3 font-medium">Meaning</th></tr>
                                </thead>
                                <tbody class="divide-y divide-line text-fg-muted">
                                    <tr><td class="px-4 py-3 font-mono text-fg">401</td><td class="px-4 py-3">Missing or invalid API key.</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">403</td><td class="px-4 py-3">Endpoint requires a paid plan (green centers).</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">404</td><td class="px-4 py-3">Resource not found (or no data for it).</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">422</td><td class="px-4 py-3">Invalid query parameters (see <code>errors</code>).</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">429</td><td class="px-4 py-3">Rate limit exceeded.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-5">
                            <CodeBlock method="422" label="validation error" :code="res422" />
                        </div>
                    </section>

                    <!-- List courses -->
                    <section id="ep-courses" class="scroll-mt-24">
                        <h2 class="font-display text-2xl font-bold text-fg">List &amp; search courses</h2>
                        <p class="mt-1 font-mono text-sm text-mk-accent">GET /courses</p>
                        <p class="mt-3 max-w-2xl text-fg-muted">
                            Search and filter courses. Combine any parameters; results are paginated.
                        </p>
                        <div class="mt-5 overflow-x-auto rounded-xl border border-line">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-line text-fg-subtle">
                                    <tr><th class="px-4 py-3 font-medium">Param</th><th class="px-4 py-3 font-medium">Description</th></tr>
                                </thead>
                                <tbody class="divide-y divide-line text-fg-muted">
                                    <tr><td class="px-4 py-3 font-mono text-fg">q</td><td class="px-4 py-3">Search course / club name.</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">country</td><td class="px-4 py-3">ISO2 code (e.g. <code>US</code>) or country id.</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">state_prov_id</td><td class="px-4 py-3">Filter by state/province id.</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">city_id</td><td class="px-4 py-3">Filter by city id.</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">lat, lng, radius</td><td class="px-4 py-3">Near-me search (radius in km, max {{ maxRadiusKm }}). Adds <code>distance_km</code>, sorted nearest first.</td></tr>
                                    <tr><td class="px-4 py-3 font-mono text-fg">page, per_page</td><td class="px-4 py-3">Pagination.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-5 grid gap-4">
                            <CodeBlock method="GET" label="/courses?q=bowling" :code="curl('/courses?q=bowling')" />
                            <CodeBlock method="200" label="response" :code="resList" />
                        </div>
                        <p class="mt-6 mb-2 text-sm font-medium text-fg">Near-me example</p>
                        <div class="grid gap-4">
                            <CodeBlock method="GET" label="/courses?lat=&amp;lng=&amp;radius=" :code="curl('/courses?lat=37.014&lng=-86.434&radius=25')" />
                            <CodeBlock method="200" label="response" :code="resNear" />
                        </div>
                    </section>

                    <!-- Detail -->
                    <section id="ep-detail" class="scroll-mt-24">
                        <h2 class="font-display text-2xl font-bold text-fg">Get a course</h2>
                        <p class="mt-1 font-mono text-sm text-mk-accent">GET /courses/{id}</p>
                        <p class="mt-3 max-w-2xl text-fg-muted">
                            Full detail including resolved location and the scorecard (teeboxes with
                            per-hole par, yardage, and handicap).
                        </p>
                        <div class="mt-5 grid gap-4">
                            <CodeBlock method="GET" label="/courses/4" :code="curl('/courses/4')" />
                            <CodeBlock method="200" label="response (scorecard trimmed)" :code="resDetail" />
                        </div>
                    </section>

                    <!-- Green centers -->
                    <section id="ep-green" class="scroll-mt-24">
                        <div class="flex items-center gap-3">
                            <h2 class="font-display text-2xl font-bold text-fg">Green centers</h2>
                            <span class="ds-badge ds-badge--lime !px-2 !py-0.5 font-body text-[10px]">Paid</span>
                        </div>
                        <p class="mt-1 font-mono text-sm text-mk-accent">GET /courses/{id}/green-centers</p>
                        <p class="mt-3 max-w-2xl text-fg-muted">
                            Per-hole green-center GPS. Requires a <strong class="text-fg">Pro or Max</strong> plan —
                            free keys get <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">403</code>.
                            The course detail also carries <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">green_centers_available</code>.
                        </p>
                        <div class="mt-5 grid gap-4">
                            <CodeBlock method="GET" label="/courses/4/green-centers" :code="curl('/courses/4/green-centers')" />
                            <CodeBlock method="200" label="response (holes trimmed)" :code="resGreen" />
                            <CodeBlock method="403" label="free plan" :code="resGreenForbidden" />
                        </div>
                    </section>

                    <!-- Geo -->
                    <section id="ep-geo" class="scroll-mt-24">
                        <h2 class="font-display text-2xl font-bold text-fg">Geo lookups</h2>
                        <p class="mt-3 max-w-2xl text-fg-muted">
                            Reference data to build filters: drill from country → state → city, then pass
                            the ids to <code class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-fg">/courses</code>.
                        </p>
                        <div class="mt-5 grid gap-4">
                            <CodeBlock method="GET" label="/countries" :code="curl('/countries')" />
                            <CodeBlock method="200" label="response (trimmed)" :code="resCountries" />
                            <CodeBlock method="GET" label="/states?country=US" :code="curl('/states?country=US')" />
                            <CodeBlock method="200" label="response (trimmed)" :code="resStates" />
                            <CodeBlock method="GET" label="/cities?state_prov_id=1456" :code="curl('/cities?state_prov_id=1456')" />
                            <CodeBlock method="200" label="response (trimmed)" :code="resCities" />
                        </div>
                    </section>
                </main>
            </div>
        </div>

        <MarketingFooter />
    </MarketingLayout>
</template>
