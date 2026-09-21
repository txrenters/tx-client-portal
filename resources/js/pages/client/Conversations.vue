<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MessageSquare, Search, SearchX } from '@lucide/vue';
import { computed } from 'vue';
import ConversationList from '@/components/ConversationList.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useServerFilters } from '@/composables/useServerFilters';
import { dashboard as ownerDashboard } from '@/routes/owner';
import { index as ownerConversations } from '@/routes/owner/conversations';
import { show as ownerWorkOrder } from '@/routes/owner/maintenance';
import { dashboard as residentDashboard } from '@/routes/resident';
import { index as residentConversations } from '@/routes/resident/conversations';
import { show as residentWorkOrder } from '@/routes/resident/maintenance';
import type { ConversationFilterValues, ConversationsPage } from '@/types';

/** The header beside the sidebar's collapse button, for whichever portal this is. */
defineOptions({
    layout: ({ portal }: { portal: 'owner' | 'resident' }) => ({
        breadcrumbs: [
            {
                title: 'Home',
                href:
                    portal === 'owner' ? ownerDashboard() : residentDashboard(),
            },
            {
                title: 'Conversations',
                href:
                    portal === 'owner'
                        ? ownerConversations()
                        : residentConversations(),
            },
        ],
    }),
});

const props = defineProps<{
    portal: 'owner' | 'resident';
    filters: ConversationFilterValues;
    /** Undefined while loading, null when it failed. */
    conversations?: ConversationsPage | null;
}>();

const isOwner = props.portal === 'owner';

const { filters, isLoading, isFiltered, clearFilters, goToPage } =
    useServerFilters({
        url: isOwner ? ownerConversations.url() : residentConversations.url(),
        only: ['conversations', 'filters'],
        initial: props.filters,
        searchKey: 'search',
    });

const workOrderHref = isOwner ? ownerWorkOrder : residentWorkOrder;

const showPropertyFilter = computed(
    () => (props.conversations?.properties.length ?? 0) > 1,
);
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Conversations" />
        <PageHeader
            title="Conversations"
            :description="
                isOwner
                    ? 'Conversations from Propertyware about your account and properties.'
                    : 'Conversations from Propertyware about your home and account.'
            "
        />

        <div
            v-if="conversations === undefined"
            class="flex flex-col gap-4"
            role="status"
            aria-label="Loading conversations"
        >
            <div class="flex flex-col gap-2 sm:flex-row">
                <Skeleton class="h-9 w-full sm:w-72" />
                <Skeleton class="h-9 w-full sm:w-56" />
            </div>
            <Skeleton
                v-for="item in 5"
                :key="item"
                class="h-20 w-full rounded-2xl"
            />
        </div>

        <ErrorState
            v-else-if="conversations === null"
            description="We couldn't load your conversations right now."
        />

        <EmptyState
            v-else-if="conversations.total === 0"
            :icon="MessageSquare"
            title="No conversations yet"
            description="Conversations with TexasRenters.com will appear here."
        />

        <div v-else class="flex flex-col gap-4">
            <div
                class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center"
            >
                <div class="relative w-full sm:w-72">
                    <Search
                        class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="filters.search"
                        type="search"
                        class="pl-9"
                        placeholder="Search conversations"
                        aria-label="Search conversations"
                    />
                </div>
                <Select v-if="showPropertyFilter" v-model="filters.property">
                    <SelectTrigger
                        class="w-full sm:w-64"
                        aria-label="Filter by property"
                        ><SelectValue placeholder="Everything"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Everything</SelectItem>
                        <SelectItem value="account">Your account</SelectItem>
                        <SelectItem
                            v-for="item in conversations.properties"
                            :key="item.id"
                            :value="String(item.id)"
                            >{{ item.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <Button v-if="isFiltered" variant="ghost" @click="clearFilters"
                    >Clear filters</Button
                >
            </div>

            <p class="text-muted-foreground text-xs" role="status">
                {{ conversations.results.total }} of
                {{ conversations.total }} conversations
            </p>

            <EmptyState
                v-if="conversations.results.total === 0"
                :icon="SearchX"
                title="No matching conversations"
                description="Try another search or clear your filters."
            />

            <div
                v-else
                class="flex flex-col gap-4 transition-opacity"
                :class="{ 'pointer-events-none opacity-60': isLoading }"
                :aria-busy="isLoading"
            >
                <ConversationList
                    :conversations="conversations.results.data"
                    :work-order-href="workOrderHref"
                />
                <ResultsPagination
                    :results="conversations.results"
                    @change="goToPage"
                />
            </div>
        </div>
    </div>
</template>
