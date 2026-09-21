<script setup lang="ts">
import { ref, watch } from 'vue';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import type { Paginated } from '@/types';

const props = defineProps<{
    results: Paginated<unknown>;
}>();

const emit = defineEmits<{
    change: [page: number];
}>();

/**
 * The page the client picked, shown straight away instead of waiting for the
 * server; it follows the server again once the new page arrives.
 */
const selectedPage = ref(props.results.current_page);

watch(
    () => props.results.current_page,
    (page) => (selectedPage.value = page),
);

function select(page: number): void {
    selectedPage.value = page;
    emit('change', page);
}
</script>

<template>
    <div
        v-if="results.last_page > 1"
        class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between"
    >
        <p class="text-muted-foreground text-sm">
            Showing {{ results.from }}–{{ results.to }} of
            {{ results.total }}
        </p>

        <Pagination
            class="mx-0 w-auto"
            :page="selectedPage"
            :items-per-page="results.per_page"
            :total="results.total"
            :sibling-count="1"
            show-edges
            @update:page="select"
        >
            <PaginationContent v-slot="{ items }">
                <PaginationPrevious />
                <template v-for="(item, index) in items" :key="index">
                    <PaginationItem
                        v-if="item.type === 'page'"
                        :value="item.value"
                        :is-active="item.value === selectedPage"
                    >
                        {{ item.value }}
                    </PaginationItem>
                    <PaginationEllipsis v-else :index="index" />
                </template>
                <PaginationNext />
            </PaginationContent>
        </Pagination>
    </div>
</template>
