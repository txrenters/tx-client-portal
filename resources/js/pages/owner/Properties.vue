<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2, Search, SearchX } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PropertyCard from '@/components/PropertyCard.vue';
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
import { Skeleton } from '@/components/ui/skeleton';
import { useServerFilters } from '@/composables/useServerFilters';
import { dashboard } from '@/routes/owner';
import { index as propertiesRoute, show } from '@/routes/owner/properties';
import type { PropertiesPage, PropertyFilterValues } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Properties', href: propertiesRoute() },
        ],
    },
});

const props = defineProps<{
    filters: PropertyFilterValues;
    properties?: PropertiesPage | null;
}>();

const { filters, isLoading, isFiltered, clearFilters, goToPage } =
    useServerFilters({
        url: propertiesRoute.url(),
        only: ['properties', 'filters'],
        initial: props.filters,
        searchKey: 'search',
    });
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Properties" />
        <PageHeader
            title="Properties"
            description="Every property TexasRenters.com manages for you, with occupancy at a glance."
        />

        <div
            v-if="properties === undefined"
            class="flex flex-col gap-4"
            role="status"
            aria-label="Loading properties"
        >
            <div class="flex flex-col gap-2 sm:flex-row">
                <Skeleton class="h-9 w-full sm:w-72" />
                <Skeleton class="h-9 w-full sm:w-44" />
                <Skeleton class="h-9 w-full sm:w-40" />
            </div>
            <Skeleton class="h-3 w-28" />
            <div class="grid gap-3 md:grid-cols-2">
                <Skeleton
                    v-for="item in 6"
                    :key="item"
                    class="h-28 w-full rounded-xl"
                />
            </div>
        </div>

        <ErrorState
            v-else-if="properties === null"
            description="We couldn't load your properties right now."
        />

        <EmptyState
            v-else-if="properties.total === 0"
            :icon="Building2"
            title="No properties yet"
            description="Contact TexasRenters.com if your properties should appear here."
        />

        <div v-else class="flex flex-col gap-4">
            <div
                class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
            >
                <div class="relative w-full sm:w-72">
                    <Search
                        class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="filters.search"
                        type="search"
                        class="pl-9"
                        placeholder="Search address, ZIP or area"
                        aria-label="Search properties"
                    />
                </div>
                <Select v-model="filters.occupancy">
                    <SelectTrigger
                        class="w-full sm:w-44"
                        aria-label="Filter by occupancy"
                        ><SelectValue placeholder="Any occupancy"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any occupancy</SelectItem>
                        <SelectItem value="occupied"
                            >Occupied ({{
                                properties.counts.occupied
                            }})</SelectItem
                        >
                        <SelectItem value="vacant"
                            >Vacant ({{ properties.counts.vacant }})</SelectItem
                        >
                    </SelectContent>
                </Select>
                <Select
                    v-if="properties.cities.length > 1"
                    v-model="filters.city"
                >
                    <SelectTrigger
                        class="w-full sm:w-44"
                        aria-label="Filter by city"
                        ><SelectValue placeholder="All cities"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All cities</SelectItem>
                        <SelectItem
                            v-for="city in properties.cities"
                            :key="city"
                            :value="city"
                            >{{ city }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <Select v-model="filters.bedrooms">
                    <SelectTrigger
                        class="w-full sm:w-40"
                        aria-label="Filter by bedrooms"
                        ><SelectValue placeholder="Any bedrooms"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Any bedrooms</SelectItem>
                        <SelectItem value="1">1 bedroom</SelectItem>
                        <SelectItem value="2">2 bedrooms</SelectItem>
                        <SelectItem value="3">3 bedrooms</SelectItem>
                        <SelectItem value="4">4+ bedrooms</SelectItem>
                    </SelectContent>
                </Select>
                <Button v-if="isFiltered" variant="ghost" @click="clearFilters"
                    >Clear filters</Button
                >
            </div>

            <p class="text-muted-foreground text-xs" role="status">
                {{ properties.results.total }} of {{ properties.total }}
                properties
            </p>

            <EmptyState
                v-if="properties.results.total === 0"
                :icon="SearchX"
                title="No matching properties"
                description="Try another search or clear your filters."
            />

            <div
                v-else
                class="flex flex-col gap-4 transition-opacity"
                :class="{ 'pointer-events-none opacity-60': isLoading }"
                :aria-busy="isLoading"
            >
                <div class="grid gap-3 md:grid-cols-2">
                    <PropertyCard
                        v-for="item in properties.results.data"
                        :key="item.id"
                        :property="item"
                        :href="show(item.id)"
                    />
                </div>
                <ResultsPagination
                    :results="properties.results"
                    @change="goToPage"
                />
            </div>
        </div>
    </div>
</template>
