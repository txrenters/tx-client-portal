<script setup lang="ts">
import { computed, ref } from 'vue';
import { appDateFormatter } from '@/lib/timezone';

/**
 * One bar per day, optionally stacked by series, with a hover (and keyboard)
 * tooltip giving the day and every value, a scale on the left, dates along
 * the bottom and a legend. Colours are the theme's chart tokens, passed in
 * per series in a fixed order so a series keeps its colour.
 */
const props = withDefaults(
    defineProps<{
        days: ({ date: string } & Record<string, number | string>)[];
        series: { key: string; label: string; colorClass: string }[];
        /** What one unit is called in the tooltip, e.g. "sign-up". */
        unit?: string;
        height?: string;
    }>(),
    { unit: '', height: 'h-40' },
);

const longDate = appDateFormatter({ weekday: 'short', month: 'short', day: 'numeric' });
const shortDate = appDateFormatter({ month: 'short', day: 'numeric' });

/** Noon, so the calendar date never slips across a timezone boundary. */
function asDate(date: string): Date {
    return new Date(`${date}T12:00:00`);
}

function value(day: Record<string, number | string>, key: string): number {
    return Number(day[key] ?? 0);
}

function total(day: Record<string, number | string>): number {
    return props.series.reduce((sum, item) => sum + value(day, item.key), 0);
}

/** A round top for the scale, so the gridline reads as a real number. */
const scaleTop = computed(() => {
    const busiest = Math.max(0, ...props.days.map(total));

    if (busiest <= 5) {
        return Math.max(1, busiest);
    }

    const magnitude = 10 ** Math.floor(Math.log10(busiest));

    return Math.ceil(busiest / magnitude) * magnitude;
});

const sum = computed(() =>
    Object.fromEntries(
        props.series.map((item) => [
            item.key,
            props.days.reduce((total, day) => total + value(day, item.key), 0),
        ]),
    ),
);

/** Dates under the axis: first, middle and last day. */
const ticks = computed(() => {
    const count = props.days.length;

    return [0, Math.floor((count - 1) / 2), count - 1]
        .filter((index, position, all) => all.indexOf(index) === position)
        .map((index) => ({
            index,
            label: shortDate.format(asDate(props.days[index].date)),
        }));
});

const active = ref<number | null>(null);

const activeDay = computed(() =>
    active.value === null ? null : props.days[active.value],
);

function plural(count: number): string {
    if (!props.unit) {
        return String(count);
    }

    return `${count} ${props.unit}${count === 1 ? '' : 's'}`;
}

function describe(day: Record<string, number | string>): string {
    const parts = props.series.map(
        (item) => `${item.label}: ${value(day, item.key)}`,
    );

    return `${longDate.format(asDate(String(day.date)))}, ${plural(total(day))}${
        props.series.length > 1 ? ` (${parts.join(', ')})` : ''
    }`;
}
</script>

<template>
    <div>
        <div
            v-if="series.length > 1"
            class="text-muted-foreground mb-3 flex flex-wrap gap-4 text-xs"
        >
            <span
                v-for="item in series"
                :key="item.key"
                class="flex items-center gap-1.5"
            >
                <span class="size-2.5 rounded-sm" :class="item.colorClass" />
                {{ item.label }}
                <span class="text-foreground font-medium tabular-nums">{{
                    sum[item.key]
                }}</span>
            </span>
        </div>

        <div class="flex gap-2">
            <!-- Scale -->
            <div
                class="text-muted-foreground flex flex-col justify-between text-right text-xs tabular-nums"
                :class="height"
                aria-hidden="true"
            >
                <span class="-mt-1.5">{{ scaleTop }}</span>
                <span class="-mb-1.5">0</span>
            </div>

            <div class="relative min-w-0 flex-1">
                <!-- Gridlines at the top of the scale and at zero -->
                <div
                    class="pointer-events-none absolute inset-x-0 top-0 border-t border-dashed"
                    aria-hidden="true"
                />
                <div
                    class="flex items-end gap-0.5 border-b"
                    :class="height"
                    role="list"
                    :aria-label="`Per day, ${days.length} days`"
                    @mouseleave="active = null"
                >
                    <button
                        v-for="(day, index) in days"
                        :key="day.date"
                        type="button"
                        role="listitem"
                        class="focus-visible:ring-ring flex h-full min-w-0 flex-1 cursor-default flex-col justify-end gap-0.5 rounded-sm outline-none focus-visible:ring-2"
                        :class="active === index ? 'bg-muted/60' : ''"
                        :aria-label="describe(day)"
                        @mouseenter="active = index"
                        @focus="active = index"
                        @blur="active = null"
                    >
                        <template
                            v-for="item in [...series].reverse()"
                            :key="item.key"
                        >
                            <span
                                v-if="value(day, item.key) > 0"
                                class="block w-full first:rounded-t-sm"
                                :class="item.colorClass"
                                :style="{
                                    height: `${(value(day, item.key) / scaleTop) * 100}%`,
                                }"
                            />
                        </template>
                    </button>
                </div>

                <!-- Tooltip -->
                <div
                    v-if="activeDay !== null && active !== null"
                    class="bg-popover text-popover-foreground pointer-events-none absolute top-1 z-10 w-max max-w-56 rounded-md border px-3 py-2 text-xs shadow-md"
                    :style="{
                        left: `${((active + 0.5) / days.length) * 100}%`,
                        // Inside the plot, so a card's edge can never clip it; kept
                        // clear of the bar being read by sitting beside it.
                        transform: `translateX(${active < days.length / 2 ? '0.75rem' : 'calc(-100% - 0.75rem)'})`,
                    }"
                    role="status"
                >
                    <p class="font-medium">
                        {{ longDate.format(asDate(String(activeDay.date))) }}
                    </p>
                    <p
                        v-for="item in series"
                        :key="item.key"
                        class="text-muted-foreground mt-0.5 flex items-center gap-1.5"
                    >
                        <span
                            class="size-2 rounded-sm"
                            :class="item.colorClass"
                        />
                        {{ item.label }}
                        <span
                            class="text-foreground ml-auto pl-3 font-medium tabular-nums"
                            >{{ value(activeDay, item.key) }}</span
                        >
                    </p>
                    <p
                        v-if="series.length > 1"
                        class="mt-1 flex border-t pt-1 font-medium"
                    >
                        Total
                        <span class="ml-auto pl-3 tabular-nums">{{
                            total(activeDay)
                        }}</span>
                    </p>
                </div>

                <!-- Dates -->
                <div
                    class="text-muted-foreground relative mt-1 h-4 text-xs"
                    aria-hidden="true"
                >
                    <span
                        v-for="tick in ticks"
                        :key="tick.index"
                        class="absolute whitespace-nowrap"
                        :style="{
                            left: `${((tick.index + 0.5) / days.length) * 100}%`,
                            transform:
                                tick.index === 0
                                    ? 'translateX(-10%)'
                                    : tick.index === days.length - 1
                                      ? 'translateX(-90%)'
                                      : 'translateX(-50%)',
                        }"
                        >{{ tick.label }}</span
                    >
                </div>
            </div>
        </div>
    </div>
</template>
