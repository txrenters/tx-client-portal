<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { SearchX } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import WorkOrderLoadStatus from '@/components/WorkOrderLoadStatus.vue';
import MaintenanceList from '@/components/MaintenanceList.vue';
import MaintenanceFilters from '@/components/MaintenanceFilters.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import PageHeader from '@/components/PageHeader.vue';
import WorkOrderRequestDialog from '@/components/WorkOrderRequestDialog.vue';
import { useWorkOrderFilters } from '@/composables/useWorkOrderFilters';
import { dashboard } from '@/routes/resident';
import {
    index as maintenanceRoute,
    show as maintenanceShow,
    store,
} from '@/routes/resident/maintenance';
import type {
    MaintenanceFilterValues,
    MaintenancePage,
    WorkOrderRequestOptions,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Work Orders', href: maintenanceRoute() },
        ],
    },
});

const props = defineProps<{
    filters: MaintenanceFilterValues;
    maintenance?: MaintenancePage;
    requestOptions?: WorkOrderRequestOptions | null;
}>();

const { status, search, property, isLoading, isFiltered, goToPage } =
    useWorkOrderFilters(maintenanceRoute.url(), props.filters);

const results = computed(() => props.maintenance?.workOrders?.data ?? []);
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Work Orders" />
        <PageHeader
            title="Work Orders"
            description="Repairs and work happening at your home."
        >
            <template #actions>
                <WorkOrderRequestDialog
                    :action="store()"
                    :options="requestOptions"
                />
            </template>
        </PageHeader>

        <WorkOrderLoadStatus
            :status="maintenance?.status"
            :empty="maintenance?.total === 0"
            with-filters
        >
            <MaintenanceFilters
                v-model:status="status"
                v-model:search="search"
                v-model:property="property"
                :counts="maintenance?.counts ?? {}"
                :total="maintenance?.total ?? 0"
            />

            <EmptyState
                v-if="results.length === 0 && isFiltered"
                :icon="SearchX"
                title="No matching work orders"
                description="Try a different search or filter."
            />

            <div
                v-else
                class="flex flex-col gap-4 transition-opacity"
                :class="{ 'pointer-events-none opacity-60': isLoading }"
                :aria-busy="isLoading"
            >
                <MaintenanceList
                    :work-orders="results"
                    :href="maintenanceShow"
                    :show-property="false"
                />

                <ResultsPagination
                    v-if="maintenance?.workOrders"
                    :results="maintenance.workOrders"
                    @change="goToPage"
                />
            </div>
        </WorkOrderLoadStatus>
    </div>
</template>
