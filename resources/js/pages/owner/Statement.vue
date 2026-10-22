<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, FileText } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { dashboard, statements } from '@/routes/owner';
import type { OwnerStatement } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Statements', href: statements() },
        ],
    },
});

const props = defineProps<{
    filters: { portfolio: number | null; month: string };
    months: { value: string; label: string }[];
    /** The months an income statement PDF can be made between. */
    pdfRange: { earliest: string; latest: string; maxMonths: number };
    portfolios?: { id: number; name: string }[];
    /** Undefined while loading, null when it couldn't be read. */
    statement?: OwnerStatement | null;
}>();

const { formatDate } = useFormattedDate();

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

const month = ref(props.filters.month);

/** The years on offer, newest first, so an owner can go back a year at a time. */
const years = computed(() => [
    ...new Set(props.months.map((item) => item.value.slice(0, 4))),
]);
const year = computed({
    get: () => month.value.slice(0, 4),
    // The same month in the year picked, or that year's latest on offer.
    set: (picked: string) => {
        const inYear = props.months.filter((item) =>
            item.value.startsWith(picked),
        );
        const same = inYear.find(
            (item) => item.value.slice(5) === month.value.slice(5),
        );

        month.value = (same ?? inYear[0]).value;
    },
});
/** Months as YYYY-MM, moved by a number of months. */
function shiftMonth(month: string, by: number): string {
    const [y, m] = month.split('-').map(Number);
    const moved = new Date(Date.UTC(y, m - 1 + by, 1));

    return `${moved.getUTCFullYear()}-${String(moved.getUTCMonth() + 1).padStart(2, '0')}`;
}

