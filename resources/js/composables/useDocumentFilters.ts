import { router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { presetDates } from '@/lib/dateRange';
import type { DocumentFilterValues } from '@/types';

export type UseDocumentFiltersReturn = {
    search: Ref<string>;
    category: Ref<string>;
    fileType: Ref<string>;
    dateRange: Ref<string>;
    dateFrom: Ref<string>;
    dateTo: Ref<string>;
    property: Ref<string>;
    workOrder: Ref<string>;
    isLoading: Ref<boolean>;
    isFiltered: Readonly<Ref<boolean>>;
    clearFilters: () => void;
    goToPage: (page: number) => void;
};

/**
 * Keeps the document filters in the URL and asks the server for each page.
 *
 * Only one page of documents reaches the browser, however many the client has.
 */
export function useDocumentFilters(
    url: string,
    initial: DocumentFilterValues,
): UseDocumentFiltersReturn {
    const search = ref(initial.search ?? '');
    const category = ref(initial.category ?? 'all');
    const fileType = ref<string>(initial.type ?? 'all');
    const dateRange = ref<string>(initial.range ?? 'all');
    const dateFrom = ref(
        initial.range === 'custom' ? (initial.from ?? '') : '',
    );
    const dateTo = ref(initial.range === 'custom' ? (initial.to ?? '') : '');
    const property = ref(initial.property ? String(initial.property) : 'all');
    const workOrder = ref(initial.work_order ?? 'all');
    const isLoading = ref(false);

    function visit(page: number): void {
        const dates = presetDates(
            dateRange.value,
            dateFrom.value,
            dateTo.value,
        );

        router.get(
            url,
            {
                search: search.value.trim() || undefined,
                category: category.value === 'all' ? undefined : category.value,
                type: fileType.value === 'all' ? undefined : fileType.value,
                range: dateRange.value === 'all' ? undefined : dateRange.value,
                from: dates.from,
                to: dates.to,
                property: property.value === 'all' ? undefined : property.value,
                work_order:
                    workOrder.value === 'all' ? undefined : workOrder.value,
                page: page > 1 ? page : undefined,
            },
            {
                only: ['documents', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => (isLoading.value = true),
                onFinish: () => (isLoading.value = false),
            },
        );
    }

    watch(
        [category, fileType, dateRange, dateFrom, dateTo, property, workOrder],
        () => visit(1),
    );
    watchDebounced(search, () => visit(1), { debounce: 300 });

    return {
        search,
        category,
        fileType,
        dateRange,
        dateFrom,
        dateTo,
        property,
        workOrder,
        isLoading,
        isFiltered: computed(
            () =>
                search.value.trim() !== '' ||
                category.value !== 'all' ||
                fileType.value !== 'all' ||
                dateRange.value !== 'all' ||
                property.value !== 'all' ||
                workOrder.value !== 'all',
        ),
        clearFilters(): void {
            search.value = '';
            category.value = 'all';
            fileType.value = 'all';
            dateRange.value = 'all';
            dateFrom.value = '';
            dateTo.value = '';
            property.value = 'all';
            workOrder.value = 'all';
        },
        goToPage: (page: number) => visit(page),
    };
}
