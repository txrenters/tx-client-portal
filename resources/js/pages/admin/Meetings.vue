<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarClock, ExternalLink, Save } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { dashboard as adminHome } from '@/routes/admin';
import { index as meetingsRoute, update } from '@/routes/admin/meetings';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Meetings', href: meetingsRoute() },
        ],
    },
});

type MeetingType = {
    slug: string;
    name: string;
    description: string | null;
    durationMinutes: number | null;
    locationLabel: string | null;
    url: string | null;
    allowed: boolean;
};

const props = defineProps<{
    /** Undefined while the scheduling app is being asked. */
    meetingTypes?: { types: MeetingType[]; error: string | null };
    allowedSlugs: string[];
}>();

/** The slugs ticked on screen, saved together with one button. */
const chosen = ref<string[]>([...props.allowedSlugs]);

watch(
    () => props.allowedSlugs,
    (slugs) => (chosen.value = [...slugs]),
);

function toggle(slug: string, on: boolean): void {
    chosen.value = on
        ? [...chosen.value, slug]
        : chosen.value.filter((item) => item !== slug);
}

const changed = computed(
    () =>
        [...chosen.value].sort().join('|') !==
        [...props.allowedSlugs].sort().join('|'),
);

/** Approved types that no longer exist in scheduling. */
const missing = computed(() =>
    props.meetingTypes
        ? props.allowedSlugs.filter(
              (slug) =>
                  !props.meetingTypes!.types.some((type) => type.slug === slug),
          )
        : [],
);

const saving = ref(false);

function save(): void {
    router.put(
        update().url,
        { slugs: chosen.value },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Meetings" />

        <PageHeader
            title="Meetings"
            description="Which of the scheduling app's meeting types owners can see and book on the portal and in the app. Anything new created in scheduling stays hidden until it is switched on here."
        >
            <template #actions>
                <Button size="sm" :disabled="!changed || saving" @click="save">
                    <Spinner v-if="saving" />
                    <Save v-else class="size-4" />
                    Save
                </Button>
            </template>
        </PageHeader>

        <AppCard>
            <div
                v-if="meetingTypes === undefined"
                class="flex flex-col gap-3"
                role="status"
                aria-label="Loading meeting types"
            >
                <Skeleton v-for="row in 4" :key="row" class="h-14 w-full" />
            </div>

            <p v-else-if="meetingTypes.error" class="text-destructive text-sm">
                The scheduling app could not be reached:
                {{ meetingTypes.error }}
            </p>

            <EmptyState
                v-else-if="meetingTypes.types.length === 0"
                :icon="CalendarClock"
                title="No meeting types"
                description="Nothing has been set up in the scheduling app yet."
            />

            <ul v-else class="divide-border/60 divide-y">
                <li
                    v-for="type in meetingTypes.types"
                    :key="type.slug"
                    class="flex items-center justify-between gap-4 py-3"
                >
                    <div class="min-w-0">
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            {{ type.name }}
                            <Badge
                                v-if="chosen.includes(type.slug)"
                                variant="secondary"
                                >Clients see it</Badge
                            >
                            <Badge v-else variant="outline">Hidden</Badge>
                        </p>
                        <p class="text-muted-foreground text-sm">
                            <span v-if="type.durationMinutes"
                                >{{ type.durationMinutes }} min</span
                            >
                            <span v-if="type.locationLabel">
                                · {{ type.locationLabel }}</span
                            >
                            <span class="font-mono text-xs">
                                · {{ type.slug }}</span
                            >
                        </p>
                        <a
                            v-if="type.url"
                            :href="type.url"
                            target="_blank"
                            rel="noopener"
                            class="text-primary inline-flex items-center gap-1 text-xs hover:underline"
                        >
                            Open booking page
                            <ExternalLink class="size-3" />
                        </a>
                    </div>
                    <Switch
                        :model-value="chosen.includes(type.slug)"
                        :aria-label="`Show ${type.name} to clients`"
                        @update:model-value="toggle(type.slug, $event)"
                    />
                </li>
            </ul>

            <p
                v-if="missing.length"
                class="text-muted-foreground mt-4 text-xs text-pretty"
            >
                Approved but no longer in scheduling, so not shown:
                <span class="font-mono">{{ missing.join(', ') }}</span
                >.
            </p>
        </AppCard>
    </div>
</template>
