<script setup lang="ts">
import { computed } from 'vue';
import { Search, SearchX } from '@lucide/vue';
import DocumentList from '@/components/DocumentList.vue';
import EmptyState from '@/components/EmptyState.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useDocumentFilters } from '@/composables/useDocumentFilters';
import type {
    DocumentFilterValues,
    DocumentKind,
    DocumentsPage,
} from '@/types';

const props = defineProps<{
    page: DocumentsPage;
    filters: DocumentFilterValues;
    url: string;
    downloadUrl: (id: string) => string;
    previewUrl: (id: string) => string;
    thumbnailUrl: (id: string) => string;
    workOrderUrl?: (id: string) => string;
}>();

/**
 * Name properties only when the documents span more than one, for owners and
 * residents alike; with a single property the address would just repeat.
 */
const hasSeveralProperties = computed(() => props.page.properties.length > 1);

/** The buttons along the top: what each document is, read from its name. */
const kinds: { value: DocumentKind | 'all'; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'leases', label: 'Leases' },
    { value: 'statements', label: 'Statements' },
    { value: 'tax', label: 'Tax documents' },
    { value: 'invoices', label: 'Invoices' },
    { value: 'other', label: 'Other' },
];

function countFor(value: DocumentKind | 'all'): number {
    return value === 'all' ? props.page.total : props.page.kindCounts[value];
}

const {
    search,
    category,
    kind,
    dateRange,
    dateFrom,
    dateTo,
    property,
    isLoading,
    isFiltered,
    clearFilters,
    goToPage,
} = useDocumentFilters(props.url, props.filters);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            class="flex flex-wrap gap-2"
            role="group"
            aria-label="Filter by kind of document"
        >
            <Button
                v-for="item in kinds"
                :key="item.value"
                size="sm"
                :variant="kind === item.value ? 'default' : 'outline'"
                :aria-pressed="kind === item.value"
                @click="kind = item.value"
            >
                {{ item.label }}
                <span
                    class="text-xs tabular-nums"
                    :class="
                        kind === item.value
                            ? 'opacity-80'
                            : 'text-muted-foreground'
                    "
                    >{{ countFor(item.value) }}</span
                >
            </Button>
        </div>
        <div
            class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
        >
            <div class="relative w-full sm:w-72">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="pl-9"
                    placeholder="Search documents"
                    aria-label="Search documents"
                />
            </div>
            <Select v-model="category">
                <SelectTrigger
                    class="w-full sm:w-48"
                    aria-label="Filter by category"
                    ><SelectValue placeholder="All categories"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All categories</SelectItem>
                    <SelectItem
                        v-for="item in page.categories"
                        :key="item"
                        :value="item"
                        >{{ item }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="dateRange">
                <SelectTrigger
                    class="w-full sm:w-44"
                    aria-label="Filter by date added"
                    ><SelectValue placeholder="Any date"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Any date</SelectItem>
                    <SelectItem value="30">Last 30 days</SelectItem>
                    <SelectItem value="90">Last 3 months</SelectItem>
                    <SelectItem value="365">Last 12 months</SelectItem>
                    <SelectItem value="year">This year</SelectItem>
                    <SelectItem value="custom">Custom range…</SelectItem>
                </SelectContent>
            </Select>
            <div
                v-if="dateRange === 'custom'"
                class="flex w-full items-center gap-2 sm:w-auto"
            >
                <Input
                    v-model="dateFrom"
                    type="date"
                    class="flex-1 sm:w-40"
                    :max="dateTo || undefined"
                    aria-label="Added on or after"
                />
                <span class="text-muted-foreground text-sm">to</span>
                <Input
                    v-model="dateTo"
                    type="date"
                    class="flex-1 sm:w-40"
                    :min="dateFrom || undefined"
                    aria-label="Added on or before"
                />
            </div>
            <Select v-if="hasSeveralProperties" v-model="property">
                <SelectTrigger
                    class="w-full sm:w-56"
                    aria-label="Filter by property"
                    ><SelectValue placeholder="All properties"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All properties</SelectItem>
                    <SelectItem
                        v-for="item in page.properties"
                        :key="item.id"
                        :value="String(item.id)"
                        >{{ item.name }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Button v-if="isFiltered" variant="ghost" @click="clearFilters"
                >Clear filters</Button
            >
        </div>
        <p class="text-muted-foreground text-xs" role="status">
            {{ page.results.total }} of {{ page.total }} documents
        </p>
        <EmptyState
            v-if="page.results.total === 0"
            :icon="SearchX"
            title="No matching documents"
            description="Try another search or clear your filters."
        />
        <div
            v-else
            class="flex flex-col gap-4 transition-opacity"
            :class="{ 'pointer-events-none opacity-60': isLoading }"
            :aria-busy="isLoading"
        >
            <DocumentList
                thumbnails
                :documents="page.results.data"
                :download-url="downloadUrl"
                :preview-url="previewUrl"
                :thumbnail-url="thumbnailUrl"
                :work-order-url="workOrderUrl"
                :show-property="hasSeveralProperties"
            />
            <ResultsPagination :results="page.results" @change="goToPage" />
        </div>
    </div>
</template>
