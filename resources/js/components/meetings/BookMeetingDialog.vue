<script setup lang="ts">
import { useForm, useHttp, usePage } from '@inertiajs/vue3';
import { CalendarX2, Clock, MapPin } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { APP_TIME_ZONE, appDateFormatter } from '@/lib/timezone';
import { slots as slotsRoute, store } from '@/routes/owner/meetings';
import type { MeetingType } from '@/types';

/**
 * Book one approved meeting without leaving the portal: who it is for (filled
 * in from the account, and the owner may change it), a day, a time and an
 * optional note. The booking is made in coresched, which emails the
 * invitation and keeps the calendar; times show in Houston time, like the
 * rest of the portal.
 */
const props = defineProps<{ meeting: MeetingType | null }>();
const open = defineModel<boolean>('open', { required: true });

type Slot = { startsAt: string; endsAt: string };

/**
 * How many days ahead to ask coresched about: the most one request may cover.
 * Coresched trims it to each meeting's own booking window, notice and hours.
 */
const DAYS_AHEAD = 31;

const http = useHttp();
const slots = ref<Slot[] | null>(null);
const loadFailed = ref(false);
const selectedDay = ref<string | null>(null);
/** The first day asked about: today, in Houston, when the times were loaded. */
const firstDay = ref('');

const page = usePage();

const form = useForm({
    event_type: '',
    name: '',
    email: '',
    starts_at: '',
    notes: '',
    timezone: APP_TIME_ZONE,
});

