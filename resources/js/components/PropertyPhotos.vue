<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';

type Photo = { url: string; description: string | null };

/**
 * A property's listing photos: a large cover, a strip of thumbnails, and a
 * full-screen viewer. Shows nothing when the property has no photos.
 */
const props = defineProps<{
    /** Undefined while loading. */
    photos?: Photo[];
    alt: string;
}>();

const open = ref(false);
const index = ref(0);

const thumbnails = computed(() => (props.photos ?? []).slice(1, 5));
const hiddenCount = computed(() =>
    Math.max((props.photos?.length ?? 0) - 5, 0),
);
const current = computed(() => props.photos?.[index.value] ?? null);

function show(position: number): void {
    index.value = position;
    open.value = true;
}

function step(direction: 1 | -1): void {
    const count = props.photos?.length ?? 0;

    if (count > 0) {
        index.value = (index.value + direction + count) % count;
    }
}
</script>

<template>
    <!-- The same shape the photos take, so nothing below jumps when they land. -->
    <div
        v-if="photos === undefined"
        class="flex flex-col gap-2"
        role="status"
        aria-label="Loading photos"
    >
        <Skeleton class="aspect-[16/9] w-full rounded-2xl" />
        <div class="grid grid-cols-4 gap-2">
            <Skeleton
                v-for="thumbnail in 4"
                :key="thumbnail"
                class="aspect-[4/3] w-full rounded-xl"
            />
        </div>
    </div>

    <div v-else-if="photos.length > 0" class="flex flex-col gap-2">
        <button
            type="button"
            class="focus-visible:ring-ring/60 overflow-hidden rounded-2xl focus-visible:ring-2 focus-visible:outline-none"
            :aria-label="`View photos of ${alt}`"
            @click="show(0)"
        >
            <img
                :src="photos[0].url"
                :alt="photos[0].description ?? alt"
                class="aspect-[16/9] w-full object-cover transition-transform duration-300 hover:scale-[1.01]"
            />
        </button>

        <div v-if="thumbnails.length > 0" class="grid grid-cols-4 gap-2">
            <button
                v-for="(photo, position) in thumbnails"
                :key="photo.url"
                type="button"
                class="focus-visible:ring-ring/60 relative overflow-hidden rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                :aria-label="`View photo ${position + 2} of ${photos.length}`"
                @click="show(position + 1)"
            >
                <img
                    :src="photo.url"
                    :alt="photo.description ?? alt"
                    loading="lazy"
                    class="aspect-[4/3] w-full object-cover"
                />
                <span
                    v-if="position === thumbnails.length - 1 && hiddenCount > 0"
                    class="bg-foreground/55 text-background absolute inset-0 flex items-center justify-center text-sm font-medium"
                >
                    +{{ hiddenCount }}
                </span>
            </button>
        </div>

        <Dialog v-model:open="open">
            <DialogContent
                class="max-w-[calc(100%-2rem)] gap-3 p-3 sm:max-w-5xl"
                @keydown.left.prevent="step(-1)"
                @keydown.right.prevent="step(1)"
            >
                <DialogTitle class="sr-only">Photos of {{ alt }}</DialogTitle>
                <DialogDescription class="sr-only">
                    Use the arrow keys to move between photos.
                </DialogDescription>

                <div class="bg-muted relative overflow-hidden rounded-xl">
                    <img
                        v-if="current"
                        :src="current.url"
                        :alt="current.description ?? alt"
                        class="max-h-[75vh] w-full object-contain"
                    />
                    <template v-if="photos.length > 1">
                        <Button
                            variant="secondary"
                            size="icon"
                            class="absolute top-1/2 left-3 -translate-y-1/2 rounded-full"
                            aria-label="Previous photo"
                            @click="step(-1)"
                        >
                            <ChevronLeft class="size-4" />
                        </Button>
                        <Button
                            variant="secondary"
                            size="icon"
                            class="absolute top-1/2 right-3 -translate-y-1/2 rounded-full"
                            aria-label="Next photo"
                            @click="step(1)"
                        >
                            <ChevronRight class="size-4" />
                        </Button>
                    </template>
                </div>

                <div
                    class="text-muted-foreground flex items-center justify-between gap-3 px-1 text-sm"
                >
                    <span class="truncate">{{ current?.description }}</span>
                    <span class="shrink-0 tabular-nums"
                        >{{ index + 1 }} / {{ photos.length }}</span
                    >
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
