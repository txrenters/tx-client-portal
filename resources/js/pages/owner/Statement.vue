<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
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
            <Select v-model="month">
                <SelectTrigger class="w-full sm:w-48" aria-label="Month">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="item in months"
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
