<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3';
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { Bell, CircleCheck, MessageSquare, Wrench } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import {
    index as notifications,
    latest as latestNotifications,
} from '@/routes/notifications';
import { show as maintenanceShow } from '@/routes/owner/maintenance';
import { index as ownerMessages } from '@/routes/owner/messages';
import { index as residentMessages } from '@/routes/resident/messages';
import type { ChatThread, WorkOrder } from '@/types';

type Section<T> = { count: number; items: T[] } | null;

type Latest = {
    count: number;
    portal: 'owner' | 'resident' | null;
    /** Private channel that announces new replies for the account in view. */
    channel: string | null;
    approvals: Section<WorkOrder>;
    messages: Section<ChatThread>;
};

/**
 * The header bell: a badge with how many things need the client's attention
 * (unread replies, and for owners, work orders waiting for approval) and a
 * dropdown listing the first few. It loads after the page, refreshes when a
 * reply arrives over Reverb, when it's opened, and after the client changes
 * something; otherwise at most once a minute.
 */
const latest = ref<Latest | null>(null);
const http = useHttp({});
let fetchedAt = 0;
let listeningOn: string | null = null;

function refresh(force = false): void {
    if (http.processing || (!force && Date.now() - fetchedAt < 60_000)) {
        return;
    }

    fetchedAt = Date.now();
    http.get(latestNotifications.url(), {
        onSuccess: (response) => {
            latest.value = response as Latest;
        },
    });
}

/** Follow the channel of the account in view, so a new reply updates the badge at once. */
watch(
    () => latest.value?.channel ?? null,
    (channel) => {
        if (!echoIsConfigured() || channel === listeningOn) {
            return;
        }

        if (listeningOn) {
            echo().leave(listeningOn);
        }

        listeningOn = channel;

        if (channel) {
            echo()
                .private(channel)
                .listen('.conversation.updated', () => refresh(true));
        }
    },
);

const stopListening = router.on('finish', (event) =>
    refresh(event.detail.visit.method !== 'get'),
);

onMounted(() => refresh(true));
onBeforeUnmount(() => {
    stopListening();

    if (listeningOn && echoIsConfigured()) {
        echo().leave(listeningOn);
    }
});

function opened(open: boolean): void {
    if (open) {
        refresh(true);
    }
}

function messageHref(thread: ChatThread): string {
    const route =
        latest.value?.portal === 'resident' ? residentMessages : ownerMessages;

    return route.url({ query: { thread: thread.id } });
}

const count = (): number => latest.value?.count ?? 0;
</script>

<template>
    <DropdownMenu @update:open="opened">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="
                    count() > 0
                        ? `Notifications, ${count()} need attention`
                        : 'Notifications'
                "
            >
                <Bell class="size-5" />
                <span
                    v-if="count() > 0"
                    class="bg-primary text-primary-foreground absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] leading-none font-semibold tabular-nums"
                    aria-hidden="true"
                    >{{ count() > 99 ? '99+' : count() }}</span
                >
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-80 max-w-[calc(100vw-2rem)]">
            <DropdownMenuLabel>Notifications</DropdownMenuLabel>
            <DropdownMenuSeparator />

            <div v-if="latest === null" class="flex flex-col gap-2 p-2">
                <Skeleton class="h-10 w-full" />
                <Skeleton class="h-10 w-full" />
            </div>

            <div
                v-else-if="count() === 0"
                class="text-muted-foreground flex flex-col items-center gap-2 px-3 py-6 text-center text-sm"
            >
                <CircleCheck class="size-5" />
                You're all caught up.
            </div>

            <template v-else>
                <template v-if="latest.messages && latest.messages.count > 0">
                    <DropdownMenuLabel
                        class="text-muted-foreground text-xs font-normal"
                        >New replies ({{
                            latest.messages.count
                        }})</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="thread in latest.messages.items"
                        :key="`message-${thread.id}`"
                        as-child
                    >
                        <Link
                            :href="messageHref(thread)"
                            class="flex cursor-pointer items-start gap-3"
                        >
                            <MessageSquare
                                class="text-primary mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="flex min-w-0 flex-col">
                                <span class="truncate font-medium">{{
                                    thread.subject
                                }}</span>
                                <span
                                    class="text-muted-foreground truncate text-xs"
                                >
                                    {{ thread.lastMessage?.author }}:
                                    {{ thread.lastMessage?.preview }}
                                </span>
                            </span>
                        </Link>
                    </DropdownMenuItem>
                </template>

                <template v-if="latest.approvals && latest.approvals.count > 0">
                    <DropdownMenuSeparator
                        v-if="latest.messages && latest.messages.count > 0"
                    />
                    <DropdownMenuLabel
                        class="text-muted-foreground text-xs font-normal"
                        >Need your approval ({{
                            latest.approvals.count
                        }})</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="workOrder in latest.approvals.items"
                        :key="`approval-${workOrder.id}`"
                        as-child
                    >
                        <Link
                            :href="maintenanceShow(workOrder.id)"
                            class="flex cursor-pointer items-start gap-3"
                        >
                            <Wrench
                                class="text-primary mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="flex min-w-0 flex-col">
                                <span class="font-medium">
                                    Approval needed
                                    <template v-if="workOrder.number"
                                        >· #{{ workOrder.number }}</template
                                    >
                                </span>
                                <span
                                    class="text-muted-foreground truncate text-xs"
                                >
                                    {{
                                        [workOrder.category, workOrder.property]
                                            .filter(Boolean)
                                            .join(' · ') || workOrder.title
                                    }}
                                </span>
                            </span>
                        </Link>
                    </DropdownMenuItem>
                </template>
            </template>

            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link
                    :href="notifications()"
                    class="text-primary cursor-pointer justify-center font-medium"
                >
                    View all notifications
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
