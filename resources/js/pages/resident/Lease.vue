<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CalendarDays,
    Receipt,
    ScrollText,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onUnmounted, ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import DetailList from '@/components/DetailList.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import ChargeBadge from '@/components/ChargeBadge.vue';
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { PERIOD_PRESETS, presetDates } from '@/lib/dateRange';
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

type Filters = {
    range: string | null;
    from: string | null;
    to: string | null;
};

const props = defineProps<{
    /** The period the transactions are narrowed to; no dates means all of them. */
    filters: Filters;
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

/**
 * The periods a tenant can pick: all of their tenancy first, then the shared
 * presets. Nothing is on the ledger before it happens, so the "next" ones are
 * left out rather than offered as a page that is always empty.
 */
const periodGroups = [
    { label: 'All', options: [{ value: 'all', label: 'All time' }] },
    ...PERIOD_PRESETS.map((group) => ({
        ...group,
        options: group.options.filter(
            (option) => !option.value.startsWith('next-'),
        ),
    })),
];

/**
 * Which preset the period control shows. The server opens the page on this
 * month and names every preset, so dates carried on their own — a link
 * someone kept, or a range picked by hand — are a custom period.
 */
function appliedRange(applied: Filters): string {
    return applied.range ?? 'custom';
}

const range = ref(appliedRange(props.filters));
const from = ref(props.filters.from ?? '');
const to = ref(props.filters.to ?? '');

const isLoading = ref(false);

/** The period as the controls currently read, before it is applied. */
function picked(): Filters {
    const dates = presetDates(range.value, from.value, to.value);

    return {
        range: range.value === 'custom' ? null : range.value,
        from: dates.from ?? null,
        to: dates.to ?? null,
    };
}

/** The query a visit carries, so paging keeps the period on screen. */
function query(filters: Filters, page = 1): Record<string, string | number> {
    return {
        ...(filters.range ? { range: filters.range } : {}),
        ...(filters.from ? { from: filters.from } : {}),
        ...(filters.to ? { to: filters.to } : {}),
        ...(page > 1 ? { page } : {}),
    };
}

function visit(filters: Filters, page = 1): void {
    isLoading.value = true;

    router.get(leaseRoute().url, query(filters, page), {
        preserveState: true,
        preserveScroll: true,
        only: ['ledger', 'filters'],
        onFinish: () => {
            isLoading.value = false;
        },
    });
}

/** Paging the transactions leaves the rest of the page as it is. */
function goToPage(page: number): void {
    visit(props.filters, page);
}

/** Whether the list covers less than the whole tenancy. */
const isFiltered = computed(() => range.value !== 'all');

/** Whether the period differs from the month the page opens on. */
const isChanged = computed(() => range.value !== 'this-month');

/** Clearing goes back to the month the page opens on. */
function clear(): void {
    range.value = 'this-month';
    from.value = '';
    to.value = '';
}

/** Whether the controls already match what the server has applied. */
function matchesApplied(): boolean {
    const current = picked();

    return (
        (current.range ?? '') === (props.filters.range ?? '') &&
        (current.from ?? '') === (props.filters.from ?? '') &&
        (current.to ?? '') === (props.filters.to ?? '')
    );
}

// Picking a period applies it straight away, as on the owner's ledger. Typing
// into the custom dates pauses first so a half-typed year never gets sent.
watchDebounced(
    [range, from, to],
    () => {
        if (!matchesApplied()) {
            visit(picked());
        }
    },
    { debounce: 300 },
);

/** A visit that carries a different period — back, a kept link — has the last word. */
watch(
    () => props.filters,
    (applied) => {
        range.value = appliedRange(applied);
        from.value = applied.from ?? '';
        to.value = applied.to ?? '';
    },
);

/** "Sep 1, 2026 – Sep 30, 2026" for a picked period, or where the history starts. */
const periodLabel = computed<string>(() => {
    if (!props.ledger) {
        return '—';
    }

    return isFiltered.value
        ? `${formatDate(props.ledger.from)} – ${formatDate(props.ledger.to)}`
        : `since ${formatDate(props.ledger.from)}`;
});

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
                    <div
                        v-if="ledgerAvailable"
                        class="flex flex-wrap items-end gap-3"
                    >
                        <div class="grid w-full gap-1.5 sm:w-auto">
                            <span class="text-sm font-medium">Period</span>
                            <Select v-model="range">
                                <SelectTrigger class="w-full sm:w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent class="max-h-96">
                                    <SelectGroup
                                        v-for="group in periodGroups"
                                        :key="group.label"
                                    >
                                        <SelectLabel>{{
                                            group.label
                                        }}</SelectLabel>
                                        <SelectItem
                                            v-for="option in group.options"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            v-if="range === 'custom'"
                            class="grid w-full gap-1.5 sm:w-auto"
                        >
                            <span class="text-sm font-medium">From</span>
                            <Input
                                v-model="from"
                                type="date"
                                :max="to || undefined"
                            />
                        </div>

                        <div
                            v-if="range === 'custom'"
                            class="grid w-full gap-1.5 sm:w-auto"
                        >
                            <span class="text-sm font-medium">To</span>
                            <Input
                                v-model="to"
                                type="date"
                                :min="from || undefined"
                            />
                        </div>

                        <Button
                            v-if="isChanged"
                            variant="ghost"
                            :disabled="isLoading"
                            @click="clear"
                        >
                            Clear filter
                        </Button>
                    </div>

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
                                {{ periodLabel }}
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
                                {{
                                    isFiltered
                                        ? 'received from you in this period'
                                        : 'received from you'
                                }}
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
                            :title="
                                isFiltered
                                    ? 'Nothing in this period'
                                    : 'Nothing yet'
                            "
                            :description="
                                isFiltered
                                    ? 'No charges or payments landed on your lease between these dates. Try another period.'
                                    : 'Rent charges and payments will appear here as they happen.'
                            "
                        />

                        <template v-else>
                            <ul class="flex flex-col">
                                <li
                                    v-for="entry in ledger.entries"
                                    :key="entry.id"
                                    class="border-border/60 flex items-center gap-3 border-b py-3 last:border-0"
                                >
                                    <span
                                        class="flex size-9 shrink-0 items-center justify-center rounded-full"
                                        :class="
                                            entry.kind === 'payment'
                                                ? 'bg-success/10 text-success'
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

                                    <span
                                        class="flex min-w-0 flex-1 flex-col gap-0.5"
                                    >
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
                                            <ChargeBadge
                                                v-else-if="
                                                    entry.kind === 'charge'
                                                "
                                            />
                                        </span>
                                        <span
                                            v-if="entry.detail"
                                            class="text-muted-foreground truncate text-xs"
                                            >{{ entry.detail }}</span
                                        >
                                        <span
                                            class="text-muted-foreground text-xs"
                                        >
                                            {{
                                                entry.kind === 'payment'
                                                    ? `Paid ${formatDate(entry.date)}`
                                                    : entry.kind === 'charge'
                                                      ? `Billed ${formatDate(entry.date)}`
                                                      : formatDate(entry.date)
                                            }}
                                            <template
                                                v-if="entry.settled != null"
                                            >
                                                ·
                                                <span
                                                    :class="{
                                                        'text-success':
                                                            entry.settled,
                                                    }"
                                                    >{{
                                                        entry.settledOn
                                                            ? `In the bank ${formatDate(entry.settledOn)}`
                                                            : 'On its way to the bank'
                                                    }}</span
                                                >
                                            </template>
                                        </span>
                                    </span>

                                    <span
                                        class="shrink-0 text-sm font-semibold tabular-nums"
                                        :class="
                                            entry.kind === 'payment'
                                                ? 'text-success'
                                                : 'text-foreground'
                                        "
                                    >
                                        {{ entry.kind === 'payment' ? '−' : '+'
                                        }}{{ money.format(entry.amount) }}
                                    </span>
                                </li>
                            </ul>

                            <p class="text-muted-foreground mt-4 text-xs">
                                <span class="text-success font-medium">−</span>
                                payments come off your balance;
                                <span class="text-foreground font-medium"
                                    >+</span
                                >
                                charges add to it. A payment is
                                <span class="font-medium">Pending</span> while
                                it is on its way to the bank and
                                <span class="text-success font-medium"
                                    >Settled</span
                                >
                                once it has been deposited. Payments are made in
                                your Propertyware tenant portal; this page shows
                                what has landed on your lease.
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
