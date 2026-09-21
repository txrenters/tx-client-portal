<script setup lang="ts">
import { Download, ExternalLink, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { PortalDocument } from '@/types';

const props = defineProps<{
    document: PortalDocument | null;
    previewUrl: (id: string) => string;
    downloadUrl: (id: string) => string;
    /** Owners hold several properties, so the header names the property. */
    showProperty?: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const details = computed(() =>
    [
        props.showProperty ? props.document?.propertyName : null,
        props.document?.category,
        props.document?.workOrderId
            ? `WO #${props.document.workOrderNumber ?? props.document.workOrderId}`
            : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

const isImage = computed(() =>
    (props.document?.fileType ?? '').startsWith('image/'),
);

/**
 * Files stream from Propertyware through the portal and can take a moment,
 * so the preview shows a spinner until the browser has the file.
 */
const status = ref<'loading' | 'loaded' | 'failed'>('loading');

watch(
    () => [open.value, props.document?.id],
    () => (status.value = 'loading'),
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="document"
            class="flex h-dvh w-screen max-w-none flex-col gap-0 rounded-none border-0 p-0 sm:h-[90vh] sm:w-full sm:max-w-5xl sm:rounded-xl sm:border"
            :show-close-button="false"
        >
            <DialogHeader
                class="flex-row items-center justify-between gap-2 border-b p-3 sm:p-4"
            >
                <div class="min-w-0 flex-1">
                    <DialogTitle class="truncate text-sm sm:text-base">
                        {{ document.fileName }}
                    </DialogTitle>
                    <DialogDescription
                        class="truncate text-xs sm:text-sm"
                        :title="details"
                    >
                        {{ details }}
                    </DialogDescription>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        as-child
                        aria-label="Open in a new tab"
                    >
                        <a
                            :href="previewUrl(document.id)"
                            target="_blank"
                            rel="noopener"
                        >
                            <ExternalLink class="size-4" />
                        </a>
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        as-child
                        aria-label="Download"
                    >
                        <a
                            :href="downloadUrl(document.id)"
                            :download="document.fileName"
                        >
                            <Download class="size-4" />
                        </a>
                    </Button>
                    <DialogClose as-child>
                        <Button variant="ghost" size="icon" aria-label="Close">
                            <X class="size-4" />
                        </Button>
                    </DialogClose>
                </div>
            </DialogHeader>

            <div
                class="bg-muted/40 relative flex min-h-0 flex-1 flex-col items-center justify-center overflow-auto"
                :aria-busy="status === 'loading'"
            >
                <div
                    v-if="status === 'loading'"
                    class="text-muted-foreground absolute inset-0 flex flex-col items-center justify-center gap-3 text-sm"
                    role="status"
                >
                    <Spinner class="size-6" />
                    Loading document…
                </div>

                <div
                    v-else-if="status === 'failed'"
                    class="flex flex-col items-center gap-3 p-6 text-center text-sm"
                    role="alert"
                >
                    <p class="text-muted-foreground">
                        This preview couldn't be loaded.
                    </p>
                    <Button variant="outline" as-child>
                        <a :href="downloadUrl(document.id)">
                            <Download class="size-4" />
                            Download instead
                        </a>
                    </Button>
                </div>

                <img
                    v-if="isImage && status !== 'failed'"
                    :src="previewUrl(document.id)"
                    :alt="document.fileName"
                    class="max-h-full w-auto max-w-full object-contain p-2 transition-opacity duration-200 sm:p-4"
                    :class="{ 'opacity-0': status === 'loading' }"
                    @load="status = 'loaded'"
                    @error="status = 'failed'"
                />
                <iframe
                    v-else-if="!isImage"
                    :src="previewUrl(document.id)"
                    :title="document.fileName"
                    class="h-full w-full border-0 transition-opacity duration-200"
                    :class="{ 'opacity-0': status === 'loading' }"
                    @load="status = 'loaded'"
                />
            </div>

            <!-- Phone browsers often refuse to render a PDF inside a frame. -->
            <div class="border-t p-3 sm:hidden">
                <Button variant="outline" class="w-full" as-child>
                    <a
                        :href="previewUrl(document.id)"
                        target="_blank"
                        rel="noopener"
                    >
                        <ExternalLink class="size-4" />
                        Open in a new tab
                    </a>
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
