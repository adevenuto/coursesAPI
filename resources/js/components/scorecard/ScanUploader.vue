<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ImagePlus, Loader2, Upload, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';

const props = defineProps<{
    courseId: number | null;
    maxImages: number;
    maxImageMb: number;
}>();

const form = useForm<{ images: File[]; course_id: number | null }>({
    images: [],
    course_id: props.courseId,
});

const input = ref<HTMLInputElement | null>(null);
const dragging = ref(false);

// Object URLs for the local previews. Revoked on removal so a long session
// picking through cards doesn't leak them.
const previews = ref<string[]>([]);

const atLimit = computed(() => form.images.length >= props.maxImages);

function addFiles(files: FileList | null) {
    if (!files) return;

    for (const file of Array.from(files)) {
        if (form.images.length >= props.maxImages) break;
        form.images.push(file);
        previews.value.push(URL.createObjectURL(file));
    }
}

function remove(index: number) {
    URL.revokeObjectURL(previews.value[index]);
    previews.value.splice(index, 1);
    form.images.splice(index, 1);
}

function onDrop(event: DragEvent) {
    dragging.value = false;
    addFiles(event.dataTransfer?.files ?? null);
}

function onPick(event: Event) {
    addFiles((event.target as HTMLInputElement).files);
    // Reset so picking the same file twice still fires a change event.
    if (input.value) input.value.value = '';
}

function submit() {
    form.post('/scorecard-scans', { forceFormData: true });
}

function humanSize(bytes: number): string {
    return bytes < 1024 * 1024 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
</script>

<template>
    <div class="ds-card p-6">
        <h2 class="font-mono text-[11px] tracking-[0.18em] text-fg-subtle uppercase">Scorecard images</h2>
        <p class="mt-2 text-sm text-fg-muted">
            Upload up to {{ maxImages }} photos of the scorecard — front and back, or a wide card shot in halves.
            Sharper images read better; images are resized automatically.
        </p>

        <!-- drop zone -->
        <div
            class="mt-4 rounded-xl border border-dashed p-8 text-center transition"
            :class="dragging ? 'border-lime-500 bg-lime-500/5' : 'border-line'"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <ImagePlus class="mx-auto size-8 text-fg-subtle" />
            <p class="mt-3 text-sm text-fg-muted">
                Drag images here, or
                <button type="button" class="text-lime-500 underline underline-offset-2" @click="input?.click()">
                    browse
                </button>
            </p>
            <p class="mt-1 text-xs text-fg-subtle">JPG, PNG or WebP · up to {{ maxImageMb }} MB each</p>
            <input
                ref="input"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                class="hidden"
                @change="onPick"
            />
        </div>

        <InputError class="mt-2" :message="form.errors.images" />
        <InputError class="mt-2" :message="(form.errors as Record<string, string>)['images.0']" />

        <!-- staged files -->
        <div v-if="form.images.length" class="mt-4 grid gap-3 sm:grid-cols-2">
            <div
                v-for="(file, i) in form.images"
                :key="i"
                class="relative overflow-hidden rounded-lg border border-line"
            >
                <img :src="previews[i]" :alt="file.name" class="h-40 w-full object-cover" />
                <div class="flex items-center justify-between gap-2 px-3 py-2">
                    <span class="truncate text-xs text-fg-muted">{{ file.name }}</span>
                    <span class="shrink-0 font-mono text-[11px] text-fg-subtle">{{ humanSize(file.size) }}</span>
                </div>
                <button
                    type="button"
                    class="absolute top-2 right-2 rounded-full bg-ink-900/80 p-1 text-fg-muted transition hover:text-fg"
                    aria-label="Remove image"
                    @click="remove(i)"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>

        <p v-if="atLimit" class="mt-3 text-xs text-fg-subtle">
            Maximum of {{ maxImages }} images reached.
        </p>

        <div class="mt-5 flex items-center gap-3">
            <Button type="button" :disabled="!form.images.length || form.processing" @click="submit">
                <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                <Upload v-else class="size-4" />
                {{ form.processing ? 'Uploading…' : 'Upload' }}
            </Button>
            <span v-if="form.processing && form.progress" class="text-xs text-fg-subtle">
                {{ form.progress.percentage }}%
            </span>
        </div>
    </div>
</template>
