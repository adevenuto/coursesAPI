<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';
import { Menu, X } from '@lucide/vue';
import { computed, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const open = ref(false);

// Absolute paths so anchors work from the landing (same-path hash scroll)
// and from /docs (navigate home, then scroll).
const links = [
    { label: 'Features', href: '/#features' },
    { label: 'Pricing', href: '/#pricing' },
    { label: 'Docs', href: '/docs' },
];

const close = () => (open.value = false);
</script>

<template>
    <header
        class="sticky top-0 z-50 border-b border-line"
        style="background: rgba(10, 11, 10, 0.6); backdrop-filter: blur(16px)"
    >
        <nav class="mx-auto flex h-16 max-w-[1120px] items-center gap-6 px-5 sm:px-7">
            <a href="/" class="flex flex-col leading-none" @click="close">
                <span class="font-display text-xl font-extrabold tracking-tight text-fg">
                    <span style="color: var(--lime-500)">G</span>CA
                </span>
                <span class="mt-0.5 font-mono text-[9px] font-medium tracking-[0.2em] text-fg-subtle uppercase">
                    Golf Courses API
                </span>
            </a>

            <!-- desktop links -->
            <div class="ml-4 hidden items-center gap-7 md:flex">
                <a
                    v-for="l in links"
                    :key="l.href"
                    :href="l.href"
                    class="text-sm text-fg-muted transition hover:text-fg"
                    >{{ l.label }}</a
                >
            </div>

            <!-- desktop actions -->
            <div class="ml-auto hidden items-center gap-2.5 md:flex">
                <template v-if="user">
                    <Link :href="dashboard()" class="ds-btn ds-btn--primary px-4 py-2 text-sm">
                        Dashboard
                    </Link>
                </template>
                <template v-else>
                    <Link :href="login()" class="ds-btn ds-btn--dark px-4 py-2 text-sm">
                        Sign in
                    </Link>
                    <Link :href="register()" class="ds-btn ds-btn--primary px-4 py-2 text-sm">
                        Get free key
                    </Link>
                </template>
            </div>

            <!-- mobile hamburger -->
            <button
                type="button"
                class="ml-auto grid size-10 place-items-center rounded-lg border border-line-2 text-fg transition hover:border-line-lime md:hidden"
                :aria-expanded="open"
                aria-label="Toggle menu"
                @click="open = !open"
            >
                <component :is="open ? X : Menu" class="size-5" />
            </button>
        </nav>

        <!-- mobile panel -->
        <Transition name="nav">
            <div
                v-if="open"
                class="border-t border-line md:hidden"
                style="background: rgba(10, 11, 10, 0.96); backdrop-filter: blur(14px)"
            >
                <div class="flex flex-col gap-1 px-5 py-4 sm:px-7">
                    <a
                        v-for="l in links"
                        :key="l.href"
                        :href="l.href"
                        class="rounded-lg px-2 py-2.5 text-sm text-fg-muted transition hover:bg-ink-800 hover:text-fg"
                        @click="close"
                        >{{ l.label }}</a
                    >
                    <div class="mt-3 flex flex-col gap-2.5 border-t border-line pt-4">
                        <template v-if="user">
                            <Link
                                :href="dashboard()"
                                class="ds-btn ds-btn--primary w-full px-4 py-2.5 text-sm"
                                @click="close"
                            >
                                Dashboard
                            </Link>
                        </template>
                        <template v-else>
                            <Link
                                :href="login()"
                                class="ds-btn ds-btn--dark w-full px-4 py-2.5 text-sm"
                                @click="close"
                            >
                                Sign in
                            </Link>
                            <Link
                                :href="register()"
                                class="ds-btn ds-btn--primary w-full px-4 py-2.5 text-sm"
                                @click="close"
                            >
                                Get free key
                            </Link>
                        </template>
                    </div>
                </div>
            </div>
        </Transition>
    </header>
</template>

<style scoped>
.nav-enter-active,
.nav-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}
.nav-enter-from,
.nav-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>