const dayKey = new Intl.DateTimeFormat('en-CA', { timeZone: APP_TIME_ZONE });
const dayLabel = appDateFormatter({
    weekday: 'short',
    month: 'short',
    day: 'numeric',
});
const timeLabel = appDateFormatter({ hour: 'numeric', minute: '2-digit' });
/** A time of day in Houston, such as "13:30", to line the same time up across days. */
const timeKey = new Intl.DateTimeFormat('en-GB', {
    timeZone: APP_TIME_ZONE,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

function addDays(day: string, count: number): string {
    const date = new Date(`${day}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + count);

    return date.toISOString().slice(0, 10);
}

async function loadSlots(): Promise<void> {
    if (!props.meeting) {
        return;
    }

    slots.value = null;
    loadFailed.value = false;
    const from = dayKey.format(new Date());
    firstDay.value = from;

    try {
        const response = (await http.submit(
            slotsRoute(props.meeting.slug, {
                query: { from, to: addDays(from, DAYS_AHEAD - 1) },
            }),
        )) as { data: Slot[] };

        slots.value = response.data;
        selectedDay.value =
            days.value.find((day) => day.times.length > 0)?.key ?? null;
    } catch {
        loadFailed.value = true;
    }
}

/**
 * Every day from today to the last one coresched has a time on, so a day
 * with nothing open (a weekend, a holiday, fully booked) shows but can't be
 * picked, as on a calendar.
 */
const days = computed(() => {
    const byDay = new Map<string, Slot[]>();

    for (const slot of slots.value ?? []) {
        const key = dayKey.format(new Date(slot.startsAt));
        byDay.set(key, [...(byDay.get(key) ?? []), slot]);
    }

    const lastDay = [...byDay.keys()].sort().at(-1);

    if (!lastDay || !firstDay.value) {
        return [];
    }

    const all = [];

    for (let key = firstDay.value; key <= lastDay; key = addDays(key, 1)) {
        all.push({
            key,
            label: dayLabel.format(new Date(`${key}T12:00:00Z`)),
            times: byDay.get(key) ?? [],
        });
    }

    return all;
});

/**
 * Every time this meeting is offered on any day, earliest first. On the day
 * picked, the ones not open there (booked, past, or outside that day's hours)
 * show but can't be picked.
 */
const timeGrid = computed(() => {
    const grid = new Map<string, string>();

    for (const slot of slots.value ?? []) {
        const at = new Date(slot.startsAt);
        grid.set(timeKey.format(at), timeLabel.format(at));
    }

    return [...grid.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([key, label]) => ({ key, label }));
});

const timesForDay = computed(() => {
    const open = new Map(
        (
            days.value.find((day) => day.key === selectedDay.value)?.times ?? []
        ).map((slot) => [timeKey.format(new Date(slot.startsAt)), slot]),
    );

    return timeGrid.value.map((time) => ({
        ...time,
        slot: open.get(time.key) ?? null,
    }));
});

watch(open, (isOpen) => {
    if (isOpen && props.meeting) {
        form.reset();
        form.clearErrors();
        form.event_type = props.meeting.slug;
        form.name = page.props.auth.user?.name ?? '';
        form.email = page.props.auth.user?.email ?? '';
        void loadSlots();
    }
});

function book(): void {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onError: (errors) => {
            // Someone took the time first: show what is still open.
            if (errors.starts_at) {
                form.starts_at = '';
                void loadSlots();
            }
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="meeting" class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ meeting.name }}</DialogTitle>
                <DialogDescription class="flex flex-wrap gap-x-4 gap-y-1">
                    <span class="inline-flex items-center gap-1.5">
                        <Clock class="size-3.5" aria-hidden="true" />
                        {{ meeting.durationMinutes }} minutes
                    </span>
                    <span
                        v-if="meeting.locationLabel"
                        class="inline-flex items-center gap-1.5"
                    >
                        <MapPin class="size-3.5" aria-hidden="true" />
                        {{ meeting.locationLabel }}
                    </span>
                </DialogDescription>
            </DialogHeader>

            <div v-if="slots === null && !loadFailed" class="grid gap-3">
                <Skeleton class="h-9 w-full" />
                <Skeleton class="h-24 w-full" />
            </div>

            <p
                v-else-if="loadFailed"
                class="text-muted-foreground text-sm"
                role="alert"
            >
                We couldn't load the open times. Please try again in a moment.
            </p>

            <div
                v-else-if="days.length === 0"
                class="text-muted-foreground flex items-center gap-2 text-sm"
            >
                <CalendarX2 class="size-4" aria-hidden="true" />
                No open times in the next {{ DAYS_AHEAD }} days. Please check
                back soon or send us a message.
            </div>

            <form v-else class="grid gap-4" @submit.prevent="book">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="meeting-name">Name</Label>
                        <Input
                            id="meeting-name"
                            v-model="form.name"
                            autocomplete="name"
                            maxlength="255"
                            required
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-destructive text-sm"
                            role="alert"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="meeting-email">Email</Label>
                        <Input
                            id="meeting-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            maxlength="255"
                            required
                        />
                        <p
                            v-if="form.errors.email"
                            class="text-destructive text-sm"
                            role="alert"
                        >
                            {{ form.errors.email }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Day</Label>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="day in days"
                            :key="day.key"
                            type="button"
                            size="sm"
                            :disabled="day.times.length === 0"
                            :variant="
                                day.key === selectedDay ? 'default' : 'outline'
                            "
                            class="shrink-0"
                            :aria-pressed="day.key === selectedDay"
                            @click="
                                selectedDay = day.key;
                                form.starts_at = '';
                            "
                        >
                            {{ day.label }}
                        </Button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Time (Houston)</Label>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <Button
                            v-for="time in timesForDay"
                            :key="time.key"
                            type="button"
                            size="sm"
                            :disabled="time.slot === null"
                            :variant="
                                time.slot &&
                                time.slot.startsAt === form.starts_at
                                    ? 'default'
                                    : 'outline'
                            "
                            :aria-pressed="
                                time.slot !== null &&
                                time.slot.startsAt === form.starts_at
                            "
                            @click="form.starts_at = time.slot?.startsAt ?? ''"
                        >
                            {{ time.label }}
                        </Button>
                    </div>
                    <p
                        v-if="form.errors.starts_at"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ form.errors.starts_at }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="meeting-notes">
                        Anything we should know?
                        <span class="text-muted-foreground font-normal"
                            >(optional)</span
                        >
                    </Label>
                    <Textarea
                        id="meeting-notes"
                        v-model="form.notes"
                        rows="3"
                        maxlength="2000"
                        placeholder="What would you like to talk about?"
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            form.starts_at === '' ||
                            form.name.trim() === '' ||
                            form.email.trim() === ''
                        "
                    >
                        <Spinner v-if="form.processing" />
                        Book meeting
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