function monthLabel(month: string): string {
    const [y, m] = month.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

/**
 * The income statement PDF: any run of up to twelve months, across a year
 * end if need be. Made when it is asked for and never kept: the owner's
 * download is the only copy.
 */
const pdfOpen = ref(false);
const pdfMonths = computed(() => {
    const all: string[] = [];

    for (
        let month = props.pdfRange.latest;
        month >= props.pdfRange.earliest;
        month = shiftMonth(month, -1)
    ) {
        all.push(month);
    }

    return all;
});
// The last twelve finished months by default.
const pdfTo = ref(props.months[0]?.value ?? props.pdfRange.latest);
const pdfFrom = ref(
    [
        shiftMonth(pdfTo.value, 1 - props.pdfRange.maxMonths),
        props.pdfRange.earliest,
    ]
        .sort()
        .at(-1)!,
);
/** The end month runs from the start month to twelve months on. */
const pdfToOptions = computed(() =>
    pdfMonths.value.filter(
        (month) =>
            month >= pdfFrom.value &&
            month <= shiftMonth(pdfFrom.value, props.pdfRange.maxMonths - 1),
    ),
);
watch(pdfFrom, (from) => {
    if (!pdfToOptions.value.includes(pdfTo.value)) {
        pdfTo.value = [
            shiftMonth(from, props.pdfRange.maxMonths - 1),
            props.pdfRange.latest,
        ].sort()[0];
    }
});
const pdfHref = computed(() => {
    const query = new URLSearchParams({ from: pdfFrom.value, to: pdfTo.value });

    if (portfolio.value !== '') {
        query.set('portfolio', portfolio.value);
    }

    return `${statements().url}/income-statement.pdf?${query}`;
});

const monthsInYear = computed(() =>
    props.months.filter((item) => item.value.startsWith(year.value)),
);
const portfolio = ref(
    props.filters.portfolio === null ? '' : String(props.filters.portfolio),
);

// Once the statement names its portfolio, the picker shows it.
watch(
    () => props.statement?.portfolio?.id,
    (id) => {
        if (id !== undefined && portfolio.value === '') {
            portfolio.value = String(id);
        }
    },
    { immediate: true },
);

watch(
    [month, portfolio],
    ([pickedMonth, pickedPortfolio], [lastMonth, lastPortfolio]) => {
        // Filling the picker in from the statement is not a new choice.
        if (pickedMonth === lastMonth && lastPortfolio === '') {
            return;
        }

        router.get(
            statements().url,
            { month: pickedMonth, portfolio: pickedPortfolio || undefined },
            {
                only: ['statement', 'filters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    },
);
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Statements" />

        <PageHeader
            title="Statements"
            description="What each portfolio carried in, earned, spent and paid out to you, month by month."
        />

        <div class="flex flex-col gap-2 sm:flex-row">
            <Select v-model="year">
                <SelectTrigger class="w-full sm:w-28" aria-label="Year">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="item in years"
                        :key="item"
                        :value="item"
                        >{{ item }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="month">
                <SelectTrigger class="w-full sm:w-48" aria-label="Month">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="item in monthsInYear"
                        :key="item.value"
                        :value="item.value"
                        >{{ item.label }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select
                v-if="portfolios && portfolios.length > 1"
                v-model="portfolio"
            >
                <SelectTrigger class="w-full sm:w-64" aria-label="Portfolio">
                    <SelectValue placeholder="Portfolio" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="item in portfolios"
                        :key="item.id"
                        :value="String(item.id)"
                        >{{ item.name }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Dialog v-model:open="pdfOpen">
                <DialogTrigger as-child>
                    <Button variant="outline" class="sm:ml-auto">
                        <Download class="size-4" aria-hidden="true" />
                        Income statement (PDF)
                    </Button>
                </DialogTrigger>
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Income statement</DialogTitle>
                        <DialogDescription>
                            Month by month, up to
                            {{ pdfRange.maxMonths }} months, across a year end
                            if you like. It downloads to your device; we keep no
                            copy.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="pdf-from">From</Label>
                            <Select v-model="pdfFrom">
                                <SelectTrigger id="pdf-from" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="month in pdfMonths"
                                        :key="month"
                                        :value="month"
                                        >{{ monthLabel(month) }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="pdf-to">To</Label>
                            <Select v-model="pdfTo">
                                <SelectTrigger id="pdf-to" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="month in pdfToOptions"
                                        :key="month"
                                        :value="month"
                                        >{{ monthLabel(month) }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button as-child @click="pdfOpen = false">
                            <a :href="pdfHref" download>
                                <Download class="size-4" aria-hidden="true" />
                                Download PDF
                            </a>
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>

        <div v-if="statement === undefined" class="flex flex-col gap-4">
            <Skeleton class="h-28 w-full" />
            <Skeleton class="h-64 w-full" />
        </div>

        <ErrorState
            v-else-if="statement === null"
            title="We couldn't load this statement"
            description="Propertyware didn't answer. Please try again in a moment."
        />

        <template v-else>
            <AppCard
                :title="statement.portfolio?.name ?? 'Your portfolio'"
                :description="`${statement.label} · ${formatDate(statement.from)} – ${formatDate(statement.to)}`"
            >
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Opening balance
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ money.format(statement.openingBalance) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Income</dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ money.format(statement.income) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">Expenses</dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ money.format(-statement.expense) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Paid to you
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ money.format(-statement.draws) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground text-xs">
                            Ending balance
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ money.format(statement.endingBalance) }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="!statement.fromInception"
                    class="text-muted-foreground mt-4 text-sm text-pretty"
                >
                    We're still adding up this portfolio's history, so the
                    balances show only this month's movement for now. Check back
                    in a few minutes for the full figures.
                </p>
            </AppCard>

            <div class="grid gap-6 lg:grid-cols-2">
                <AppCard title="Income by account">
                    <p
                        v-if="statement.incomeAccounts.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        No income this month.
                    </p>
                    <ul v-else class="divide-border divide-y text-sm">
                        <li
                            v-for="item in statement.incomeAccounts"
                            :key="item.name"
                            class="flex justify-between gap-4 py-2"
                        >
                            <span>{{ item.name }}</span>
                            <span class="tabular-nums">{{
                                money.format(item.amount)
                            }}</span>
                        </li>
                    </ul>
                </AppCard>
                <AppCard title="Expenses by account">
                    <p
                        v-if="statement.expenseAccounts.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        No expenses this month.
                    </p>
                    <ul v-else class="divide-border divide-y text-sm">
                        <li
                            v-for="item in statement.expenseAccounts"
                            :key="item.name"
                            class="flex justify-between gap-4 py-2"
                        >
                            <span>{{ item.name }}</span>
                            <span class="tabular-nums">{{
                                money.format(item.amount)
                            }}</span>
                        </li>
                    </ul>
                </AppCard>
            </div>

            <AppCard title="Transactions">
                <EmptyState
                    v-if="statement.entries.length === 0"
                    :icon="FileText"
                    title="Nothing moved this month"
                    description="No money came in or went out of this portfolio."
                />
                <Table v-else>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Property</TableHead
                            >
                            <TableHead class="text-right">Amount</TableHead>
                            <TableHead class="text-right">Balance</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="entry in statement.entries"
                            :key="entry.id"
                        >
                            <TableCell class="whitespace-nowrap">{{
                                formatDate(entry.date)
                            }}</TableCell>
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{
                                        entry.description
                                    }}</span>
                                    <Badge
                                        v-if="entry.settled != null"
                                        :variant="
                                            entry.settled
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        >{{
                                            entry.settled
                                                ? 'Settled'
                                                : 'Pending'
                                        }}</Badge
                                    >
                                </div>
                                <p
                                    v-if="entry.details"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ entry.details }}
                                </p>
                            </TableCell>
                            <TableCell
                                class="text-muted-foreground hidden md:table-cell"
                                >{{ entry.location ?? '—' }}</TableCell
                            >
                            <TableCell
                                class="text-right tabular-nums"
                                :class="
                                    entry.amount < 0 ? 'text-destructive' : ''
                                "
                                >{{ money.format(entry.amount) }}</TableCell
                            >
                            <TableCell
                                class="text-muted-foreground text-right tabular-nums"
                                >{{ money.format(entry.balance) }}</TableCell
                            >
                        </TableRow>
                    </TableBody>
                </Table>
            </AppCard>
        </template>
    </div>
</template>
