<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import MonthlyMoneyChart from '@/components/MonthlyMoneyChart.vue';
import AppCard from '@/components/AppCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { financials as ledgerRoute } from '@/routes/owner';
import type { OwnerDashboardLedger } from '@/types';

const props = defineProps<{
    /** Undefined while loading, null when it could not be read. */
    ledger?: OwnerDashboardLedger | null;
}>();

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
});
</script>

<template>
    <AppCard
        title="Your money"
        description="Income and expense for the last 12 months."
        :href="ledgerRoute()"
        link-label="Open my ledger"
    >
        <!--
            The skeleton stands in for what actually arrives: three totals
            across the top and a full width chart under them. A placeholder
            shaped like something else just moves the page when it resolves.
        -->
        <div v-if="ledger === undefined" class="flex flex-col gap-4">
            <div
                class="border-border grid grid-cols-3 gap-px overflow-hidden rounded-lg border sm:max-w-lg"
            >
                <div
                    v-for="tile in 3"
                    :key="tile"
                    class="bg-card flex flex-col gap-2 px-4 py-3"
                >
                    <Skeleton class="h-3 w-16" />
                    <Skeleton class="h-6 w-24" />
                </div>
            </div>
            <Skeleton class="h-72 w-full" />
        </div>

        <p v-else-if="ledger === null" class="text-muted-foreground text-sm">
            We could not read your ledger just now.
        </p>

        <div v-else class="flex flex-col gap-6">
            <p
                v-if="ledger.fromInception && ledger.portfolioMinimum > 0"
                class="text-muted-foreground text-sm text-pretty"
            >
                Your available balance is what is left after the
                {{ money.format(ledger.portfolioMinimum) }} kept back for this
                portfolio<span v-if="ledger.outstanding > 0">
                    and {{ money.format(ledger.outstanding) }} still owed on
                    bills</span
                >.
            </p>

            <MonthlyMoneyChart
                v-if="ledger.months.length > 0"
                :months="ledger.months"
            />

            <p v-else class="text-muted-foreground text-sm">
                No money has moved on this portfolio yet.
            </p>

            <p class="text-muted-foreground text-sm">
                <Link :href="ledgerRoute()" class="text-primary hover:underline"
                    >See every transaction</Link
                >
                in your ledger.
            </p>
        </div>
    </AppCard>
</template>
