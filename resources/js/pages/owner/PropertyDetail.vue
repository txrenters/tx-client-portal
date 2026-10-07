<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText, MessageSquare, Wrench } from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import ConversationList from '@/components/ConversationList.vue';
import DetailList from '@/components/DetailList.vue';
import DocumentImportStatus from '@/components/DocumentImportStatus.vue';
import DocumentList from '@/components/DocumentList.vue';
import DocumentUploadDialog from '@/components/DocumentUploadDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import MaintenanceList from '@/components/MaintenanceList.vue';
import PropertyDetailSections from '@/components/PropertyDetailSections.vue';
import PropertyPhotos from '@/components/PropertyPhotos.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { dashboard, documents as documentsRoute } from '@/routes/owner';
import { download, preview, store } from '@/routes/owner/documents';
import {
    index as maintenanceIndex,
    show as maintenanceShow,
} from '@/routes/owner/maintenance';
import { index as conversationsRoute } from '@/routes/owner/conversations';
import { index as propertiesRoute } from '@/routes/owner/properties';
import type {
    Conversation,
    DocumentsImporting,
    DocumentsPage,
    MaintenancePage,
    PortalProperty,
    PropertyDetails,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Properties', href: propertiesRoute() },
        ],
    },
});

const props = defineProps<{
    property: PortalProperty;
    details: PropertyDetails | null;
    photos?: { url: string; description: string | null }[];
    maxUploadMegabytes: number;
    maintenance?: MaintenancePage;
    documents?: DocumentsPage | DocumentsImporting | null;
    conversations?: { total: number; conversations: Conversation[] } | null;
}>();

const { formatDate } = useFormattedDate();

const downloadUrl = (id: string): string => download.url(id);
const previewUrl = (id: string): string => preview.url(id);
const workOrderUrl = (id: string): string => maintenanceShow.url(id);
const allDocumentsUrl = documentsRoute.url({
    query: { property: props.property.id },
});
const allConversationsUrl = conversationsRoute.url({
    query: { property: props.property.id },
});
const allWorkOrdersUrl = maintenanceIndex.url({
    query: { property: props.property.fullAddress },
});

const facts = [
    { label: 'Status', value: props.property.status },
    {
        label: 'Occupancy',
        value: props.property.occupied ? 'Occupied' : 'Vacant',
    },
    { label: 'Type', value: props.property.type },
    { label: 'Category', value: props.property.category },
    { label: 'Bedrooms', value: props.property.bedrooms },
    { label: 'Bathrooms', value: props.property.bathrooms },
    { label: 'Area', value: props.property.area },
    { label: 'Year built', value: props.property.yearBuilt },
    { label: 'Neighborhood', value: props.property.neighborhood },
    { label: 'County', value: props.property.county },
    { label: 'Unit', value: props.property.unitName },
];
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head :title="property.address" />
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ property.address }}
            </h1>
            <p class="text-muted-foreground text-sm">
                {{
                    [property.city, property.state, property.postalCode]
                        .filter(Boolean)
                        .join(', ')
                }}
            </p>
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <PropertyPhotos :photos="photos" :alt="property.address" />

                <AppCard title="Property details">
                    <DetailList :items="facts" />
                </AppCard>

                <Tabs default-value="work-orders" class="min-w-0 gap-4">
                    <TabsList>
                        <TabsTrigger value="work-orders">
                            Work orders
                            <template v-if="maintenance?.workOrders">
                                ({{ maintenance.workOrders.total }})
                            </template>
                        </TabsTrigger>
                        <TabsTrigger value="documents">
                            Documents
                            <template v-if="documents?.status === 'ready'">
                                ({{ documents.results.total }})
                            </template>
                        </TabsTrigger>
                        <TabsTrigger value="conversations">
                            Conversation
                            <template v-if="conversations">
                                ({{ conversations.total }})
                            </template>
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="work-orders">
                        <AppCard>
                            <LoadingSkeleton v-if="!maintenance" :rows="2" />

                            <ErrorState
                                v-else-if="maintenance.status === 'error'"
                                description="We couldn't load work orders for this property right now."
                            />

                            <EmptyState
                                v-else-if="!maintenance.workOrders?.total"
                                :icon="Wrench"
                                title="No work orders"
                                description="There is no work order history for this property."
                            />

                            <div v-else class="flex flex-col gap-3">
                                <p class="text-muted-foreground text-xs">
                                    Latest
                                    {{ maintenance.workOrders.data.length }} of
                                    {{ maintenance.workOrders.total }}, most
                                    recently updated first
                                </p>
                                <MaintenanceList
                                    :work-orders="maintenance.workOrders.data"
                                    :href="maintenanceShow"
                                    :show-property="false"
                                />
                                <Link
                                    v-if="maintenance.workOrders.last_page > 1"
                                    :href="allWorkOrdersUrl"
                                    class="text-primary self-start text-sm font-medium hover:underline"
                                >
                                    View all
                                    {{ maintenance.workOrders.total }} work
                                    orders
                                </Link>
                            </div>
                        </AppCard>
                    </TabsContent>

                    <TabsContent value="documents">
                        <AppCard>
                            <div class="mb-4 flex justify-end">
                                <DocumentUploadDialog
                                    :action="store()"
                                    :max-megabytes="maxUploadMegabytes"
                                    :fixed-property-id="property.id"
                                />
                            </div>

                            <LoadingSkeleton
                                v-if="documents === undefined"
                                :rows="2"
                            />

                            <ErrorState
                                v-else-if="documents === null"
                                description="We couldn't load documents for this property right now."
                            />

                            <DocumentImportStatus
                                v-else-if="documents.status === 'importing'"
                                :only="['documents']"
                            />

                            <EmptyState
                                v-else-if="documents.results.total === 0"
                                :icon="FileText"
                                title="No documents"
                                description="Files shared for this property, its lease and its work orders will appear here."
                            />

                            <div v-else class="flex flex-col gap-4">
                                <DocumentList
                                    thumbnails
                                    :documents="documents.results.data"
                                    :download-url="downloadUrl"
                                    :preview-url="previewUrl"
                                    :work-order-url="workOrderUrl"
                                />
                                <Link
                                    v-if="documents.results.last_page > 1"
                                    :href="allDocumentsUrl"
                                    class="text-primary self-start text-sm font-medium hover:underline"
                                >
                                    View all
                                    {{ documents.results.total }} documents
                                </Link>
                            </div>
                        </AppCard>
                    </TabsContent>

                    <TabsContent value="conversations">
                        <AppCard>
                            <LoadingSkeleton
                                v-if="conversations === undefined"
                                :rows="2"
                            />

                            <ErrorState
                                v-else-if="conversations === null"
                                description="We couldn't load conversations for this property right now."
                            />

                            <EmptyState
                                v-else-if="conversations.total === 0"
                                :icon="MessageSquare"
                                title="No conversations"
                                description="Messages with TexasRenters.com about this property will appear here."
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
                                    View all
                                    {{ conversations.total }} conversations
                                </Link>
                            </div>
                        </AppCard>
                    </TabsContent>
                </Tabs>
            </div>

            <PropertyDetailSections
                v-if="details"
                :details="details"
                stacked
                hide-managers
            />
        </div>
    </div>
</template>
