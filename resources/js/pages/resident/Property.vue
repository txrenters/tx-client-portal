<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Building2, FileText, MessageSquare } from '@lucide/vue';
import ConversationList from '@/components/ConversationList.vue';
import DetailList from '@/components/DetailList.vue';
import DocumentList from '@/components/DocumentList.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import PropertyCard from '@/components/PropertyCard.vue';
import PropertyDetailSections from '@/components/PropertyDetailSections.vue';
import PropertyPhotos from '@/components/PropertyPhotos.vue';
import AppCard from '@/components/AppCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard, property as propertyRoute } from '@/routes/resident';
import { index as conversationsRoute } from '@/routes/resident/conversations';
import { download, preview } from '@/routes/resident/documents';
import { show as maintenanceShow } from '@/routes/resident/maintenance';
import type {
    Conversation,
    PortalDocument,
    PortalProperty,
    PropertyDetails,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Property', href: propertyRoute() },
        ],
    },
});

const props = defineProps<{
    property?:
        | (PortalProperty & {
              details: PropertyDetails | null;
              photos: { url: string; description: string | null }[];
          })
        | null;
    documents?: PortalDocument[] | null;
    conversations?: {
        total: number;
        conversations: Conversation[];
    } | null;
}>();

const downloadUrl = (id: string): string => download.url(id);
const previewUrl = (id: string): string => preview.url(id);
const allConversationsUrl = conversationsRoute.url();

const facts = computed(() => [
    { label: 'Type', value: props.property?.type ?? null },
    { label: 'Bedrooms', value: props.property?.bedrooms ?? null },
    { label: 'Bathrooms', value: props.property?.bathrooms ?? null },
    { label: 'Area', value: props.property?.area ?? null },
    { label: 'Year built', value: props.property?.yearBuilt ?? null },
    { label: 'Neighborhood', value: props.property?.neighborhood ?? null },
    { label: 'County', value: props.property?.county ?? null },
    { label: 'Unit', value: props.property?.unitName ?? null },
]);
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Property" />
        <h1 class="text-2xl font-semibold tracking-tight">Your property</h1>

        <div v-if="property === undefined" class="flex flex-col gap-2">
            <Skeleton class="h-24 w-full rounded-xl" />
        </div>

        <template v-else-if="property">
            <PropertyCard :property="property" />

            <PropertyPhotos :photos="property.photos" :alt="property.address" />

            <AppCard title="Property details">
                <DetailList :items="facts" />
            </AppCard>

            <PropertyDetailSections
                v-if="property.details"
                :details="property.details"
            />

            <AppCard title="Conversations">
                <LoadingSkeleton v-if="conversations === undefined" :rows="2" />

                <ErrorState
                    v-else-if="conversations === null"
                    description="We couldn't load conversations about your home right now."
                />

                <EmptyState
                    v-else-if="conversations.total === 0"
                    :icon="MessageSquare"
                    title="No conversations"
                    description="Calls and emails with TexasRenters.com about your home will appear here."
                />

                <div v-else class="flex flex-col gap-3">
                    <ConversationList
                        :conversations="conversations.conversations"
                        :work-order-href="maintenanceShow"
                        :show-property="false"
                    />
                    <Link
                        v-if="
                            conversations.total >
                            conversations.conversations.length
                        "
                        :href="allConversationsUrl"
                        class="text-primary self-start text-sm font-medium hover:underline"
                    >
                        View all {{ conversations.total }} conversations
                    </Link>
                </div>
            </AppCard>

            <AppCard title="Documents">
                <LoadingSkeleton v-if="documents === undefined" :rows="2" />

                <EmptyState
                    v-else-if="!documents || documents.length === 0"
                    :icon="FileText"
                    title="No documents"
                    description="Files TexasRenters.com shares about your home will appear here."
                />

                <DocumentList
                    v-else
                    :documents="documents"
                    :download-url="downloadUrl"
                    :preview-url="previewUrl"
                />
            </AppCard>
        </template>

        <EmptyState
            v-else
            :icon="Building2"
            title="No property linked yet"
            description="Contact TexasRenters.com if your home should appear here."
        />
    </div>
</template>
