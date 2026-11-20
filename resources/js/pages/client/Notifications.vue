<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarCheck,
    CalendarClock,
    CircleCheck,
    MessageSquare,
    Wrench,
} from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import MaintenanceList from '@/components/MaintenanceList.vue';
import MaintenanceListSkeleton from '@/components/MaintenanceListSkeleton.vue';
import PageHeader from '@/components/PageHeader.vue';
import {
    index as maintenanceRoute,
    show as maintenanceShow,
} from '@/routes/owner/maintenance';
import { appDateFormatter } from '@/lib/timezone';
import { read as readNotice } from '@/routes/notifications';
import { index as ownerMessages } from '@/routes/owner/messages';
import { index as residentMessages } from '@/routes/resident/messages';
import type { ApprovalSummary, ChatThread, Notice } from '@/types';

const props = defineProps<{
    isOwner: boolean;
    portal: 'owner' | 'resident' | null;
    /** Undefined while loading, null when it failed. */
    approvals?: ApprovalSummary | null;
    messages?: { count: number; items: ChatThread[] } | null;
    notices: Notice[];
}>();

const messagesRoute =
    props.portal === 'resident' ? residentMessages : ownerMessages;

const timeFormatter = appDateFormatter({
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Notifications" />
        <PageHeader
            title="Notifications"
            description="Things that need your attention."
        />

        <AppCard
            v-if="notices.length > 0"
            title="Reminders"
            description="Leases ending soon and updates on your work orders."
        >
            <ul class="divide-border/60 divide-y">
                <li v-for="notice in notices" :key="notice.id">
                    <Link
                        :href="readNotice(notice.id)"
                        method="post"
                        as="button"
                        class="hover:bg-muted/50 flex w-full items-start gap-3 rounded-lg px-2 py-3 text-left transition-colors"
                    >
                        <component
                            :is="
                                notice.type === 'work_order_update'
                                    ? Wrench
                                    : notice.type === 'meeting_reminder'
                                      ? CalendarCheck
                                      : CalendarClock
                            "
                            class="text-primary mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span
                                class="flex items-baseline justify-between gap-2"
                            >
                                <span
                                    class="flex items-center gap-2 font-medium"
                                >
                                    <span
                                        v-if="!notice.readAt"
                                        class="bg-primary size-2 shrink-0 rounded-full"
                                        aria-label="Unread"
                                    />
                                    {{ notice.title }}
                                </span>
                                <span
                                    class="text-muted-foreground shrink-0 text-xs"
                                    >{{
                                        notice.createdAt
                                            ? timeFormatter.format(
                                                  new Date(notice.createdAt),
                                              )
                                            : ''
                                    }}</span
                                >
                            </span>
                            <span
                                class="text-muted-foreground text-sm whitespace-pre-line"
                                >{{ notice.message }}</span
                            >
                        </span>
                    </Link>
                </li>
            </ul>
        </AppCard>

        <AppCard
            :title="
                messages && messages.count > 0
                    ? `New replies (${messages.count})`
                    : 'New replies'
            "
            description="Replies from TexasRenters.com you haven't read yet."
            :href="messagesRoute()"
            link-label="All messages"
        >
            <MaintenanceListSkeleton v-if="messages === undefined" :rows="2" />

            <ErrorState
                v-else-if="messages === null"
                description="We couldn't check your messages right now."
            />

            <EmptyState
                v-else-if="messages.count === 0"
                :icon="CircleCheck"
                title="No new replies"
                description="We'll show new replies from our team here."
            />

            <ul v-else class="divide-border/60 divide-y">
                <li v-for="thread in messages.items" :key="thread.id">
                    <Link
                        :href="messagesRoute({ query: { thread: thread.id } })"
                        class="hover:bg-muted/50 flex items-start gap-3 rounded-lg px-2 py-3 transition-colors"
                    >
                        <MessageSquare
                            class="text-primary mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span
                                class="flex items-baseline justify-between gap-2"
                            >
                                <span class="truncate font-medium">{{
                                    thread.subject
                                }}</span>
                                <span
                                    class="text-muted-foreground shrink-0 text-xs"
                                    >{{
                                        thread.updatedAt
                                            ? timeFormatter.format(
                                                  new Date(thread.updatedAt),
                                              )
                                            : ''
                                    }}</span
                                >
                            </span>
                            <span
                                class="text-muted-foreground line-clamp-2 text-sm"
                            >
                                {{ thread.lastMessage?.author }}:
                                {{ thread.lastMessage?.preview }}
                            </span>
                        </span>
                    </Link>
                </li>
            </ul>
        </AppCard>

        <AppCard
            v-if="isOwner"
            :title="
                approvals
                    ? `Work orders needing your approval (${approvals.count})`
                    : 'Work orders needing your approval'
            "
            description="Open a work order to approve or decline it."
            :href="
                approvals && approvals.count > approvals.items.length
                    ? maintenanceRoute({ query: { approval: 'needed' } })
                    : undefined
            "
            link-label="View all"
        >
            <MaintenanceListSkeleton v-if="approvals === undefined" :rows="3" />

            <ErrorState
                v-else-if="approvals === null"
                description="We couldn't check for work orders right now."
            />

            <EmptyState
                v-else-if="approvals.count === 0"
                :icon="CircleCheck"
                title="Nothing waiting for your approval"
                description="We'll let you know here when a work order needs your OK."
            />

            <MaintenanceList
                v-else
                :work-orders="approvals.items"
                :href="maintenanceShow"
                :show-property="true"
            />
        </AppCard>
    </div>
</template>
