<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Download,
    Eye,
    FileText,
    MessageSquare,
    TrendingUp,
    Wrench,
} from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import RecentActivityCard from '@/components/RecentActivityCard.vue';
import DocumentImportStatus from '@/components/DocumentImportStatus.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import { useFormattedDate } from '@/composables/useFormattedDate';
import type {
    DashboardActivityEntry,
    DashboardDocuments,
    DashboardMessage,
} from '@/types';

/**
 * The cards under the fold: the client's latest messages and documents, and
 * the activity list that merges those with their work orders. Each feed is
 * undefined while it loads and null when its source could not be read.
 */
defineProps<{
    messagesHref: string;
    documentsHref: string;
    /** Owners only, while the financial summary is still a placeholder. */
    financialsHref?: string;
    /** Off where the page shows payments in the documents card's place. */
    showDocuments?: boolean;
    /** Off where messages are reached from the sidebar instead. */
    showMessages?: boolean;
    /** Off where the page places the activity card itself. */
    showActivity?: boolean;
    /** Where a message thread opens. */
    threadHref: (id: number) => string;
    /** Where a document opens, and where it downloads from. */
    previewHref: (id: string) => string;
    downloadHref: (id: string) => string;
    /** Owner totals for the Financials card. */
    financials?: {
        expenses: number;
        paid: number;
        outstanding: number;
        bills: number;
    } | null;
    messages?: DashboardMessage[] | null;
    documents?: DashboardDocuments | null;
    activity?: DashboardActivityEntry[] | null;
}>();

const { formatDate } = useFormattedDate();

const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
});

const activityIcon = {
    maintenance: Wrench,
    document: FileText,
    message: MessageSquare,
} as const;
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-2">
        <AppCard
            v-if="financialsHref"
            title="Financials"
            :href="financialsHref"
            link-label="View financials"
        >
            <LoadingSkeleton v-if="financials === undefined" :rows="1" />

            <ErrorState
                v-else-if="financials === null"
                description="We couldn't load your financials right now."
            />

            <EmptyState
                v-else-if="financials.bills === 0"
                :icon="TrendingUp"
                title="No bills in the last year"
                description="Work billed to your properties will appear here."
            />

            <div v-else class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <p class="text-3xl leading-none font-semibold">
                        {{ money.format(financials.expenses) }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        billed in the last year
                    </p>
                </div>
                <p class="text-muted-foreground text-sm">
                    {{ financials.bills }}
                    {{ financials.bills === 1 ? 'bill' : 'bills' }} ·
                    <span class="text-foreground font-medium">{{
                        money.format(financials.outstanding)
                    }}</span>
                    outstanding
                </p>
            </div>
        </AppCard>

        <AppCard
            v-if="showMessages !== false"
            title="Messages"
            :href="messagesHref"
            link-label="View messages"
        >
            <LoadingSkeleton v-if="messages === undefined" :rows="3" />

            <ErrorState
                v-else-if="messages === null"
                description="We couldn't load your messages right now."
            />

            <Link
                v-else-if="messages.length === 0"
                :href="messagesHref"
                class="hover:bg-accent/40 focus-visible:ring-ring block rounded-xl transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
                <EmptyState
                    :icon="MessageSquare"
                    title="You're all caught up"
                    description="Start a conversation with our office, or open your messages."
                />
            </Link>

            <ul v-else class="flex flex-col gap-2">
                <li v-for="thread in messages" :key="thread.id">
                    <Link
                        :href="threadHref(thread.id)"
                        class="border-border/60 bg-card/40 hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring block rounded-xl border p-3 wrap-anywhere transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                v-if="thread.unread"
                                class="bg-primary size-2 shrink-0 rounded-full"
                                aria-label="Unread"
                            />
                            <span class="min-w-0 flex-1 truncate font-medium">
                                {{ thread.subject }}
                            </span>
                            <span
                                class="text-muted-foreground shrink-0 text-xs whitespace-nowrap"
                            >
                                {{ formatDate(thread.at) }}
                            </span>
                        </div>
                        <p
                            class="text-muted-foreground mt-1 line-clamp-1 text-sm"
                        >
                            <span v-if="thread.author"
                                >{{ thread.author }}:
                            </span>
                            {{ thread.preview }}
                        </p>
                    </Link>
                </li>
            </ul>
        </AppCard>

        <AppCard
            v-if="showDocuments !== false"
            title="Documents"
            :href="documentsHref"
            link-label="View documents"
        >
            <LoadingSkeleton v-if="documents === undefined" :rows="3" />

            <ErrorState
                v-else-if="documents === null"
                description="We couldn't load your documents right now."
            />

            <DocumentImportStatus
                v-else-if="documents.status === 'importing'"
                :only="['documents']"
            />

            <Link
                v-else-if="documents.items.length === 0"
                :href="documentsHref"
                class="hover:bg-accent/40 focus-visible:ring-ring block rounded-xl transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
                <EmptyState
                    :icon="FileText"
                    title="No documents available"
                    description="Statements and property documents will appear here."
                />
            </Link>

            <ul v-else class="flex flex-col gap-2">
                <li v-for="document in documents.items" :key="document.id">
                    <a
                        :href="
                            document.viewable
                                ? previewHref(document.id)
                                : downloadHref(document.id)
                        "
                        :target="document.viewable ? '_blank' : undefined"
                        :rel="document.viewable ? 'noopener' : undefined"
                        :download="
                            document.viewable ? undefined : document.fileName
                        "
                        class="border-border/60 bg-card/40 hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring flex items-center gap-3 rounded-xl border p-3 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <span
                            class="bg-primary/10 text-primary flex size-8 shrink-0 items-center justify-center rounded-lg"
                        >
                            <component
                                :is="document.viewable ? Eye : Download"
                                class="size-4"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">
                                {{ document.description || document.fileName }}
                            </span>
                            <span class="text-muted-foreground block text-xs">
                                {{
                                    [
                                        document.category,
                                        formatDate(document.createdAt),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </span>
                        </span>
                    </a>
                </li>
            </ul>
        </AppCard>

        <RecentActivityCard
            v-if="showActivity !== false"
            :activity="activity"
        />
    </div>
</template>
