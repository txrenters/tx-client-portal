import { router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import type { MaintenanceFilterValues } from '@/types';

export type UseWorkOrderFiltersReturn = {
    status: Ref<string>;
    search: Ref<string>;
    property: Ref<string>;
    isLoading: Ref<boolean>;
    isFiltered: Readonly<Ref<boolean>>;
    goToPage: (page: number) => void;
};

/**
 * Keeps the maintenance filters in the URL and asks the server for each page.
 *
 * Only one page of work orders is sent to the browser, so large portfolios
 * stay quick to load; the full list lives in the server-side cache.
 */
export function useWorkOrderFilters(
    url: string,
    initial: MaintenanceFilterValues,
): UseWorkOrderFiltersReturn {
    // "approval" is a view of its own: work orders waiting on the owner, whatever their status.
    const status = ref(
        initial.approval === 'needed' ? 'approval' : (initial.status ?? 'all'),
    );
    const search = ref(initial.search ?? '');
    const property = ref(initial.property ?? 'all');
    const isLoading = ref(false);

    function visit(page: number): void {
        router.get(
            url,
            {
                // Always sent: with no status the page falls back to "open".
                status: status.value === 'approval' ? 'all' : status.value,
                approval: status.value === 'approval' ? 'needed' : undefined,
                search: search.value.trim() || undefined,
                property: property.value === 'all' ? undefined : property.value,
                page: page > 1 ? page : undefined,
            },
            {
                only: ['maintenance', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => (isLoading.value = true),
                onFinish: () => (isLoading.value = false),
            },
        );
    }

    watch([status, property], () => visit(1));
    watchDebounced(search, () => visit(1), { debounce: 300 });

    return {
        status,
        search,
        property,
        isLoading,
        isFiltered: computed(
            () =>
                status.value !== 'all' ||
                property.value !== 'all' ||
                search.value.trim() !== '',
        ),
        goToPage: (page: number) => visit(page),
    };
}
