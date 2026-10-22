<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Banknote,
    Building2,
    Receipt,
    Clock,
    FileText,
    MessageSquare,
    RefreshCw,
    ScrollText,
    Wrench,
} from '@lucide/vue';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import DashboardHeader from '@/components/DashboardHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import WorkOrderLoadStatus from '@/components/WorkOrderLoadStatus.vue';
import MaintenanceList from '@/components/MaintenanceList.vue';
import AppCard from '@/components/AppCard.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import MonthlyMoneyChart from '@/components/MonthlyMoneyChart.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { ChartConfig } from '@/components/ui/chart';
import DashboardFeeds from '@/components/DashboardFeeds.vue';
import DashboardMetrics from '@/components/DashboardMetrics.vue';
import type { Metric } from '@/components/DashboardMetrics.vue';
import { computed } from 'vue';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { dashboard, documents, lease as leaseRoute } from '@/routes/resident';
import {
    index as maintenanceRoute,
    show as maintenanceShow,
} from '@/routes/resident/maintenance';
import { download, preview } from '@/routes/resident/documents';
import { index as messages } from '@/routes/resident/messages';

import type {
    DashboardActivityEntry,
    DashboardDocuments,
    DashboardMessage,
    LeaseLedger,
    MaintenanceSummary,
    PortalProperty,
    ResidentLease,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Home', href: dashboard() }],
    },
});

/** A tenant's chart: what was billed against what they paid. */
const paymentSeries = {
    charged: { label: 'Charged', color: 'var(--chart-expense)' },
    paid: { label: 'Paid', color: 'var(--chart-income)' },
} satisfies ChartConfig;

const props = defineProps<{
    property?: PortalProperty | null;
    /** Undefined while loading, null when it couldn't be read. */
    lease?: ResidentLease | null;
    maintenance?: MaintenanceSummary;
    ledger?: LeaseLedger | null;
    messages?: DashboardMessage[] | null;
    documents?: DashboardDocuments | null;
    activity?: DashboardActivityEntry[] | null;
}>();

const metrics = computed<Metric[]>(() => [
    {
        label: 'Open work orders',
        value: props.maintenance?.counts.open ?? null,
        loading: props.maintenance === undefined,
        icon: Wrench,
        caption: 'We are on it',
    },
    {
        label: 'Rent',
        value:
            props.lease?.rent === null || props.lease?.rent === undefined
                ? null
                : money.format(props.lease.rent),
        loading: props.lease === undefined,
        icon: Banknote,
        caption: 'Each month',
    },
    {
        label: 'Lease',
        value: props.lease?.name ?? null,
        loading: props.lease === undefined,
        icon: ScrollText,
        caption: props.lease?.status ?? null,
    },
    {
        label: 'Your home',
        value: props.property?.unitName ?? props.property?.address ?? null,
        loading: props.property === undefined,
        icon: Building2,
        caption: props.property?.city ?? null,
    },
]);

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

const exact = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

const { formatDate } = useFormattedDate();

const refreshing = ref(false);
function refreshDashboard(): void {
    refreshing.value = true;
    router.reload({
        only: ['property', 'maintenance'],
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
            portal-label="Resident portal"
            description="Here's everything about your home with TexasRenters.com."
        >
            <template #actions>
                <Button
                    variant="outline"
                    size="xs"
                    class="border-scrim-foreground/30 bg-scrim-foreground/10 text-scrim-foreground hover:bg-scrim-foreground/20 hover:text-scrim-foreground h-5 cursor-pointer gap-1.5 rounded-full px-2 py-0 text-xs"
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

        <AppCard
            title="Your payments"
            description="Charges and payments for the last 12 months."
            :href="leaseRoute()"
            link-label="View lease"
        >
            <!-- Shaped like the totals and chart it stands in for. -->
            <div v-if="ledger === undefined" class="flex flex-col gap-4">
                <div
                    class="border-border grid grid-cols-3 gap-px overflow-hidden rounded-lg border sm:max-w-lg"
                >
                    <div
                        v-for="tile in 3"
                        :key="tile"
                        class="bg-card flex flex-col gap-2 px-4 py-3"
                    >
                        <Skeleton class="h-3 w-16" />
                        <Skeleton class="h-6 w-24" />
                    </div>
                </div>
                <Skeleton class="h-72 w-full" />
            </div>

            <ErrorState
                v-else-if="ledger === null"
                description="We couldn't load your charges and payments right now."
            />

            <MonthlyMoneyChart
                v-else-if="ledger.entries.length > 0"
                :months="ledger.months"
                :series="paymentSeries"
            />

            <EmptyState
                v-else
                :icon="Receipt"
                title="Nothing yet"
                description="Rent charges and payments will appear here as they happen."
            />
        </AppCard>

        <!-- Messages and activity first, then maintenance beside what they have paid. -->
        <DashboardFeeds
            :messages-href="messages().url"
            :documents-href="documents().url"
            :preview-href="(id) => preview.url(id)"
            :download-href="(id) => download.url(id)"
            :thread-href="(id) => messages({ query: { thread: id } }).url"
            :messages="props.messages"
            :documents="props.documents"
            :activity="activity"
            :show-documents="false"
        />

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard
                title="Work Orders"
                :href="maintenanceRoute()"
                link-label="View work orders"
            >
                <WorkOrderLoadStatus
                    :status="maintenance?.status"
                    :empty="maintenance?.recent.length === 0"
                    :loading-rows="3"
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
                            :show-property="false"
                        />
                    </div>
                </WorkOrderLoadStatus>
            </AppCard>

            <AppCard
                title="Payments"
                :href="leaseRoute()"
                link-label="View lease"
            >
                <LoadingSkeleton v-if="ledger === undefined" :rows="3" />

                <ErrorState
                    v-else-if="ledger === null"
                    description="We couldn't load your charges and payments right now."
                />

                <EmptyState
                    v-else-if="ledger.entries.length === 0"
                    :icon="Receipt"
                    title="Nothing yet"
                    description="Rent charges and payments will appear here as they happen."
                />

                <ul v-else class="flex flex-col">
                    <li
                        v-for="entry in ledger.entries.slice(0, 5)"
                        :key="entry.id"
                        class="border-border/60 flex items-center gap-3 border-b py-2.5 last:border-0"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-full"
                            :class="
                                entry.kind === 'payment'
                                    ? 'bg-success/10 text-success'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            <component
                                :is="
                                    entry.direction === 'credit'
                                        ? ArrowDownLeft
                                        : ArrowUpRight
                                "
                                class="size-4"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="flex items-center gap-2 text-sm font-medium"
                            >
                                <span class="truncate">{{
                                    entry.description
                                }}</span>
                                <PaymentStatusBadge
                                    v-if="entry.settled != null"
                                    :settled="entry.settled"
                                />
                            </span>
                            <span class="text-muted-foreground text-xs">{{
                                formatDate(entry.date)
                            }}</span>
                        </span>
                        <span
                            class="shrink-0 text-sm font-medium tabular-nums"
                            :class="
                                entry.kind === 'payment'
                                    ? 'text-success'
                                    : entry.kind === 'charge'
                                      ? 'text-foreground'
                                      : 'text-muted-foreground'
                            "
                        >
                            {{ entry.direction === 'credit' ? '−' : '+'
                            }}{{ exact.format(entry.amount) }}
                        </span>
                    </li>
                </ul>
            </AppCard>
        </div>
    </div>
</template>
