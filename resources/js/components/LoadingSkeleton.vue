<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

withDefaults(
    defineProps<{
        rows?: number;
    }>(),
    {
        rows: 3,
    },
);
</script>

<template>
    <div class="flex flex-col gap-3" role="status" aria-label="Loading">
        <div
            v-for="row in rows"
            :key="row"
            class="border-border flex flex-col gap-2 rounded-xl border p-4"
        >
            <div class="flex items-center justify-between gap-3">
                <Skeleton class="h-4 w-1/2" />
                <Skeleton class="h-5 w-20 rounded-full" />
            </div>
            <Skeleton class="h-3 w-2/3" />
            <Skeleton class="h-3 w-1/3" />
        </div>
    </div>
</template>
