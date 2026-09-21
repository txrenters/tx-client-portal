<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Building2,
    CheckCircle2,
    Clock,
    RefreshCw,
    TrendingUp,
    Wrench,
} from '@lucide/vue';
import DashboardHeader from '@/components/DashboardHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import WorkOrderLoadStatus from '@/components/WorkOrderLoadStatus.vue';
import ErrorState from '@/components/ErrorState.vue';
import MaintenanceList from '@/components/MaintenanceList.vue';
import MaintenanceListSkeleton from '@/components/MaintenanceListSkeleton.vue';
import OccupancyDonut from '@/components/OccupancyDonut.vue';
import AppCard from '@/components/AppCard.vue';
import DashboardFeeds from '@/components/DashboardFeeds.vue';
import DashboardMetrics from '@/components/DashboardMetrics.vue';
import type { Metric } from '@/components/DashboardMetrics.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { computed } from 'vue';
import {
    dashboard,
    documents,
    financials,
    portfolio as portfolioRoute,
} from '@/routes/owner';
import {
    index as maintenanceRoute,
    show as maintenanceShow,
} from '@/routes/owner/maintenance';
import { download, preview } from '@/routes/owner/documents';
import { index as messages } from '@/routes/owner/messages';
import {
    index as properties,
    show as propertyShow,
} from '@/routes/owner/properties';

import type {
    ApprovalSummary,
    DashboardActivityEntry,
    DashboardDocuments,
    DashboardMessage,
    MaintenanceSummary,
    PortalProperty,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Home', href: dashboard() }],
    },
});

const props = defineProps<{
    portfolio?: {
        portfolioCount: number;
        propertyCount: number;
        occupancy: { total: number; occupied: number; vacant: number };
        properties: PortalProperty[];
    } | null;
    maintenance?: MaintenanceSummary;
    /** Null when it couldn't be loaded; the section is hidden then. */
    approvals?: ApprovalSummary | null;
    financials?: {
        expenses: number;
        paid: number;
        outstanding: number;
        bills: number;
    } | null;
    messages?: DashboardMessage[] | null;
    documents?: DashboardDocuments | null;
    activity?: DashboardActivityEntry[] | null;
}>();

/** Approvals sit beside the portfolio summary; without them it takes the row. */
const needsApproval = computed(() => (props.approvals?.count ?? 0) > 0);

const metrics = computed<Metric[]>(() => [
    {
        label: 'Buildings',
        value: props.portfolio?.propertyCount ?? null,
        loading: props.portfolio === undefined,
        icon: Building2,
        caption: 'In your portfolio',
    },
    {
        label: 'Open work orders',
        value: props.maintenance?.counts.open ?? null,
        loading: props.maintenance === undefined,
        icon: Wrench,
        caption: 'Being worked on',
    },
    {
        label: 'Scheduled',
        value: props.maintenance?.counts.scheduled ?? null,
        loading: props.maintenance === undefined,
        icon: Clock,
        caption: 'Visits booked',
    },
    {
        label: 'Completed',
        value: props.maintenance?.counts.completed ?? null,
        loading: props.maintenance === undefined,
        icon: TrendingUp,
        caption: 'All time',
    },
]);

