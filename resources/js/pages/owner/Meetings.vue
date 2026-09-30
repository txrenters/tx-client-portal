<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { CalendarClock, ExternalLink } from '@lucide/vue';
import { onMounted, onUnmounted } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { appDateFormatter } from '@/lib/timezone';
import { dashboard } from '@/routes/owner';
import { index } from '@/routes/owner/meetings';
import type { UpcomingMeeting } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Meetings', href: index() },
        ],
    },
});

const props = defineProps<{
    available: boolean;
    /** tx-scheduling's departments page, where the meeting is chosen and booked. */
    bookingUrl: string | null;
    /** This login's private channel, where booking changes are announced. */
    channel: string;
    /** Undefined while loading. */
    upcoming?: UpcomingMeeting[];
}>();

/**
 * Pick up a booking made in the other tab.
 *
 * Booking happens on scheduling's own page, and scheduling's webhook tells
 * the portal the moment one is booked, moved or cancelled. Only the booked
 * list is refetched, so nothing else on the page flickers.
 */
if (props.available) {
    useEcho(props.channel, '.meetings.updated', () =>
        router.reload({ only: ['upcoming'] }),
    );
}

/**
 * Coming back to the tab also asks, in case an announcement was missed while
 * the connection was down.
 */
function refreshBooked(): void {
    if (document.visibilityState !== 'visible' || !props.available) {
        return;
    }

    router.reload({ only: ['upcoming'] });
}

onMounted(() => document.addEventListener('visibilitychange', refreshBooked));
onUnmounted(() =>
    document.removeEventListener('visibilitychange', refreshBooked),
);

const when = appDateFormatter({
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Meetings" />

        <PageHeader
            title="Meetings"
            description="Meet with our leasing, maintenance or accounting team."
        />

        <AppCard v-if="bookingUrl">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-start gap-3">
                    <CalendarClock class="text-primary mt-0.5 size-5 shrink-0" />
                    <div class="flex flex-col gap-1">
                        <h2 class="font-heading font-semibold">
                            Book a meeting
                        </h2>
                        <p class="text-muted-foreground text-sm text-pretty">
                            Choose a department, how you'd like to meet and a
                            time that suits you. Booking opens on our scheduling
                            page.
                        </p>
                    </div>
                </div>
                <Button as-child class="shrink-0">
                    <a :href="bookingUrl" target="_blank" rel="noreferrer">
                        Choose a department
                        <ExternalLink />
                    </a>
                </Button>
            </div>
        </AppCard>

        <EmptyState
            v-else
            :icon="CalendarClock"
            title="Booking isn't switched on yet"
            description="Meetings will appear here once scheduling is connected."
        />

        <template v-if="available">
            <AppCard
                v-if="upcoming === undefined || upcoming.length > 0"
                title="Booked"
            >
                <ul
                    v-if="upcoming === undefined"
                    class="divide-border divide-y"
                    aria-busy="true"
                >
                    <li
                        v-for="row in 2"
                        :key="row"
                        class="flex items-center justify-between gap-3 py-2.5"
                    >
                        <span class="flex flex-col gap-1.5">
                            <Skeleton class="h-5 w-48" />
                            <Skeleton class="h-4 w-36" />
                        </span>
                        <Skeleton class="h-4 w-16" />
                    </li>
                </ul>

                <ul v-else class="divide-border divide-y text-sm">
                    <li v-for="meeting in upcoming" :key="meeting.uid">
                        <a
                            :href="meeting.manageUrl"
                            target="_blank"
                            rel="noreferrer"
                            class="hover:bg-muted/50 focus-visible:bg-muted/50 -mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 transition-colors focus-visible:outline-none"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{
                                    meeting.name
                                }}</span>
                                <span
                                    class="text-muted-foreground block truncate text-xs"
                                >
                                    {{ when.format(new Date(meeting.startsAt))
                                    }}<template v-if="meeting.locationLabel">
                                        ·
                                        {{ meeting.locationLabel }}</template
                                    >
                                </span>
                            </span>
                            <span
                                class="text-primary flex shrink-0 items-center gap-1 text-xs font-medium"
                            >
                                Change
                                <ExternalLink class="size-3.5" />
                            </span>
                        </a>
                    </li>
                </ul>
            </AppCard>

        </template>
    </div>
</template>
