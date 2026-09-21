<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { useFormattedDate } from '@/composables/useFormattedDate';
import type { WorkOrder, WorkOrderStatus } from '@/types';

/**
 * Work orders as one list of quiet rows: the title, a single line of
 * details, and the status as a small colored dot with its label.
 */
const props = withDefaults(
    defineProps<{
        workOrders: WorkOrder[];
        href: (id: string) => NonNullable<InertiaLinkProps['href']>;
        showProperty?: boolean;
    }>(),
    {
        showProperty: true,
    },
);

const { formatDate } = useFormattedDate();

const dots: Record<WorkOrderStatus, string> = {
    open: 'bg-primary',
    scheduled: 'bg-primary/40',
    completed: 'bg-success',
    canceled: 'bg-muted-foreground/40',
};

const labels: Record<WorkOrderStatus, string> = {
    open: 'text-foreground',
    scheduled: 'text-foreground',
    completed: 'text-muted-foreground',
    canceled: 'text-muted-foreground',
};

function details(workOrder: WorkOrder): string {
    const when =
        workOrder.status === 'scheduled' && workOrder.scheduledFor
            ? `Scheduled ${formatDate(workOrder.scheduledFor)}`
            : workOrder.updatedAt
              ? `Updated ${formatDate(workOrder.updatedAt)}`
              : null;

    return [
        workOrder.number ? `#${workOrder.number}` : null,
        workOrder.category,
        props.showProperty ? workOrder.property : null,
        when,
    ]
        .filter(Boolean)
        .join(' · ');
}
</script>

<template>
    <ul
        class="bg-card divide-border divide-y overflow-hidden rounded-xl border"
    >
        <li v-for="workOrder in workOrders" :key="workOrder.id">
            <Link
                :href="href(workOrder.id)"
                class="hover:bg-muted/50 focus-visible:bg-muted/50 flex items-start gap-4 px-4 py-3 transition-colors focus-visible:outline-none"
            >
                <div class="min-w-0 flex-1">
                    <p class="flex min-w-0 items-center gap-2">
                        <span class="truncate text-sm font-medium">{{
                            workOrder.title
                        }}</span>
                        <span
                            v-if="workOrder.awaitingApproval"
                            class="bg-primary/10 text-primary shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                            >Needs your approval</span
                        >
                    </p>
                    <p class="text-muted-foreground mt-0.5 truncate text-xs">
                        {{ details(workOrder) }}
                    </p>
                </div>
                <span
                    class="flex shrink-0 items-center gap-1.5 pt-0.5 text-xs"
                    :class="labels[workOrder.status]"
                >
                    <span
                        class="size-1.5 rounded-full"
                        :class="dots[workOrder.status]"
                        aria-hidden="true"
                    />
                    {{ workOrder.statusLabel }}
                </span>
            </Link>
        </li>
    </ul>
</template>
