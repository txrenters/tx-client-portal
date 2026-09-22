<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Clock, FileText, MessageSquare, Wrench } from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormattedDate } from '@/composables/useFormattedDate';
import type { DashboardActivityEntry } from '@/types';

/**
 * What has happened on the account lately.
 *
 * Its own component so a dashboard can place it where it likes rather than
 * wherever the feeds column happens to put it.
 */
defineProps<{
    /** Undefined while loading, null when it couldn't be read. */
    activity?: DashboardActivityEntry[] | null;
}>();

const { formatDate } = useFormattedDate();

const activityIcon = {
    maintenance: Wrench,
    document: FileText,
    message: MessageSquare,
} as const;
</script>

<template>
    <AppCard title="Recent activity">
        <div
            v-if="activity === undefined"
            class="flex flex-col gap-3"
            role="status"
            aria-label="Loading recent activity"
        >
            <div v-for="row in 4" :key="row" class="flex items-center gap-3">
                <Skeleton class="size-8 shrink-0 rounded-lg" />
                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <Skeleton class="h-3.5 w-2/3" />
                    <Skeleton class="h-3 w-1/3" />
                </div>
            </div>
        </div>

        <ErrorState
            v-else-if="activity === null"
            description="We couldn't load recent activity right now."
        />

        <EmptyState
            v-else-if="activity.length === 0"
            :icon="Clock"
            title="Nothing to show yet"
            description="Work order updates, statements, and new messages will appear here."
        />

        <ul v-else class="flex flex-col">
            <li
                v-for="(entry, position) in activity"
                :key="`${entry.type}-${position}`"
                class="border-border/60 border-b last:border-0"
            >
                <Link
                    :href="entry.url"
                    class="hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring -mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    <span
                        class="bg-primary/10 text-primary flex size-8 shrink-0 items-center justify-center rounded-lg"
                    >
                        <component
                            :is="activityIcon[entry.type]"
                            class="size-4"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">
                            {{ entry.title }}
                        </span>
                        <span
                            v-if="entry.detail"
                            class="text-muted-foreground block truncate text-xs"
                        >
                            {{ entry.detail }}
                        </span>
                    </span>
                    <span
                        class="text-muted-foreground shrink-0 text-xs whitespace-nowrap"
                    >
                        {{ formatDate(entry.at) }}
                    </span>
                </Link>
            </li>
        </ul>
    </AppCard>
</template>
