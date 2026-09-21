<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Placeholder shaped like the maintenance list, optionally with its filters,
 * so nothing shifts when the work orders arrive.
 */
withDefaults(
    defineProps<{
        rows?: number;
        withFilters?: boolean;
    }>(),
    {
        rows: 6,
        withFilters: false,
    },
);
</script>

<template>
    <div
        class="flex flex-col gap-5"
        role="status"
        aria-label="Loading work orders"
    >
        <div v-if="withFilters" class="flex flex-col gap-3">
            <div class="flex flex-col gap-2 sm:flex-row">
                <Skeleton class="h-9 w-full sm:w-72" />
                <Skeleton class="h-9 w-full sm:w-56" />
            </div>
            <Skeleton class="h-9 w-full max-w-md rounded-lg" />
        </div>

        <ul
            class="bg-card divide-border divide-y overflow-hidden rounded-xl border"
        >
            <li
                v-for="row in rows"
                :key="row"
                class="flex items-start gap-4 px-4 py-3"
            >
                <div class="flex min-w-0 flex-1 flex-col gap-2">
                    <Skeleton
                        class="h-4"
                        :class="row % 3 === 0 ? 'w-1/2' : 'w-2/3'"
                    />
                    <Skeleton class="h-3 w-2/5" />
                </div>
                <Skeleton class="mt-0.5 h-3 w-16" />
            </li>
        </ul>
    </div>
</template>
