import { router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import type { Ref } from 'vue';
import { computed, reactive, ref, watch } from 'vue';

type FilterValue = string | number | null | undefined;

export type UseServerFiltersReturn<K extends string> = {
    filters: Record<K, string>;
    isLoading: Ref<boolean>;
    isFiltered: Readonly<Ref<boolean>>;
    clearFilters: () => void;
    goToPage: (page: number) => void;
};

/**
 * Keeps a list page's filters in the URL and asks the server for each page.
 *
 * Dropdowns use "all" for "no filter"; the search box waits for typing to
 * pause. Only the given props are reloaded and the scroll position is kept.
 */
export function useServerFilters<K extends string>(options: {
    url: string;
    only: string[];
    initial: Record<K, FilterValue>;
    searchKey?: NoInfer<K>;
}): UseServerFiltersReturn<K> {
    const keys = Object.keys(options.initial) as K[];
    const emptyValue = (key: K): string =>
        key === options.searchKey ? '' : 'all';

    const filters = reactive(
        Object.fromEntries(
            keys.map((key) => {
                const value = options.initial[key];

                return [
                    key,
                    value === null || value === undefined || value === ''
                        ? emptyValue(key)
                        : String(value),
                ];
            }),
        ),
    ) as Record<K, string>;

    const isLoading = ref(false);

    function visit(page: number): void {
        const query: Record<string, string | number | undefined> = {};

        for (const key of keys) {
            const value = filters[key].trim();
            query[key] =
                value === '' || value === emptyValue(key) ? undefined : value;
        }

        query.page = page > 1 ? page : undefined;

        router.get(options.url, query, {
            only: options.only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        });
    }

    const nonSearchKeys = keys.filter((key) => key !== options.searchKey);

    watch(
        () => nonSearchKeys.map((key) => filters[key]),
        () => visit(1),
    );

    if (options.searchKey) {
        const searchKey = options.searchKey;

        watchDebounced(
            () => filters[searchKey],
            () => visit(1),
            { debounce: 300 },
        );
    }

    return {
        filters,
        isLoading,
        isFiltered: computed(() =>
            keys.some((key) => filters[key].trim() !== emptyValue(key)),
        ),
        clearFilters(): void {
            for (const key of keys) {
                filters[key] = emptyValue(key);
            }
        },
        goToPage: (page: number) => visit(page),
    };
}
