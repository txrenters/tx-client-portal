<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { OwnerRevenue } from '@/types';

/**
 * What each property brought in, as one bar per property: money received
 * against the rent billed on it. The bars are read left to right against a
 * shared scale, and every figure is written beside its bar, so the chart is
 * never the only way to read a number.
 */
const props = defineProps<{
    revenue: OwnerRevenue;
}>();

/** How many properties a portfolio of any size opens with. */
const SHOWN = 10;

const expanded = ref(false);

// A new set of properties is read from the top again.
watch(
    () => props.revenue.properties,
    () => (expanded.value = false),
);

const shown = computed(() =>
    expanded.value
        ? props.revenue.properties
        : props.revenue.properties.slice(0, SHOWN),
);

const hidden = computed(() => props.revenue.properties.length - SHOWN);

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

/** The scale every bar shares: the largest of anything billed or received. */
const ceiling = computed(() =>
    Math.max(
        ...props.revenue.properties.flatMap((property) => [
            property.billed,
            property.received,
        ]),
        1,
    ),
);

const width = (amount: number): string =>
    `${Math.max((amount / ceiling.value) * 100, amount > 0 ? 1.5 : 0)}%`;
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            class="text-muted-foreground flex flex-wrap items-center gap-4 text-xs"
        >
            <span class="flex items-center gap-1.5">
                <span
                    class="bg-chart-1 size-2.5 rounded-full"
                    aria-hidden="true"
                />
                Received
            </span>
            <span class="flex items-center gap-1.5">
                <span
                    class="bg-muted border-border size-2.5 rounded-full border"
                    aria-hidden="true"
                />
                Billed
            </span>
        </div>

        <ul class="flex flex-col gap-4">
            <li
                v-for="property in shown"
                :key="property.id"
                class="flex flex-col gap-1.5"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <span class="min-w-0 truncate text-sm font-medium">{{
                        property.name
                    }}</span>
                    <span class="shrink-0 text-sm font-medium tabular-nums">{{
                        money.format(property.received)
                    }}</span>
                </div>

                <div class="flex flex-col gap-1">
                    <!-- Received sits on top of what was billed, same scale. -->
                    <div
                        class="bg-muted h-2.5 w-full overflow-hidden rounded-full"
                        role="img"
                        :aria-label="`${property.name}: ${money.format(property.received)} received of ${money.format(property.billed)} billed`"
                    >
                        <div
                            class="bg-chart-1 h-full rounded-full"
                            :style="{ width: width(property.received) }"
                        />
                    </div>
                    <div
                        class="text-muted-foreground flex justify-between text-xs"
                    >
                        <span
                            >{{ money.format(property.billed) }} billed ·
                            {{ property.leases }}
                            {{
                                property.leases === 1 ? 'lease' : 'leases'
                            }}</span
                        >
                        <span
                            v-if="property.billed - property.received > 0.5"
                            class="text-destructive"
                            >{{
                                money.format(
                                    property.billed - property.received,
                                )
                            }}
                            outstanding</span
                        >
                        <span
                            v-else-if="
                                property.received - property.billed > 0.5
                            "
                            >{{
                                money.format(
                                    property.received - property.billed,
                                )
                            }}
                            paid ahead</span
                        >
                    </div>
                </div>
            </li>
        </ul>

        <!-- An owner with ninety properties reads the top of the list first. -->
        <Button
            v-if="hidden > 0"
            variant="outline"
            class="self-start"
            @click="expanded = !expanded"
        >
            {{
                expanded
                    ? 'Show top 10'
                    : `Show all ${revenue.properties.length} properties`
            }}
        </Button>
    </div>
</template>
