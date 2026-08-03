<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { login, register } from '@/routes';

const cols = [
    {
        title: 'Product',
        links: [
            { label: 'Features', href: '/#features' },
            { label: 'Endpoints', href: '/#endpoints' },
            { label: 'Pricing', href: '/#pricing' },
        ],
    },
    {
        title: 'Developers',
        links: [
            { label: 'Documentation', href: '/docs' },
            { label: 'Status', href: '#' },
            { label: 'Changelog', href: '#' },
        ],
    },
];
</script>

<template>
    <footer class="border-t border-line bg-ink-850">
        <div class="mx-auto max-w-[1120px] px-5 py-14 sm:px-7">
            <div class="grid gap-10 md:grid-cols-[1.4fr_1fr_1fr]">
                <div>
                    <div class="flex flex-col leading-none">
                        <span class="font-display text-xl font-extrabold tracking-tight text-fg">
                            <span style="color: var(--lime-500)">G</span>CA
                        </span>
                        <span class="mt-0.5 font-mono text-[9px] font-medium tracking-[0.2em] text-fg-subtle uppercase">
                            Golf Courses API
                        </span>
                    </div>
                    <p class="mt-4 max-w-xs text-sm text-fg-muted">
                        The golf course data API — locations, scorecards and
                        green-center GPS behind one clean endpoint.
                    </p>
                    <div class="mt-5 flex gap-3">
                        <Link :href="register()" class="ds-btn ds-btn--primary px-4 py-2 text-sm">
                            Get free key
                        </Link>
                        <Link :href="login()" class="ds-btn ds-btn--dark px-4 py-2 text-sm">
                            Sign in
                        </Link>
                    </div>
                </div>

                <div v-for="c in cols" :key="c.title">
                    <h4 class="font-mono text-xs tracking-widest text-fg-subtle uppercase">
                        {{ c.title }}
                    </h4>
                    <ul class="mt-4 space-y-2.5">
                        <li v-for="l in c.links" :key="l.label">
                            <a :href="l.href" class="text-sm text-fg-muted transition hover:text-fg">{{
                                l.label
                            }}</a>
                        </li>
                    </ul>
                </div>
            </div>

            <div
                class="mt-12 flex flex-col gap-4 border-t border-line pt-6 text-xs text-fg-subtle sm:flex-row sm:items-center sm:justify-between"
            >
                <p>© {{ new Date().getFullYear() }} GCA. All rights reserved.</p>
                <p class="max-w-xl sm:text-right">
                    Geographic data ©
                    <a href="https://www.openstreetmap.org/copyright" class="underline underline-offset-2 hover:text-fg">OpenStreetMap</a>
                    contributors (ODbL) · country/state/city dataset by
                    <a href="https://github.com/dr5hn/countries-states-cities-database" class="underline underline-offset-2 hover:text-fg">dr5hn</a>
                    (ODbL).
                </p>
            </div>
        </div>
    </footer>
</template>
