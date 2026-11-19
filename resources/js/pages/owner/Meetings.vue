<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { CalendarClock, Clock, ExternalLink, MapPin } from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import BookMeetingDialog from '@/components/meetings/BookMeetingDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { appDateFormatter } from '@/lib/timezone';
import { dashboard } from '@/routes/owner';
import { index } from '@/routes/owner/meetings';
import type { MeetingType, UpcomingMeeting } from '@/types';

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
    /** Coresched's own departments page, offered as a fallback. */
    bookingUrl: string | null;
    /** The meetings staff approved for owners. Undefined while loading. */
    meetingTypes?: MeetingType[];
    /** This login's private channel, where booking changes are announced. */
    channel: string;
    /** Undefined while loading. */
    upcoming?: UpcomingMeeting[];
}>();

/**
 * Pick up a booking made in the other tab.
 *
 * Coresched's webhook tells the portal the moment a booking is made, moved
 * or cancelled, here or on coresched's own pages. Only the booked list is
 * refetched, so nothing else on the page flickers.
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

const booking = ref<MeetingType | null>(null);
const isBooking = ref(false);

function startBooking(meeting: MeetingType): void {
    booking.value = meeting;
    isBooking.value = true;
}

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

        <AppCard v-if="available" title="Book a meeting">
            <div
                v-if="meetingTypes === undefined"
                class="grid gap-3 sm:grid-cols-2"
                aria-busy="true"
            >
                <Skeleton v-for="row in 4" :key="row" class="h-20 w-full" />
            </div>

            <p
                v-else-if="meetingTypes.length === 0"
                class="text-muted-foreground text-sm"
            >
                There are no meetings to book right now. Please send us a
                message instead.
            </p>

            <ul v-else class="grid gap-3 sm:grid-cols-2">
                <li
                    v-for="meeting in meetingTypes"
                    :key="meeting.slug"
                    class="border-border flex items-center justify-between gap-3 rounded-xl border p-3"
                >
                    <span class="min-w-0">
                        <span class="block font-medium text-pretty">{{
                            meeting.name
                        }}</span>
                        <span
                            class="text-muted-foreground mt-0.5 flex flex-wrap gap-x-3 text-xs"
                        >
                            <span class="inline-flex items-center gap-1">
                                <Clock class="size-3" aria-hidden="true" />
                                {{ meeting.durationMinutes }} min
                            </span>
                            <span
                                v-if="meeting.locationLabel"
                                class="inline-flex items-center gap-1"
                            >
                                <MapPin class="size-3" aria-hidden="true" />
                                {{ meeting.locationLabel }}
                            </span>
                        </span>
                    </span>
                    <Button
                        size="sm"
                        class="shrink-0"
                        @click="startBooking(meeting)"
                    >
                        Book
                    </Button>
                </li>
            </ul>

            <p
                v-if="bookingUrl"
                class="text-muted-foreground mt-5 border-t pt-4 text-xs"
            >
                Prefer our scheduling page?
                <a
                    :href="bookingUrl"
                    target="_blank"
                    rel="noreferrer"
                    class="text-primary inline-flex items-center gap-1 font-medium underline-offset-4 hover:underline"
                >
                    Choose a department there
                    <ExternalLink class="size-3" aria-hidden="true" />
                </a>
            </p>
        </AppCard>

        <EmptyState
            v-else
            :icon="CalendarClock"
            title="Booking isn't switched on yet"
            description="Meetings will appear here once scheduling is connected."
        />

        <BookMeetingDialog v-model:open="isBooking" :meeting="booking" />

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
