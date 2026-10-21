<script setup lang="ts">
import { Banknote, Download, Percent, Receipt, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import DashboardMetrics from '@/components/DashboardMetrics.vue';
import type { Metric } from '@/components/DashboardMetrics.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormattedDate } from '@/composables/useFormattedDate';
import type { OwnerLedgerData, OwnerLedgerEntry } from '@/types';

const props = defineProps<{
    /** Undefined while loading, null when it could not be read. */
    ledger?: OwnerLedgerData | null;
}>();

const { formatDate } = useFormattedDate();

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

/**
 * One quiet line under each transaction: when it happened, what it touched,
 * and where — the parts that are actually filled in, joined.
 */
function line(entry: OwnerLedgerEntry): string {
    return [
        formatDate(entry.date),
        entry.details,
        entry.location,
        entry.reference,
    ]
        .filter((part): part is string => Boolean(part))
        .join(' · ');
}

/** Whether a tenant's payment has reached the bank; null on other lines. */
function settlementLabel(entry: OwnerLedgerEntry): string | null {
    if (entry.settled == null) {
        return null;
    }

    return entry.settled ? 'Settled' : 'Pending';
}

/** The dates on screen, named under every figure they belong to. */
const period = computed(() =>
    props.ledger
        ? `${formatDate(props.ledger.from)} – ${formatDate(props.ledger.to)}`
        : null,
);

const metrics = computed<Metric[]>(() => {
    const ledger = props.ledger;
    const loading = ledger === undefined;
    const caption = period.value;

    const value = (amount: number | undefined): string | null =>
        amount === undefined ? null : money.format(amount);

    return [
        {
            label: 'Income',
            value: value(ledger?.income),
            loading,
            caption,
            icon: Banknote,
        },
        {
            label: 'Expense',
            value: value(ledger?.expense),
            loading,
            caption,
            icon: Receipt,
        },
        {
            // Only a balance that ran from the portfolio's first day is the
            // account balance; otherwise it is this period's own movement.
            label: ledger?.fromInception ? 'Ending balance' : 'Net this period',
            value: value(
                ledger?.fromInception ? ledger.endingBalance : ledger?.net,
            ),
            loading,
            caption,
            icon: Wallet,
        },
        {
            label: 'Management fee',
            value: value(ledger?.managementFee),
            loading,
            caption,
            icon: Percent,
        },
    ];
});

function csv(): void {
    if (!props.ledger) {
        return;
    }

    const rows = [
        [
            'Date',
            'Ref #',
            'Location',
            'Description',
            'Details',
            'Amount',
            'Balance',
            'Status',
        ],
        ...props.ledger.entries.map((entry) => [
            entry.date,
            entry.reference ?? '',
            entry.location ?? '',
            entry.description,
            entry.details ?? '',
            entry.amount.toFixed(2),
            entry.balance.toFixed(2),
            settlementLabel(entry) ?? '',
        ]),
    ];

    const body = rows
        .map((row) =>
            row
                .map((cell) => `"${String(cell).replace(/"/g, '""')}"`)
                .join(','),
        )
        .join('\n');

    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([body], { type: 'text/csv' }));
    link.download = `ledger-${props.ledger.from}-to-${props.ledger.to}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <DashboardMetrics :metrics="metrics" />

        <div class="border-border bg-card overflow-hidden rounded-xl border">
            <div
                class="border-border flex items-center justify-between gap-3 border-b px-4 py-3"
            >
                <h2 class="text-base font-semibold">Transactions</h2>
                <Button
                    v-if="ledger"
                    variant="ghost"
                    size="sm"
                    :disabled="ledger.entries.length === 0"
                    aria-label="Download this ledger as CSV"
                    @click="csv()"
                >
                    <Download class="size-4" />
                </Button>
            </div>

            <div v-if="ledger === undefined" class="flex flex-col gap-3 p-4">
                <Skeleton v-for="row in 8" :key="row" class="h-10 w-full" />
            </div>

            <ErrorState
                v-else-if="ledger === null"
                class="m-4"
                title="We could not read your ledger"
                description="Propertyware did not answer. Try again in a moment."
            />

            <EmptyState
                v-else-if="ledger.entries.length === 0"
                class="m-4"
                title="Nothing in this period"
                description="No money moved on this portfolio between these dates."
            />

            <ul v-else class="divide-border divide-y">
                <li
                    v-for="entry in ledger.entries"
                    :key="entry.id"
                    class="flex items-start gap-4 px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 text-sm font-medium">
                            <span class="truncate">{{
                                entry.description
                            }}</span>
                            <Badge
                                v-if="entry.settled != null"
                                :variant="
                                    entry.settled ? 'secondary' : 'outline'
                                "
                                class="shrink-0"
                                :title="
                                    entry.settledOn
                                        ? `Deposited ${formatDate(entry.settledOn)}`
                                        : 'Not deposited yet, and can still be returned'
                                "
                                >{{ settlementLabel(entry) }}</Badge
                            >
                        </p>
                        <p
                            class="text-muted-foreground mt-0.5 truncate text-xs"
                        >
                            {{ line(entry) }}
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p
                            class="text-sm font-medium tabular-nums"
                            :class="entry.amount < 0 ? 'text-destructive' : ''"
                        >
                            {{ money.format(entry.amount) }}
                        </p>
                        <p
                            class="text-muted-foreground mt-0.5 text-xs tabular-nums"
                        >
                            {{ money.format(entry.balance) }}
                        </p>
                    </div>
                </li>
            </ul>

            <p
                v-if="ledger && !ledger.fromInception"
                class="text-muted-foreground border-border border-t px-4 py-3 text-sm text-pretty"
            >
                The running total starts from zero at the beginning of this
                period, so it shows what moved between these dates rather than
                the balance carried forward from before them.
            </p>
        </div>
    </div>
</template>
