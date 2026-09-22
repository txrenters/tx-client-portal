<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { CalendarClock, ExternalLink } from '@lucide/vue';
import { computed, onMounted, onUnmounted } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
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

/** How often the booked list is re-read while the page is open. */
const POLL_MS = 30_000;

const props = defineProps<{
    available: boolean;
    /** Undefined while loading. */
    eventTypes?: MeetingType[];
    upcoming?: UpcomingMeeting[];
}>();

/**
 * Pick up a booking made in the other tab.
 *
 * Booking happens on scheduling's own page, so this tab has no way of knowing
 * it happened and would otherwise sit there stale until someone thought to
 * reload. Only the booked list is refetched, so the meeting cards never
 * flicker.
 *
 * Polling covers the case of the two windows side by side. `rest` keeps the
 * requests from stacking up: each one goes on to the scheduling app, so a
 * slow answer should delay the next tick rather than overlap it.
 */
usePoll(
    POLL_MS,
    { only: ['upcoming'] },
    { autoStart: props.available, mode: 'rest' },
);

/**
 * Coming back to the tab is the moment someone wants an answer, and waiting
 * out the rest of the interval is the stale page all over again, so that case
 * asks straight away rather than waiting for the next tick.
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

/**
 * What a meeting is about, from its name.
 *
 * Scheduling names meetings after the team that takes them — "Leasing Team
 * Meeting" — so the portal marks them with the same three categories it uses
 * for messages, each carrying that category's colour. Anything it cannot
 * place falls back to the brand gradient rather than being guessed into the
 * wrong team.
 */
const CATEGORIES = [
    {
        match: /leasing|lease|renewal|move.?in|move.?out/i,
        label: 'Leasing',
        blurb: 'Renewals, move-ins and move-outs.',
        wash: 'from-category-leasing to-category-leasing-deep',
    },
    {
        match: /maintenance|repair|work order/i,
        label: 'Maintenance',
        blurb: 'Repairs and work order questions.',
        wash: 'from-category-maintenance to-category-maintenance-deep',
    },
    {
        match: /account|statement|payment|financial/i,
        label: 'Accounting',
        blurb: 'Statements, rent, fees and payments.',
        wash: 'from-category-accounting to-category-accounting-deep',
    },
] as const;

/**
 * The card's shape, in one place.
 *
 * The placeholder below wears these same classes: a skeleton that is not laid
 * out like the thing it stands in for makes the page jump when the meetings
 * arrive, which is the one job it has.
 */
const CARD = 'flex flex-col gap-2 rounded-2xl p-5 sm:p-6';

/** How to meet, when the name does not spell it out. */
const FALLBACK_FORMAT: Record<string, string> = {
    phone: 'Phone call',
    in_person: 'In person',
};

/**
 * Staff offer the same conversation two ways — "Leasing Team Meeting - Phone
 * Call" and "- Screen Share" — and listing those as separate cards makes the
 * page twice as long while hiding what is actually being chosen. One card per
 * subject, with the ways to meet as the buttons on it.
 */
const subjects = computed(() => {
    const grouped = new Map<
        string,
        {
            name: string;
            label: string;
            blurb: string | null;
            wash: string;
            durations: number[];
            ways: { label: string; url: string }[];
        }
    >();

    for (const type of props.eventTypes ?? []) {
        const [name, ...rest] = type.name.split(/\s+[–-]\s+/);
        const subject = name.trim();
        const category = CATEGORIES.find((entry) =>
            entry.match.test(type.name),
        );

        const row = grouped.get(subject) ?? {
            name: subject,
            label: category?.label ?? 'General',
            blurb: category?.blurb ?? type.description,
            wash: category?.wash ?? 'from-ring to-primary',
            durations: [],
            ways: [],
        };

        row.durations.push(type.durationMinutes);
        row.ways.push({
            label:
                rest.join(' - ').trim() ||
                FALLBACK_FORMAT[type.locationType] ||
                'Book',
            url: type.url,
        });

        grouped.set(subject, row);
    }

    return [...grouped.values()].map((row) => ({
        ...row,
        length:
            Math.min(...row.durations) === Math.max(...row.durations)
                ? `${row.durations[0]} minutes`
                : `${Math.min(...row.durations)} or ${Math.max(...row.durations)} minutes`,
    }));
});
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Meetings" />

        <PageHeader
            title="Meetings"
            description="Pick a subject and a time that suits you. Booking opens on our scheduling page."
        />

        <EmptyState
            v-if="!available"
            :icon="CalendarClock"
            title="Booking isn't switched on yet"
            description="Meetings will appear here once scheduling is connected."
        />

        <template v-else>
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

            <div
                v-if="eventTypes === undefined"
                class="grid gap-4 sm:grid-cols-2"
                aria-busy="true"
            >
                <div
                    v-for="card in 4"
                    :key="card"
                    class="bg-muted/40"
                    :class="CARD"
                >
                    <Skeleton class="h-4 w-20" />
                    <Skeleton class="h-7 w-52" />
                    <Skeleton class="h-5 w-full max-w-64" />
                    <Skeleton class="h-5 w-28" />
                    <div class="mt-auto flex gap-2 pt-4">
                        <Skeleton class="h-8 w-28 rounded-lg" />
                        <Skeleton class="h-8 w-32 rounded-lg" />
                    </div>
                </div>
            </div>

            <EmptyState
                v-else-if="subjects.length === 0"
                :icon="CalendarClock"
                title="No meetings on offer"
                description="Nobody has published a meeting type yet."
            />

            <ul v-else class="grid gap-4 sm:grid-cols-2">
                <li
                    v-for="subject in subjects"
                    :key="subject.name"
                    class="text-category-foreground bg-gradient-to-br"
                    :class="[CARD, subject.wash]"
                >
                    <!-- The category is named as well as coloured. -->
                    <p
                        class="text-xs font-medium tracking-wide uppercase opacity-80"
                    >
                        {{ subject.label }}
                    </p>

                    <h2 class="font-heading text-xl font-semibold text-balance">
                        {{ subject.name }}
                    </h2>

                    <p
                        v-if="subject.blurb"
                        class="text-sm text-pretty opacity-90"
                    >
                        {{ subject.blurb }}
                    </p>

                    <p class="text-sm opacity-75">{{ subject.length }}</p>

                    <div class="mt-auto flex flex-wrap gap-2 pt-4">
                        <a
                            v-for="way in subject.ways"
                            :key="way.url"
                            :href="way.url"
                            target="_blank"
                            rel="noreferrer"
                            class="bg-category-foreground/15 hover:bg-category-foreground/25 focus-visible:ring-category-foreground/60 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        >
                            {{ way.label }}
                        </a>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>
