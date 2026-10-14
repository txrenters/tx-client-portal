<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import DetailList from '@/components/DetailList.vue';
import DocumentList from '@/components/DocumentList.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingSkeleton from '@/components/LoadingSkeleton.vue';
import VendorList from '@/components/VendorList.vue';
import MaintenanceStatusBadge from '@/components/MaintenanceStatusBadge.vue';
import AppCard from '@/components/AppCard.vue';
import ConversationList from '@/components/ConversationList.vue';
import EarlierTexts from '@/components/EarlierTexts.vue';
import CollapsibleCard from '@/components/CollapsibleCard.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { dashboard } from '@/routes/resident';
import { index as maintenanceRoute } from '@/routes/resident/maintenance';
import { FileText, MessagesSquare } from '@lucide/vue';
import {
    download as downloadDocument,
    preview as previewDocument,
} from '@/routes/resident/maintenance/documents';
import type {
    ChatConversation,
    Conversation,
    PortalDocument,
    WorkOrderDetail,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Work Orders', href: maintenanceRoute() },
        ],
    },
});

const props = defineProps<{
    workOrder: WorkOrderDetail;
    documents?: PortalDocument[] | null;
    /** Undefined while loading, null when it failed. */
    conversations?: Conversation[] | null;
    /** Texts about this work order from before the messaging hub. */
    earlierTexts?: ChatConversation | null;
}>();

const downloadUrl = (id: string): string =>
    downloadDocument.url({ workOrder: props.workOrder.id, document: id });

const previewUrl = (id: string): string =>
    previewDocument.url({ workOrder: props.workOrder.id, document: id });

const thumbnailUrl = (id: string): string =>
    previewDocument.url(
        { workOrder: props.workOrder.id, document: id },
        { query: { thumbnail: true } },
    );

const { formatDate } = useFormattedDate();

const details = computed(() => [
    { label: 'Work order #', value: props.workOrder.number },
    { label: 'Status', value: props.workOrder.statusLabel },
    { label: 'Priority', value: props.workOrder.priority },
    { label: 'Type', value: props.workOrder.type },
    { label: 'Category', value: props.workOrder.category },
    { label: 'Property', value: props.workOrder.location },
    { label: 'Area', value: props.workOrder.specificLocation },
    { label: 'Lease', value: props.workOrder.leaseName },
    { label: 'Managed by', value: props.workOrder.managedBy },
    { label: 'Source', value: props.workOrder.source },
    { label: 'Days open', value: props.workOrder.ageInDays },
    { label: 'Approved', value: props.workOrder.approved ? 'Yes' : 'No' },
    { label: 'Entry permitted', value: props.workOrder.authorizedToEnter },
    {
        label: 'Entry date',
        value: formatDate(props.workOrder.entryDate),
    },
    { label: 'Contract #', value: props.workOrder.contractNumber },
    { label: 'Materials', value: props.workOrder.requiredMaterials },
]);

const costs = computed(() => [
    { label: 'Estimate', value: formatMoney(props.workOrder.costEstimate) },
    { label: 'Hours estimated', value: props.workOrder.hourEstimate },
    { label: 'Actual cost', value: formatMoney(props.workOrder.actualCost) },
    { label: 'Your cost', value: formatMoney(props.workOrder.ownerCost) },
]);

const formatMoney = (value: number | null): string | null =>
    value === null || value === 0
        ? null
        : new Intl.NumberFormat(undefined, {
              style: 'currency',
              currency: 'USD',
          }).format(value);

const filled = (items: { value: string | number | null }[]): number =>
    items.filter((item) => item.value !== null && item.value !== '').length;

const heading = props.workOrder.number
    ? `Work order #${props.workOrder.number}`
    : 'Work order';
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head :title="heading" />
        <header class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ heading }}
                </h1>
                <MaintenanceStatusBadge
                    :status="workOrder.status"
                    :label="workOrder.statusLabel"
                />
            </div>
            <p v-if="workOrder.property" class="text-muted-foreground text-sm">
                {{ workOrder.property }}
            </p>
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <AppCard title="Work order details">
                    <div class="flex flex-col gap-4">
                        <p class="text-sm whitespace-pre-line">
                            {{ workOrder.description }}
                        </p>
                        <DetailList :items="details" />
                    </div>
                </AppCard>

                <Tabs default-value="attachments" class="min-w-0 gap-4">
                    <TabsList>
                        <TabsTrigger value="attachments">
                            Attachments
                            <template v-if="documents">
                                ({{ documents.length }})
                            </template>
                        </TabsTrigger>
                        <TabsTrigger value="conversations">
                            Conversations
                            <template v-if="conversations">
                                ({{ conversations.length }})
                            </template>
                        </TabsTrigger>
                        <TabsTrigger v-if="earlierTexts" value="texts">
                            Texts ({{ earlierTexts.messages.length }})
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="attachments">
                        <AppCard>
                            <LoadingSkeleton
                                v-if="documents === undefined"
                                :rows="2"
                            />

                            <ErrorState
                                v-else-if="documents === null"
                                description="We couldn't load the files for this work order right now."
                            />

                            <EmptyState
                                v-else-if="documents.length === 0"
                                :icon="FileText"
                                title="No attachments"
                                description="Photos and files added to this work order will appear here."
                            />

                            <DocumentList
                                v-else
                                thumbnails
                                :documents="documents"
                                :download-url="downloadUrl"
                                :preview-url="previewUrl"
                                :thumbnail-url="thumbnailUrl"
                            />
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
                                description="We couldn't load conversations for this work order right now."
                            />

                            <EmptyState
                                v-else-if="conversations.length === 0"
                                :icon="MessagesSquare"
                                title="No conversations"
                                description="Conversations that mention this work order will appear here."
                            />

                            <ConversationList
                                v-else
                                :conversations="conversations"
                                :show-property="false"
                            />
                        </AppCard>
                    </TabsContent>

                    <TabsContent v-if="earlierTexts" value="texts">
                        <AppCard
                            title="Earlier texts"
                            description="Texts about this work order from before our new messaging."
                        >
                            <EarlierTexts :messages="earlierTexts.messages" />
                        </AppCard>
                    </TabsContent>
                </Tabs>
            </div>

            <div
                v-if="workOrder.vendors.length > 0"
                class="grid items-start gap-4"
            >
                <CollapsibleCard
                    v-if="workOrder.vendors.length > 0"
                    title="Assigned to"
                    :count="workOrder.vendors.length"
                >
                    <VendorList :vendors="workOrder.vendors" />
                </CollapsibleCard>
            </div>
        </div>
    </div>
</template>
