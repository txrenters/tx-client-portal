<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import DocumentBrowser from '@/components/DocumentBrowser.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import DocumentBrowserSkeleton from '@/components/DocumentBrowserSkeleton.vue';
import DocumentImportStatus from '@/components/DocumentImportStatus.vue';
import DocumentUploadDialog from '@/components/DocumentUploadDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { dashboard, documents as documentsRoute } from '@/routes/resident';
import { download, preview, store } from '@/routes/resident/documents';
import { show as maintenanceShow } from '@/routes/resident/maintenance';
import type {
    DocumentFilterValues,
    DocumentsImporting,
    DocumentsPage,
    UploadTargets,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Home', href: dashboard() },
            { title: 'Documents', href: documentsRoute() },
        ],
    },
});

defineProps<{
    filters: DocumentFilterValues;
    documents?: DocumentsPage | DocumentsImporting | null;
    uploadTargets?: UploadTargets | null;
    maxUploadMegabytes: number;
}>();

const downloadUrl = (id: string): string => download.url(id);
const previewUrl = (id: string): string => preview.url(id);
const workOrderUrl = (id: string): string => maintenanceShow.url(id);
const thumbnailUrl = (id: string): string =>
    preview.url(id, { query: { thumbnail: true } });
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Documents" />
        <PageHeader
            title="Documents"
            description="Files TexasRenters.com has shared with you."
        >
            <template #actions>
                <DocumentUploadDialog
                    :action="store()"
                    :max-megabytes="maxUploadMegabytes"
                    :targets="uploadTargets"
                />
            </template>
        </PageHeader>

        <DocumentBrowserSkeleton v-if="documents === undefined" />

        <ErrorState
            v-else-if="documents === null"
            description="We couldn't load your documents right now."
        />

        <DocumentImportStatus
            v-else-if="documents.status === 'importing'"
            :only="['documents']"
        />

        <EmptyState
            v-else-if="documents.total === 0"
            :icon="FileText"
            title="No documents available"
            description="Documents TexasRenters.com shares with you will appear here."
        />

        <DocumentBrowser
            v-else
            :page="documents"
            :filters="filters"
            :url="documentsRoute.url()"
            :download-url="downloadUrl"
            :preview-url="previewUrl"
            :thumbnail-url="thumbnailUrl"
            :work-order-url="workOrderUrl"
        />
    </div>
</template>
