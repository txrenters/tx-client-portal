<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import ListCard from '@/components/ListCard.vue';
import { Badge } from '@/components/ui/badge';
import type { PortalProperty } from '@/types';

const props = defineProps<{
    property: PortalProperty;
    href?: NonNullable<InertiaLinkProps['href']>;
}>();

const location = [
    props.property.city,
    props.property.state,
    props.property.postalCode,
]
    .filter(Boolean)
    .join(', ');

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});

/**
 * A calendar day such as 2027-07-31, read as that day everywhere: parsed as
 * a timestamp it would land on the 30th west of Greenwich.
 */
function calendarDay(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        'en-US',
        { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' },
    );
}

/** When the tenancy ends, the way the owner plans around it. */
const leaseLabel = (() => {
    const lease = props.property.lease;

    if (!lease) {
        return null;
    }

    if (lease.moveOutDate) {
        return `Moving out ${calendarDay(lease.moveOutDate)}`;
    }

    return lease.endDate
        ? `Lease ends ${calendarDay(lease.endDate)}`
        : 'Month to month';
})();
</script>

<template>
    <ListCard
        :title="property.address"
        :subtitle="location || null"
        :icon="Building2"
        :href="href"
    >
        <template v-if="property.status" #trailing>
            <Badge variant="outline">{{ property.status }}</Badge>
        </template>

        <template #meta>
            <span v-if="property.unitName">Unit {{ property.unitName }}</span>
            <span v-if="property.bedrooms">{{ property.bedrooms }} bed</span>
            <span v-if="property.bathrooms">{{ property.bathrooms }} bath</span>
            <span v-if="property.type">{{ property.type }}</span>
        </template>

        <div
            v-if="property.lease !== undefined"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span
                v-if="property.lease?.rent != null"
                class="text-primary font-semibold tabular-nums"
                >{{ money.format(property.lease.rent)
                }}<span class="text-muted-foreground text-xs font-normal">
                    / month</span
                ></span
            >
            <Badge :variant="property.occupied ? 'secondary' : 'outline'">{{
                property.occupied ? 'Occupied' : 'Vacant'
            }}</Badge>
            <Badge
                v-if="leaseLabel"
                :variant="property.lease?.endsSoon ? 'destructive' : 'outline'"
                >{{ leaseLabel }}</Badge
            >
        </div>
    </ListCard>
</template>
