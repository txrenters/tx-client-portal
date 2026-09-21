<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle2,
    ChevronDown,
    DoorOpen,
    FolderOpen,
    Search,
} from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import PropertyCard from '@/components/PropertyCard.vue';
import DashboardMetrics from '@/components/DashboardMetrics.vue';
import type { Metric } from '@/components/DashboardMetrics.vue';
import { dashboard, portfolio as portfolioRoute } from '@/routes/owner';
import { show } from '@/routes/owner/properties';
import type { PortalProperty } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Portfolio', href: portfolioRoute() },
        ],
    },
});

import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    portfolio?: {
        portfolios: {
            id: number;
            name: string;
            properties: PortalProperty[];
        }[];
        properties: PortalProperty[];
        occupancy: { total: number; occupied: number; vacant: number };
    } | null;
}>();

const search = ref('');
const filteredPortfolios = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    return (props.portfolio?.portfolios ?? []).filter(
        (item) =>
            item.name.toLocaleLowerCase().includes(query) ||
            item.properties.some((property) =>
                property.fullAddress.toLocaleLowerCase().includes(query),
            ),
    );
});

const metrics = computed<Metric[]>(() => [
    {
        label: 'Portfolios',
        value: props.portfolio?.portfolios.length ?? null,
        icon: FolderOpen,
        caption: 'Attached to this account',
    },
    {
        label: 'Buildings',
        value: props.portfolio?.occupancy.total ?? null,
        icon: Building2,
        caption: 'Across your portfolios',
    },
    {
        label: 'Occupied',
        value: props.portfolio?.occupancy.occupied ?? null,
        icon: CheckCircle2,
        caption: 'Currently leased',
    },
    {
        label: 'Vacant',
        value: props.portfolio?.occupancy.vacant ?? null,
        icon: DoorOpen,
        caption: 'Available to rent',
    },
]);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6 p-4 md:p-6">
        <Head title="Portfolios" />
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Portfolios</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                Portfolios attached to the selected account. Expand one to view
                its buildings.
            </p>
        </div>

        <LoadingSkeleton v-if="portfolio === undefined" :rows="2" />

        <ErrorState
            v-else-if="portfolio === null"
            description="We couldn't load your portfolio right now."
        />

        <template v-else>
            <DashboardMetrics :metrics="metrics" />

            <div class="relative w-full sm:w-72">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="pl-9"
                    placeholder="Search portfolios or addresses"
                    aria-label="Search portfolios or building addresses"
                />
            </div>
            <p class="text-muted-foreground text-sm" aria-live="polite">
                {{ filteredPortfolios.length }} of
                {{ portfolio.portfolios.length }} portfolios
            </p>
            <EmptyState
                v-if="portfolio.portfolios.length === 0"
                :icon="FolderOpen"
                title="No portfolios linked"
                description="Contact TexasRenters.com if portfolios should appear for this account."
            />
            <EmptyState
                v-else-if="filteredPortfolios.length === 0"
                :icon="FolderOpen"
                title="No matching portfolios"
                description="Try another portfolio name or building address."
            />
            <div v-else class="flex flex-col gap-3">
                <Card
                    v-for="item in filteredPortfolios"
                    :key="item.id"
                    class="min-w-0"
                >
                    <Collapsible class="group/portfolio">
                        <CollapsibleTrigger as-child>
                            <Button
                                variant="ghost"
                                class="h-auto w-full justify-start gap-3 rounded-none px-4 py-2 text-left whitespace-normal"
                            >
                                <FolderOpen
                                    class="text-primary size-5 shrink-0"
                                />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block font-semibold wrap-anywhere"
                                        >{{ item.name }}</span
                                    >
                                    <span
                                        class="text-muted-foreground block text-xs"
                                        >{{ item.properties.length }}
                                        {{
                                            item.properties.length === 1
                                                ? 'building'
                                                : 'buildings'
                                        }}</span
                                    >
                                </span>
                                <ChevronDown
                                    class="size-4 shrink-0 transition-transform group-data-[state=open]/portfolio:rotate-180 motion-reduce:transition-none"
                                />
                            </Button>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <CardContent class="pt-4">
                                <p
                                    v-if="item.properties.length === 0"
                                    class="text-muted-foreground text-sm"
                                >
                                    No buildings are currently available in this
                                    portfolio.
                                </p>
                                <div v-else class="grid gap-3 md:grid-cols-2">
                                    <PropertyCard
                                        v-for="property in item.properties"
                                        :key="property.id"
                                        :property="property"
                                        :href="show(property.id)"
                                    />
                                </div>
                            </CardContent>
                        </CollapsibleContent>
                    </Collapsible>
                </Card>
            </div>
        </template>
    </div>
</template>
