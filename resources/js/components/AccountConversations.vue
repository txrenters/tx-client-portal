<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import ConversationList from '@/components/ConversationList.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import type { Conversation } from '@/types';

/**
 * Calls and emails our office logged against the client's record rather than
 * one of their properties; a property's own are on its page.
 */
defineProps<{
    /** Undefined while loading, null when it could not be loaded. */
    accountConversations?: {
        total: number;
        conversations: Conversation[];
    } | null;
    workOrderHref: (id: string) => NonNullable<InertiaLinkProps['href']>;
    /** The full list of the client's account conversations. */
    allHref: string;
}>();
</script>

<template>
    <section class="flex flex-col gap-3">
        <h2 class="text-base font-semibold">About your account</h2>

        <LoadingSkeleton v-if="accountConversations === undefined" :rows="2" />

        <ErrorState
            v-else-if="accountConversations === null"
            description="We couldn't load calls and emails about your account right now."
        />

        <p
            v-else-if="accountConversations.total === 0"
            class="text-muted-foreground text-sm"
        >
            Calls and emails our office logs about your account will appear
            here.
        </p>

        <template v-else>
            <ConversationList
                :conversations="accountConversations.conversations"
                :work-order-href="workOrderHref"
                :show-property="false"
            />
            <Link
                v-if="
                    accountConversations.total >
                    accountConversations.conversations.length
                "
                :href="allHref"
                class="text-primary self-start text-sm font-medium hover:underline"
            >
                View all {{ accountConversations.total }} conversations
            </Link>
        </template>
    </section>
</template>
