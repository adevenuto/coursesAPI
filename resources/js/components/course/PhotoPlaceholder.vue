<script setup lang="ts">
/**
 * Stand-in course photography for the photos section.
 *
 * Stock images, not this course — the dataset carries no imagery for any of the
 * 22,067 courses. Three lush course photos on a named course's page will be
 * read as photographs OF that course, so the section labels them for what they
 * are rather than letting the picture make a claim the data can't support.
 *
 * Served from public/ rather than hotlinked: this sits on every course page, and
 * a third-party CDN on every view is a dependency, a latency cost and a referrer
 * leak the page doesn't need. Unsplash License — free to use, no permission
 * required.
 */
defineProps<{ src: string; alt: string }>();
</script>

<template>
    <div class="ds-card relative aspect-[4/3] min-w-0 overflow-hidden">
        <img
            :src="src"
            :alt="alt"
            loading="lazy"
            decoding="async"
            width="600"
            height="450"
            class="size-full object-cover"
        />
    </div>
</template>
