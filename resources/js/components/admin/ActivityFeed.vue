<script setup lang="ts">
import {
    Building2,
    CircleCheck,
    MailCheck,
    Smartphone,
    UserPlus,
    Wrench,
} from '@lucide/vue';
import { Skeleton } from '@/components/ui/skeleton';
import { appDateFormatter } from '@/lib/timezone';
import type { AdminActivityEvent } from '@/types';

withDefaults(
    defineProps<{
        /** Undefined while the feed is still being read. */
        events?: AdminActivityEvent[];
        limit?: number;
    }>(),
    { limit: 100 },
);

const icons: Record<string, unknown> = {
    signup: UserPlus,
    verified: MailCheck,
    account_linked: Building2,
    invitation_accepted: CircleCheck,
    work_order_approved: Wrench,
    work_order_declined: Wrench,
    app_session: Smartphone,
};

const when = appDateFormatter({
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});
</script>

<template>
    <div v-if="events === undefined" class="flex flex-col gap-3">
        <div v-for="row in 6" :key="row" class="flex items-center gap-3">
            <Skeleton class="size-8 shrink-0 rounded-md" />
            <div class="flex-1 space-y-1.5">
                <Skeleton class="h-4 w-48" />
                <Skeleton class="h-3 w-28" />
            </div>
        </div>
    </div>

    <p v-else-if="events.length === 0" class="text-muted-foreground text-sm">
        Nothing has happened in the portal yet.
    </p>

    <ul v-else class="flex flex-col gap-3">
        <li
            v-for="(event, index) in events.slice(0, limit)"
            :key="`${event.type}-${event.at}-${index}`"
            class="flex items-start gap-3"
        >
            <span
                class="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-md"
            >
                <component
                    :is="icons[event.type] ?? CircleCheck"
                    class="size-4"
                />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">{{ event.summary }}</p>
                <p class="text-muted-foreground text-xs">
                    <span v-if="event.actor">{{ event.actor }} · </span>
                    {{ when.format(new Date(event.at)) }}
                </p>
            </div>
        </li>
    </ul>
</template>
