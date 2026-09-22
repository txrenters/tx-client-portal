<script setup lang="ts">
import { VisAxis, VisGroupedBar, VisXYContainer } from '@unovis/vue';
import { computed, ref } from 'vue';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartCrosshair,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import type { OwnerMonthlyTotals } from '@/types';

/**
 * Income against expense, month by month.
 *
 * Bars rather than a ring: the question is which is bigger and by how much,
 * which length answers and angle does not. Both series are dollars, so they
 * share one axis — never two scales on one chart.
 *
 * Picking a series in the header focuses it, and the scale re-fits so a small
 * series is not left as a sliver against a large one.
 */
const props = defineProps<{
    months: OwnerMonthlyTotals[];
}>();

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

const exact = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

const config = {
    income: { label: 'Income', color: 'var(--chart-income)' },
    expense: { label: 'Expense', color: 'var(--chart-expense)' },
} satisfies ChartConfig;

type SeriesKey = keyof typeof config;

const SERIES: SeriesKey[] = ['income', 'expense'];

/** Which series the chart is focused on; both, until one is picked. */
const focused = ref<SeriesKey | null>(null);

const shown = computed<SeriesKey[]>(() =>
    focused.value === null ? SERIES : [focused.value],
);

const totals = computed(() => {
    const income = props.months.reduce((sum, month) => sum + month.income, 0);
    const expense = props.months.reduce((sum, month) => sum + month.expense, 0);

    return { income, expense, net: income - expense };
});

/** Unovis plots against numbers, so each month is its position in the list. */
const data = computed(() =>
    props.months.map((month, index) => ({ ...month, index })),
);

type Datum = (typeof data.value)[number];

const accessors = computed(() =>
    shown.value.map((key) => (datum: Datum) => datum[key]),
);

const colors = computed(() => shown.value.map((key) => config[key].color));

function monthLabel(month: string): string {
    const [year, monthNumber] = month.split('-').map(Number);

    return new Date(Date.UTC(year, monthNumber - 1, 1)).toLocaleDateString(
        'en-US',
        { month: 'short', timeZone: 'UTC' },
    );
}

function focus(key: SeriesKey | null): void {
    focused.value = key;
}
</script>

<template>
    <figure class="flex min-w-0 flex-col gap-4">
        <figcaption
            class="border-border grid grid-cols-3 gap-px overflow-hidden rounded-lg border sm:max-w-lg"
        >
            <button
                type="button"
                class="bg-card hover:bg-muted/60 flex flex-col gap-1 px-4 py-3 text-left transition-colors"
                :class="focused === null ? 'bg-muted/60' : ''"
                :aria-pressed="focused === null"
                @click="focus(null)"
            >
                <span
                    class="text-muted-foreground flex items-center gap-1.5 text-xs"
                >
                    <span
                        v-for="key in SERIES"
                        :key="key"
                        class="size-2.5 rounded-full"
                        :style="{ backgroundColor: config[key].color }"
                        aria-hidden="true"
                    />
                    All
                </span>
                <span class="text-xl font-semibold tabular-nums">
                    {{ exact.format(totals.net) }}
                </span>
            </button>
            <button
                v-for="key in SERIES"
                :key="key"
                type="button"
                class="bg-card hover:bg-muted/60 flex flex-col gap-1 px-4 py-3 text-left transition-colors"
                :class="focused === key ? 'bg-muted/60' : ''"
                :aria-pressed="focused === key"
                @click="focus(key)"
            >
                <span
                    class="text-muted-foreground flex items-center gap-2 text-xs"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ backgroundColor: config[key].color }"
                        aria-hidden="true"
                    />
                    {{ config[key].label }}
                </span>
                <span class="text-xl font-semibold tabular-nums">
                    {{ exact.format(totals[key]) }}
                </span>
            </button>
        </figcaption>

        <ChartContainer :config="config" class="h-72 w-full">
            <VisXYContainer :data="data">
                <VisGroupedBar
                    :x="(d: Datum) => d.index"
                    :y="accessors"
                    :color="colors"
                    :rounded-corners="3"
                    :group-padding="0.2"
                />
                <VisAxis
                    type="x"
                    :tick-format="
                        (value: number) =>
                            months[value] ? monthLabel(months[value].month) : ''
                    "
                    :grid-line="false"
                    :tick-line="false"
                />
                <VisAxis
                    type="y"
                    :tick-format="(value: number) => money.format(value)"
                    :tick-line="false"
                    :domain-line="false"
                />
                <ChartTooltip />
                <ChartCrosshair
                    :x="(d: Datum) => d.index"
                    :y="accessors"
                    :color="colors"
                    :template="
                        componentToString(config, ChartTooltipContent, {
                            labelFormatter: (value: number | Date) =>
                                months[Number(value)]
                                    ? monthLabel(months[Number(value)].month)
                                    : '',
                        })
                    "
                />
            </VisXYContainer>
        </ChartContainer>
    </figure>
</template>
