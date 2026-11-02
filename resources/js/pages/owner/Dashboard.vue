<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Banknote,
    Building2,
    CheckCircle2,
    Clock,
    Percent,
    Receipt,
    RefreshCw,
    TrendingUp,
    Wallet,
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
import { computed } from 'vue';
import { dashboard, documents, financials } from '@/routes/owner';
import {
    index as maintenanceRoute,
    show as maintenanceShow,
} from '@/routes/owner/maintenance';
import { download, preview } from '@/routes/owner/documents';
import { index as messages } from '@/routes/owner/messages';
import { index as properties } from '@/routes/owner/properties';

import OwnerLedgerSummary from '@/components/OwnerLedgerSummary.vue';
import RecentActivityCard from '@/components/RecentActivityCard.vue';
import type {
    ApprovalSummary,
    DashboardActivityEntry,
    DashboardDocuments,
    DashboardMessage,
    MaintenanceSummary,
    PortalProperty,
    OwnerDashboardLedger,
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
    ledger?: OwnerDashboardLedger | null;
    messages?: DashboardMessage[] | null;
    documents?: DashboardDocuments | null;
    activity?: DashboardActivityEntry[] | null;
}>();

/** Approvals sit beside the portfolio summary; without them it takes the row. */
const needsApproval = computed(() => (props.approvals?.count ?? 0) > 0);

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

/** Undefined while the ledger loads, so its cards show a skeleton too. */
const ledgerLoading = computed(() => props.ledger === undefined);

/**
 * The period the money cards cover, named on each of them.
 *
 * Without it they read as running totals, and the twelve month chart below
 * shows quite different figures under the same labels.
 *
 * Taken from the clock rather than the ledger, which arrives later: a caption
 * that appears after the card has rendered grows it under the reader.
 */
const ledgerPeriod = computed(() => {
    const from = props.ledger?.from;
    const month = from
        ? (() => {
              const [year, monthNumber] = from.split('-').map(Number);

              return new Date(Date.UTC(year, monthNumber - 1, 1));
          })()
        : new Date();

    return month.toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: from ? 'UTC' : undefined,
    });
});

function ledgerValue(amount: number | undefined): string | null {
    return amount === undefined ? null : money.format(amount);
}

const metrics = computed<Metric[]>(() => [
    {
        label: 'Buildings',
        value: props.portfolio?.propertyCount ?? null,
        loading: props.portfolio === undefined,
        icon: Building2,
        tone: 'blue',
        href: properties().url,
    },
    {
        label: 'Open work orders',
        value: props.maintenance?.counts.open ?? null,
        loading: props.maintenance === undefined,
        icon: Wrench,
        tone: 'amber',
        href: maintenanceRoute({ query: { status: 'open' } }).url,
    },
    {
        label: 'Scheduled',
        value: props.maintenance?.counts.scheduled ?? null,
        loading: props.maintenance === undefined,
        icon: Clock,
        tone: 'violet',
        href: maintenanceRoute({ query: { status: 'scheduled' } }).url,
    },
    {
        label: 'Completed',
        value: props.maintenance?.counts.completed ?? null,
        loading: props.maintenance === undefined,
        icon: TrendingUp,
        tone: 'green',
        href: maintenanceRoute({ query: { status: 'completed' } }).url,
    },
    {
        label: 'Income',
        caption: ledgerPeriod.value,
        value: ledgerValue(props.ledger?.income),
        loading: ledgerLoading.value,
        icon: Banknote,
        tone: 'teal',
        href: financials().url,
    },
    {
        label: 'Expense',
        caption: ledgerPeriod.value,
        value: ledgerValue(props.ledger?.expense),
        loading: ledgerLoading.value,
        icon: Receipt,
        tone: 'rose',
        href: financials().url,
    },
    {
        // Only a balance that ran from the portfolio's first day is the
        // account balance; until the history is read it is the period's move.
        label: props.ledger?.fromInception
            ? 'Ending balance'
            : 'Net this period',
        caption: ledgerPeriod.value,
        value: ledgerValue(
            props.ledger?.fromInception
                ? props.ledger.endingBalance
                : props.ledger?.net,
        ),
        loading: ledgerLoading.value,
        icon: Wallet,
        tone: 'blue',
        href: financials().url,
    },
    {
        label: 'Management fee',
        caption: ledgerPeriod.value,
        value: ledgerValue(props.ledger?.managementFee),
        loading: ledgerLoading.value,
        icon: Percent,
        tone: 'violet',
        href: financials().url,
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

        <OwnerLedgerSummary :ledger="ledger" />

        <div class="grid gap-4 lg:grid-cols-2">
            <RecentActivityCard :activity="activity" />

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

        <DashboardFeeds
            :show-messages="false"
            :show-activity="false"
            :messages-href="messages().url"
            :documents-href="documents().url"
            :preview-href="(id) => preview.url(id)"
            :download-href="(id) => download.url(id)"
            :thread-href="(id) => messages({ query: { thread: id } }).url"
            :messages="props.messages"
            :documents="props.documents"
            :activity="activity"
        />
    </div>
</template>
