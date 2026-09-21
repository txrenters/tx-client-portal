<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Placeholder shaped like DocumentBrowser (filter bar, count, thumbnail grid),
 * so nothing shifts when the documents arrive.
 */
withDefaults(
    defineProps<{
        items?: number;
    }>(),
    {
        items: 8,
    },
);
</script>

<template>
    <div
        class="flex flex-col gap-4"
        role="status"
        aria-label="Loading documents"
    >
        <div
            class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
        >
            <Skeleton class="h-9 w-full sm:w-72" />
            <Skeleton class="h-9 w-full sm:w-48" />
            <Skeleton class="h-9 w-full sm:w-40" />
            <Skeleton class="h-9 w-full sm:w-44" />
        </div>
        <Skeleton class="h-3 w-28" />
        <ul class="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-3">
            <li v-for="item in items" :key="item">
                <Card class="h-full gap-0 overflow-hidden py-0">
                    <Skeleton class="aspect-[3/2] w-full rounded-none" />
                    <div class="flex flex-col gap-1.5 p-2">
                        <Skeleton class="h-3 w-full" />
                        <Skeleton class="h-3 w-2/3" />
                        <Skeleton class="mt-0.5 h-2.5 w-1/2" />
                    </div>
                </Card>
            </li>
        </ul>
    </div>
</template>
