<script setup lang="ts">
import { CheckCircle2, RefreshCw, TriangleAlert } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import MaintenanceListSkeleton from '@/components/MaintenanceListSkeleton.vue';

withDefaults(
    defineProps<{
        status?: 'ready' | 'error';
        refreshing?: boolean;
        empty?: boolean;
        /** Rows in the loading placeholder. */
        loadingRows?: number;
        /** Also show the filter bar in the loading placeholder. */
        withFilters?: boolean;
    }>(),
    {
        refreshing: false,
        empty: false,
        loadingRows: 6,
        withFilters: false,
    },
);
</script>

<template>
    <div
        role="status"
        aria-live="polite"
        aria-atomic="true"
        class="flex flex-col gap-5"
    >
        <EmptyState
            v-if="refreshing"
            :icon="RefreshCw"
            title="Refreshing work orders…"
            description="Getting the latest work orders for the selected account."
            spin
        />
        <MaintenanceListSkeleton
            v-else-if="status === undefined"
            :rows="loadingRows"
            :with-filters="withFilters"
        />
        <EmptyState
            v-else-if="status === 'error'"
            :icon="TriangleAlert"
            title="Failed to load work orders"
            description="Propertyware data is unavailable right now. Please try again in a moment."
        />
        <EmptyState
            v-else-if="empty"
            :icon="CheckCircle2"
            title="Loaded — no work orders"
            description="There are no available work orders for the selected account."
        />
        <slot v-else />
    </div>
</template>
