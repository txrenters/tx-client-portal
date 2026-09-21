<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Download, Eye, FileText } from '@lucide/vue';
import { ref } from 'vue';
import DocumentViewer from '@/components/DocumentViewer.vue';
import ListCard from '@/components/ListCard.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { formatInAppZone } from '@/lib/timezone';
import type { PortalDocument } from '@/types';

defineProps<{
    documents: PortalDocument[];
    downloadUrl: (id: string) => string;
    previewUrl: (id: string) => string;
    thumbnailUrl?: (id: string) => string;
    thumbnails?: boolean;
    /** Links a work order attachment to its work order. */
    workOrderUrl?: (id: string) => string;
    /** Owners hold several properties, so their tiles name the property. */
    showProperty?: boolean;
}>();

const viewing = ref<PortalDocument | null>(null);
const viewerOpen = ref(false);
const failedPreviews = ref<Set<string>>(new Set());

/**
 * PDFs and images open in the viewer; anything else can only be downloaded.
 */
const view = (document: PortalDocument): void => {
    viewing.value = document;
    viewerOpen.value = true;
};

const { formatDate } = useFormattedDate();
const formatTimestamp = (value: string): string =>
    formatInAppZone(value, { dateStyle: 'medium', timeStyle: 'short' }) ||
    'Date unavailable';
</script>

<template>
    <div>
        <ul
            :class="
                thumbnails
                    ? 'grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-3'
                    : 'flex flex-col gap-3'
            "
        >
            <li v-for="document in documents" :key="document.id">
                <Card v-if="thumbnails" class="h-full gap-0 py-0">
                    <Button
                        variant="ghost"
                        class="bg-muted relative aspect-[3/2] h-auto w-full overflow-hidden rounded-none p-0"
                        :disabled="!document.viewable"
                        :aria-label="`Preview ${document.fileName}`"
                        @click="view(document)"
                    >
                        <img
                            v-if="
                                document.viewable &&
                                document.fileType?.startsWith('image/') &&
                                !failedPreviews.has(document.id)
                            "
                            :src="(thumbnailUrl ?? previewUrl)(document.id)"
                            :alt="document.fileName"
                            loading="lazy"
                            class="h-full w-full object-cover"
                            @error="failedPreviews.add(document.id)"
                        />
                        <div
                            v-else
                            class="text-muted-foreground flex flex-col items-center gap-1 p-2"
                        >
                            <FileText class="size-6" />
                            <span class="text-xs">{{
                                failedPreviews.has(document.id)
                                    ? 'Preview unavailable'
                                    : document.fileType === 'application/pdf'
                                      ? 'PDF'
                                      : 'File'
                            }}</span>
                        </div>
                    </Button>
                    <div class="flex flex-1 flex-col gap-1 p-2">
                        <p
                            class="line-clamp-2 text-xs font-medium break-all"
                            :title="document.fileName"
                        >
                            {{ document.fileName }}
                        </p>
                        <Link
                            v-if="document.workOrderId && workOrderUrl"
                            :href="workOrderUrl(document.workOrderId)"
                            class="text-primary self-start text-[11px] font-medium hover:underline"
                        >
                            WO #{{
                                document.workOrderNumber ?? document.workOrderId
                            }}
                        </Link>
                        <p
                            v-if="showProperty && document.propertyName"
                            class="text-muted-foreground line-clamp-1 text-[11px]"
                            :title="document.propertyName"
                        >
                            {{ document.propertyName }}
                        </p>
                        <time
                            v-if="document.createdAt"
                            :datetime="document.createdAt"
                            class="text-muted-foreground text-[10px] leading-relaxed"
                            >{{ formatTimestamp(document.createdAt) }}</time
                        >
                        <span v-else class="text-muted-foreground text-[10px]"
                            >Date unavailable</span
                        >
                        <div
                            class="mt-auto flex flex-wrap items-center gap-1 pt-1"
                        >
                            <Button
                                v-if="document.viewable"
                                variant="ghost"
                                size="sm"
                                class="size-7 p-0"
                                :aria-label="`View ${document.fileName}`"
                                title="View"
                                @click="view(document)"
                            >
                                <Eye class="size-3.5" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="size-7 p-0"
                                title="Download"
                                as-child
                            >
                                <a
                                    :href="downloadUrl(document.id)"
                                    :download="document.fileName"
                                    :aria-label="`Download ${document.fileName}`"
                                >
                                    <Download class="size-3.5" />
                                </a>
                            </Button>
                        </div>
                    </div>
                </Card>
                <ListCard
                    v-else
                    :title="document.fileName"
                    :subtitle="document.description"
                    :icon="FileText"
                >
                    <template #trailing>
                        <div class="flex items-center gap-1">
                            <Button
                                v-if="document.viewable"
                                variant="ghost"
                                size="sm"
                                @click="view(document)"
                            >
                                <Eye class="size-4" />
                                <span class="sr-only sm:not-sr-only">View</span>
                            </Button>
                            <Button variant="ghost" size="sm" as-child>
                                <a
                                    :href="downloadUrl(document.id)"
                                    :download="document.fileName"
                                >
                                    <Download class="size-4" />
                                    <span class="sr-only sm:not-sr-only"
                                        >Download</span
                                    >
                                </a>
                            </Button>
                        </div>
                    </template>

                    <template #meta>
                        <span>{{ document.category }}</span>
                        <span v-if="document.createdAt">
                            {{ formatDate(document.createdAt) }}
                        </span>
                    </template>
                </ListCard>
            </li>
        </ul>

        <DocumentViewer
            v-model:open="viewerOpen"
            :document="viewing"
            :preview-url="previewUrl"
            :download-url="downloadUrl"
            :show-property="showProperty"
        />
    </div>
</template>
