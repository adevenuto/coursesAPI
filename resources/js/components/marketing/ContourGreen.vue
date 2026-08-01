<script setup lang="ts">
// Signature visual: a putting green rendered as a topographic contour map with
// a pulsing GPS center pin + coordinate annotations. Draws itself in on mount.
withDefaults(
    defineProps<{
        coord?: string;
        hole?: string;
    }>(),
    {
        coord: '34.11098, -85.64477',
        hole: 'HOLE 7 · GREEN',
    },
);

// Concentric contour rings (organic: varied radii, offset, rotation).
const rings = [
    { rx: 196, ry: 168, rot: -8, o: 0.1, d: 0 },
    { rx: 168, ry: 146, rot: -4, o: 0.14, d: 0.15 },
    { rx: 140, ry: 124, rot: 3, o: 0.18, d: 0.3 },
    { rx: 112, ry: 100, rot: 9, o: 0.24, d: 0.45 },
    { rx: 84, ry: 78, rot: 6, o: 0.32, d: 0.6 },
    { rx: 56, ry: 54, rot: -2, o: 0.45, d: 0.75 },
    { rx: 30, ry: 30, rot: 0, o: 0.7, d: 0.9 },
];
const cx = 232;
const cy = 208;
</script>

<template>
    <div class="relative select-none">
        <svg
            viewBox="0 0 440 420"
            fill="none"
            class="h-auto w-full overflow-visible"
            role="img"
            aria-label="Topographic map of a golf green with GPS center"
        >
            <!-- contour rings -->
            <g>
                <ellipse
                    v-for="(r, i) in rings"
                    :key="i"
                    :cx="cx"
                    :cy="cy"
                    :rx="r.rx"
                    :ry="r.ry"
                    :transform="`rotate(${r.rot} ${cx} ${cy})`"
                    :stroke="
                        i >= 5
                            ? 'var(--mk-accent)'
                            : 'var(--mk-accent2)'
                    "
                    :stroke-opacity="r.o"
                    stroke-width="1.5"
                    pathLength="1"
                    class="mk-draw"
                    :style="{ animationDelay: `${r.d}s` }"
                />
            </g>

            <!-- crosshair -->
            <g stroke="var(--mk-accent)" stroke-opacity="0.5" stroke-width="1">
                <line :x1="cx - 46" :y1="cy" :x2="cx - 14" :y2="cy" />
                <line :x1="cx + 14" :y1="cy" :x2="cx + 46" :y2="cy" />
                <line :x1="cx" :y1="cy - 46" :x2="cx" :y2="cy - 14" />
                <line :x1="cx" :y1="cy + 14" :x2="cx" :y2="cy + 46" />
            </g>

            <!-- annotation flags to a couple of ring points -->
            <g
                stroke="var(--mk-border)"
                stroke-width="1"
                font-family="var(--font-mono)"
            >
                <line :x1="cx + 110" :y1="cy - 96" x2="404" y2="86" />
                <circle :cx="cx + 110" :cy="cy - 96" r="2.5" fill="var(--mk-accent)" stroke="none" />
                <text x="408" y="82" fill="var(--mk-muted)" font-size="11">
                    slope 2.4%
                </text>
                <line :x1="cx - 120" :y1="cy + 88" x2="30" y2="360" />
                <circle :cx="cx - 120" :cy="cy + 88" r="2.5" fill="var(--mk-accent)" stroke="none" />
                <text x="30" y="378" fill="var(--mk-muted)" font-size="11">
                    front edge
                </text>
            </g>

            <!-- GPS center pin -->
            <g>
                <circle
                    :cx="cx"
                    :cy="cy"
                    r="10"
                    fill="var(--mk-accent)"
                    fill-opacity="0.5"
                    class="mk-ping"
                    style="transform-origin: center; transform-box: fill-box"
                />
                <circle :cx="cx" :cy="cy" r="6" fill="var(--mk-accent)" />
                <circle :cx="cx" :cy="cy" r="2.5" fill="var(--mk-bg)" />
            </g>
        </svg>

        <!-- coordinate readout (mono) -->
        <div
            class="absolute bottom-3 left-3 rounded-md border border-mk-border bg-mk-bg/70 px-3 py-2 font-mono text-xs backdrop-blur-sm"
        >
            <div class="text-[10px] tracking-widest text-mk-muted">
                {{ hole }}
            </div>
            <div class="mt-0.5 text-mk-accent">{{ coord }}</div>
        </div>
    </div>
</template>