const refreshing = ref(false);
function refreshDashboard(): void {
    refreshing.value = true;
    router.reload({
        only: ['portfolio', 'maintenance', 'approvals'],
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6 p-4 md:p-6">
        <Head title="Home" />

        <DashboardHeader
            portal-label="Owner portal"
            description="Here's how your portfolio is doing with TexasRenters.com."
        >
            <template #actions>
                <Button
                    variant="outline"
                    size="xs"
                    class="h-5 cursor-pointer gap-1.5 rounded-full px-2 py-0 text-xs"
                    :disabled="refreshing"
                    @click="refreshDashboard"
                >
                    <RefreshCw
                        class="size-3"
                        :class="{ 'motion-safe:animate-spin': refreshing }"
                        aria-hidden="true"
                    />
                    {{ refreshing ? 'Refreshing…' : 'Refresh dashboard' }}
                </Button>
            </template>
        </DashboardHeader>

        <DashboardMetrics :metrics="metrics" />

        <div class="grid gap-4 lg:grid-cols-3">
            <AppCard
                title="Portfolio summary"
                :href="portfolioRoute()"
                link-label="View portfolio"
                :class="approvals === null ? 'lg:col-span-3' : 'lg:col-span-1'"
            >
                <div v-if="portfolio === undefined" class="flex flex-col gap-4">
                    <Skeleton class="mx-auto size-44 rounded-full" />
                    <Skeleton class="mx-auto h-4 w-48" />
                </div>

                <div
                    v-else-if="portfolio"
                    class="flex min-w-0 flex-col gap-3 wrap-anywhere"
                >
                    <OccupancyDonut
                        v-if="portfolio.occupancy.total > 0"
                        :occupancy="portfolio.occupancy"
                    />
                    <p class="text-muted-foreground text-sm">
                        {{ portfolio.propertyCount }}
                        {{
                            portfolio.propertyCount === 1
                                ? 'building'
                                : 'buildings'
                        }}
                        across
                        {{ portfolio.portfolioCount }}
                        {{
                            portfolio.portfolioCount === 1
                                ? 'portfolio'
                                : 'portfolios'
                        }}
                    </p>
                </div>

                <ErrorState
                    v-else
                    description="We couldn't load your portfolio right now."
                />
            </AppCard>

            <AppCard
                v-if="approvals !== null"
                :title="
                    needsApproval
                        ? `Needs your approval (${approvals!.count})`
                        : 'Needs your approval'
                "
                description="These work orders are waiting on you before work can go ahead."
                :href="maintenanceRoute({ query: { approval: 'needed' } })"
                link-label="View all"
                class="lg:col-span-2"
            >
                <MaintenanceListSkeleton
                    v-if="approvals === undefined"
                    :rows="3"
                />

                <MaintenanceList
                    v-else-if="needsApproval"
                    :work-orders="approvals.items"
                    :href="maintenanceShow"
                    :show-property="true"
                />

                <EmptyState
                    v-else
                    :icon="CheckCircle2"
                    title="Nothing waiting on you"
                    description="Work orders that need your go-ahead will appear here."
                />
            </AppCard>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard
                title="Properties"
                :href="properties()"
                link-label="View properties"
            >
                <ul
                    v-if="portfolio === undefined"
                    class="flex flex-col gap-2"
                    role="status"
                    aria-label="Loading properties"
                >
                    <li
                        v-for="row in 6"
                        :key="row"
                        class="border-border/60 flex flex-col gap-2 rounded-xl border p-3"
                    >
                        <Skeleton class="h-4 w-2/3" />
                        <Skeleton class="h-3 w-1/3" />
                    </li>
                </ul>

                <ul
                    v-else-if="portfolio && portfolio.properties.length > 0"
                    class="flex flex-col gap-2"
                >
                    <li v-for="item in portfolio.properties" :key="item.id">
                        <Link
                            :href="propertyShow(item.id)"
                            class="border-border/60 bg-card/40 hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring block rounded-xl border p-3 wrap-anywhere transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <p class="font-medium">{{ item.address }}</p>
                            <p class="text-muted-foreground text-sm">
                                {{
                                    [item.city, item.state]
                                        .filter(Boolean)
                                        .join(', ')
                                }}
                            </p>
                        </Link>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    :icon="Building2"
                    title="No properties linked yet"
                    description="Contact TexasRenters.com if your properties should appear here."
                />
            </AppCard>

            <AppCard
                title="Maintenance"
                :href="maintenanceRoute()"
                link-label="View work orders"
            >
                <WorkOrderLoadStatus
                    :status="maintenance?.status"
                    :empty="maintenance?.recent.length === 0"
                    :loading-rows="6"
                    class="transition-opacity"
                    :class="{ 'opacity-60': refreshing }"
                    :aria-busy="refreshing"
                >
                    <div v-if="maintenance" class="flex flex-col gap-3">
                        <p class="text-muted-foreground text-sm">
                            {{ maintenance.counts.open ?? 0 }} open ·
                            {{ maintenance.counts.scheduled ?? 0 }} scheduled ·
                            {{ maintenance.counts.completed ?? 0 }} completed
                        </p>
                        <MaintenanceList
                            :work-orders="maintenance.recent"
                            :href="maintenanceShow"
                            :show-property="true"
                        />
                    </div>
                </WorkOrderLoadStatus>
            </AppCard>
        </div>

        <DashboardFeeds
            :messages-href="messages().url"
            :documents-href="documents().url"
            :financials-href="financials().url"
            :financials="props.financials"
            :preview-href="(id) => preview.url(id)"
            :download-href="(id) => download.url(id)"
            :thread-href="(id) => messages({ query: { thread: id } }).url"
            :messages="props.messages"
            :documents="props.documents"
            :activity="activity"
        />
    </div>
</template>
