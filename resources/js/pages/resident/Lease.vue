<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CalendarDays,
    Receipt,
    ScrollText,
} from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import DetailList from '@/components/DetailList.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { dashboard, lease as leaseRoute } from '@/routes/resident';
import type { LeaseLedger, PortalProperty, ResidentLease } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Lease', href: leaseRoute() },
        ],
    },
});

const props = defineProps<{
    /** False when the ledger connection isn't set up. */
    ledgerAvailable: boolean;
    /** Undefined while loading, null when it couldn't be read. */
    lease?: ResidentLease | null;
    property?: PortalProperty | null;
    ledger?: LeaseLedger | null;
}>();

const { formatDate } = useFormattedDate();

const pollTimer = ref<ReturnType<typeof setInterval> | null>(null);

function startPolling(): void {
    if (pollTimer.value !== null) {
        return;
    }

    pollTimer.value = setInterval(() => {
        router.reload({ only: ['ledger'] });
    }, 5000);
}

function stopPolling(): void {
    if (pollTimer.value !== null) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
}

onUnmounted(stopPolling);

/** While the first read runs, check back for it without a page reload. */
watch(
    () => props.ledger?.syncing,
    (syncing) => {
        if (syncing) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

/** Paging the transactions leaves the rest of the page as it is. */
function goToPage(page: number): void {
    router.get(
        leaseRoute().url,
        { page },
        { preserveState: true, preserveScroll: true, only: ['ledger'] },
    );
}

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

const term = computed(() => [
    ...(props.lease?.name ? [{ label: 'Lease', value: props.lease.name }] : []),
    {
        label: 'Property',
        value: props.property
            ? [
                  props.property.address,
                  props.property.unitName
                      ? `Unit ${props.property.unitName}`
                      : '',
                  [
                      props.property.city,
                      props.property.state,
                      props.property.postalCode,
                  ]
                      .filter(Boolean)
                      .join(', '),
              ]
                  .filter(Boolean)
                  .join(' · ')
            : '—',
    },
    { label: 'Status', value: props.lease?.status ?? '—' },
    {
        label: 'Starts',
        value: formatDate(props.lease?.startDate ?? null) || '—',
    },
    { label: 'Ends', value: formatDate(props.lease?.endDate ?? null) || '—' },
    {
        label: 'Moved in',
        value: formatDate(props.lease?.moveInDate ?? null) || '—',
    },
    ...(props.lease?.moveOutDate
        ? [{ label: 'Moving out', value: formatDate(props.lease.moveOutDate) }]
        : []),
    ...(props.lease?.noticeGivenDate
        ? [
              {
                  label: 'Notice given',
                  value: formatDate(props.lease.noticeGivenDate),
              },
          ]
        : []),
]);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-6 p-4 md:p-6">
        <Head title="Lease" />

        <PageHeader
            title="Lease"
            :description="
                property
                    ? `Your lease for ${property.address}, and what you have paid on it.`
                    : 'Your lease with TexasRenters.com, and what you have paid on it.'
            "
        />

        <LoadingSkeleton v-if="lease === undefined" :rows="2" />

        <ErrorState
            v-else-if="lease === null"
            description="We couldn't load your lease right now."
        />

        <EmptyState
            v-else-if="!lease.id"
            :icon="ScrollText"
            title="No lease on file"
            description="Contact TexasRenters.com if your lease should appear here."
        />

        <template v-else>
            <!-- The lease on the left, what has been charged and paid beside it. -->
            <div class="grid items-start gap-4 lg:grid-cols-3">
                <div class="flex min-w-0 flex-col gap-4 lg:col-span-1">
                    <AppCard title="Term">
                        <template #header>
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <span class="text-base font-semibold"
                                    >Term</span
                                >
                                <Badge v-if="lease.endsSoon" variant="outline">
                                    <CalendarDays class="size-3" />
                                    Ending soon
                                </Badge>
                            </div>
                        </template>

                        <DetailList :items="term" />
                    </AppCard>

                    <AppCard title="Rent">
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col gap-1">
                                <p class="text-3xl leading-none font-semibold">
                                    {{
                                        lease.rent === null
                                            ? '—'
                                            : money.format(lease.rent)
                                    }}
                                </p>
                                <p class="text-muted-foreground text-sm">
                                    base rent each month
                                </p>
                            </div>

                            <div class="flex flex-col gap-1">
                                <p class="text-lg font-medium">
                                    {{
                                        lease.balance === null
                                            ? '—'
                                            : money.format(lease.balance)
                                    }}
                                </p>
                                <p class="text-muted-foreground text-sm">
                                    balance on your lease
                                </p>
                            </div>

                            <p
                                v-if="lease.lateFeeRule"
                                class="text-muted-foreground border-border/60 border-t pt-3 text-sm"
                            >
                                <span class="text-foreground font-medium"
                                    >Late fees:</span
                                >
                                {{ lease.lateFeeRule }}
                            </p>
                        </div>
                    </AppCard>
                </div>

                <div class="flex min-w-0 flex-col gap-4 lg:col-span-2">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppCard title="Charged">
                            <Skeleton
                                v-if="ledgerAvailable && ledger === undefined"
                                class="h-9 w-28"
                            />
                            <p
                                v-else
                                class="text-3xl leading-none font-semibold"
                            >
                                {{
                                    ledger ? money.format(ledger.charged) : '—'
                                }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                since
                                {{ ledger ? formatDate(ledger.from) : '—' }}
                            </p>
                        </AppCard>

                        <AppCard title="Paid">
                            <Skeleton
                                v-if="ledgerAvailable && ledger === undefined"
                                class="h-9 w-28"
                            />
                            <p
                                v-else
                                class="text-3xl leading-none font-semibold"
                            >
                                {{ ledger ? money.format(ledger.paid) : '—' }}
                            </p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                received from you
                            </p>
                        </AppCard>
                    </div>

                    <AppCard title="Transactions">
                        <div
                            v-if="ledger?.syncing"
                            class="border-border/60 bg-muted/40 mb-4 flex items-center gap-3 rounded-xl border p-3"
                            role="status"
                        >
                            <Spinner class="size-4 shrink-0" />
                            <p class="text-muted-foreground text-sm">
                                We're still fetching your charges and payments
                                from our records. They'll appear here in a
                                moment.
                            </p>
                        </div>

                        <EmptyState
                            v-if="!ledgerAvailable"
                            :icon="Receipt"
                            title="Payments aren't connected yet"
                            description="Your charges and payments will appear here once we finish setting this up."
                        />

                        <LoadingSkeleton
                            v-else-if="ledger === undefined"
                            :rows="4"
                        />

                        <ErrorState
                            v-else-if="ledger === null"
                            description="We couldn't load your charges and payments right now."
                        />

                        <EmptyState
                            v-else-if="
                                ledger.entries.length === 0 && !ledger.syncing
                            "
                            :icon="Receipt"
                            title="Nothing yet"
                            description="Rent charges and payments will appear here as they happen."
                        />

                        <template v-else>
                            <ul class="flex flex-col">
                                <li
                                    v-for="entry in ledger.entries"
                                    :key="entry.id"
                                    class="border-border/60 flex items-center gap-3 border-b py-3 last:border-0"
                                >
                                    <span
                                        class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                                        :class="
                                            entry.kind === 'payment'
                                                ? 'bg-destructive/10 text-destructive'
                                                : 'bg-muted text-muted-foreground'
                                        "
                                    >
                                        <component
                                            :is="
                                                entry.kind === 'payment'
                                                    ? ArrowDownLeft
                                                    : ArrowUpRight
                                            "
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>

                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span
                                            class="truncate text-sm font-medium"
                                            >{{ entry.description }}</span
                                        >
                                        <span
                                            v-if="entry.detail"
                                            class="text-muted-foreground truncate text-xs"
                                            >{{ entry.detail }}</span
                                        >
                                    </span>

                                    <span
                                        class="flex shrink-0 flex-col items-end"
                                    >
                                        <span
                                            class="text-sm font-medium tabular-nums"
                                            :class="
                                                entry.kind === 'payment'
                                                    ? 'text-destructive'
                                                    : 'text-foreground'
                                            "
                                        >
                                            {{
                                                entry.kind === 'payment'
                                                    ? '−'
                                                    : '+'
                                            }}{{ money.format(entry.amount) }}
                                        </span>
                                        <span
                                            class="text-muted-foreground text-xs"
                                            >{{ formatDate(entry.date) }}</span
                                        >
                                    </span>
                                </li>
                            </ul>

                            <p class="text-muted-foreground mt-4 text-xs">
                                <span class="text-destructive font-medium"
                                    >−</span
                                >
                                payments come off your balance;
                                <span class="text-foreground font-medium"
                                    >+</span
                                >
                                charges add to it. Payments are made in your
                                Propertyware tenant portal; this page shows what
                                has landed on your lease.
                            </p>
                        </template>
                    </AppCard>

                    <ResultsPagination
                        v-if="ledger?.results"
                        :results="ledger.results"
                        @change="goToPage"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
