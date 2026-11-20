<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { CalendarClock, Clock, ExternalLink, MapPin } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import BookMeetingFrame from '@/components/meetings/BookMeetingFrame.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
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
    /** The owner's number on file, filled into the booking form. */
    phone?: string | null;
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

/**
 * One colour per department, so its meetings read as a set. Departments are
 * sorted by name, so each keeps its colour from visit to visit; "Other
 * meetings" and the unheaded list stay plain (null).
 */
const DEPARTMENT_COLORS = [
    '#0284c7', // sky
    '#059669', // emerald
    '#d97706', // amber
    '#7c3aed', // violet
    '#e11d48', // rose
    '#0d9488', // teal
];

type Department = {
    name: string | null;
    color: string | null;
    meetings: MeetingType[];
};

/**
 * The approved meetings under their departments, as coresched's departments
 * page lists them. A meeting listed by two departments shows under both;
 * one listed by none goes under "Other meetings" at the end. When coresched
 * sends no departments at all, there is one group with no heading.
 */
const departments = computed<Department[]>(() => {
    const groups = new Map<string, MeetingType[]>();
    const other: MeetingType[] = [];

    for (const meeting of props.meetingTypes ?? []) {
        const names = meeting.departments ?? [];

        if (names.length === 0) {
            other.push(meeting);
        }

        for (const name of names) {
            groups.set(name, [...(groups.get(name) ?? []), meeting]);
        }
    }

    const named: Department[] = [...groups.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([name, meetings], index) => ({
            name,
            color: DEPARTMENT_COLORS[index % DEPARTMENT_COLORS.length],
            meetings,
        }));

    if (other.length > 0) {
        named.push({
            name: named.length > 0 ? 'Other meetings' : null,
            color: null,
            meetings: other,
        });
    }

    return named;
});

const booking = ref<MeetingType | null>(null);
const isBooking = ref(false);

function startBooking(meeting: MeetingType): void {
    booking.value = meeting;
    isBooking.value = true;
}

/**
 * The time now, moved on every minute, so a meeting that starts while the
 * page is open stops offering Change.
 */
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 60_000);
});
onUnmounted(() => clearInterval(clock));

/** A meeting whose start has passed can no longer be moved or cancelled. */
function hasStarted(meeting: UpcomingMeeting): boolean {
    return new Date(meeting.startsAt).getTime() <= now.value;
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

        <EmptyState
            v-if="!available"
            :icon="CalendarClock"
            title="Booking isn't switched on yet"
            description="Meetings will appear here once scheduling is connected."
        />

        <div v-else class="grid items-start gap-6 lg:grid-cols-3">
            <AppCard title="Book a meeting" class="lg:order-2">
                <LoadingSkeleton v-if="meetingTypes === undefined" :rows="4" />

                <p
                    v-else-if="meetingTypes.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    There are no meetings to book right now. Please send us a
                    message instead.
                </p>

                <div v-else class="flex flex-col gap-5">
                    <section
                        v-for="department in departments"
                        :key="department.name ?? ''"
                        class="flex flex-col gap-2"
                    >
                        <h3
                            v-if="department.name"
                            class="flex items-center gap-2 text-sm font-medium"
                        >
                            <span
                                v-if="department.color"
                                class="size-2 shrink-0 rounded-full"
                                :style="{ backgroundColor: department.color }"
                                aria-hidden="true"
                            />
                            {{ department.name }}
                        </h3>
                        <ul
                            class="bg-card divide-border divide-y overflow-hidden rounded-xl border"
                        >
                            <li
                                v-for="meeting in department.meetings"
                                :key="`${department.name}:${meeting.slug}`"
                                class="flex items-center gap-4 px-4 py-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-pretty">
                                        {{ meeting.name }}
                                    </p>
                                    <p
                                        class="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-3 text-xs"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1"
                                        >
                                            <Clock
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            {{ meeting.durationMinutes }} min
                                        </span>
                                        <span
                                            v-if="meeting.locationLabel"
                                            class="inline-flex items-center gap-1"
                                        >
                                            <MapPin
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            {{ meeting.locationLabel }}
                                        </span>
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    class="shrink-0"
                                    @click="startBooking(meeting)"
                                >
                                    Book
                                </Button>
                            </li>
                        </ul>
                    </section>
                </div>

                <template v-if="bookingUrl" #footer>
                    <p class="text-xs">
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
                </template>
            </AppCard>

            <AppCard title="Booked" class="lg:order-1 lg:col-span-2">
                <LoadingSkeleton v-if="upcoming === undefined" :rows="2" />

                <p
                    v-else-if="upcoming.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    Nothing booked yet. Meetings you book show up here.
                </p>

                <ul v-else class="divide-border divide-y text-sm">
                    <li v-for="meeting in upcoming" :key="meeting.uid">
                        <component
                            :is="hasStarted(meeting) ? 'div' : 'a'"
                            v-bind="
                                hasStarted(meeting)
                                    ? {}
                                    : {
                                          href: meeting.manageUrl,
                                          target: '_blank',
                                          rel: 'noreferrer',
                                      }
                            "
                            class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5"
                            :class="{
                                'hover:bg-muted/50 focus-visible:bg-muted/50 transition-colors focus-visible:outline-none':
                                    !hasStarted(meeting),
                            }"
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
                                v-if="!hasStarted(meeting)"
                                class="text-primary flex shrink-0 items-center gap-1 text-xs font-medium"
                            >
                                Change
                                <ExternalLink class="size-3.5" />
                            </span>
                        </component>
                    </li>
                </ul>
            </AppCard>
        </div>

        <BookMeetingFrame
            v-model:open="isBooking"
            :meeting="booking"
            :phone="phone"
        />
    </div>
</template>
