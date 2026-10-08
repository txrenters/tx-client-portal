<script setup lang="ts">
import { FileText } from '@lucide/vue';
import { ref, watch } from 'vue';
import {
    attachmentExtension,
    attachmentName,
    attachmentPreviewAfterError,
    firstAttachmentPreview,
} from '@/lib/attachments';
import type { AttachmentPreview } from '@/lib/attachments';

/**
 * One photo, clip or file sent with a message. It is tried as a picture first
 * and steps down to a video, then a file card, if the browser can't show it.
 */
const props = defineProps<{
    url: string;
}>();

const preview = ref<AttachmentPreview>(firstAttachmentPreview(props.url));

watch(
    () => props.url,
    (url) => {
        preview.value = firstAttachmentPreview(url);
    },
);

function failed(): void {
    preview.value = attachmentPreviewAfterError(props.url, preview.value);
}
</script>

<template>
    <a
        v-if="preview === 'image'"
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        class="block w-56 max-w-full overflow-hidden rounded-xl"
    >
        <img
            :src="url"
            alt="Attached photo"
            loading="lazy"
            class="w-full object-cover"
            @error="failed"
        />
    </a>
    <video
        v-else-if="preview === 'video'"
        :src="url"
        controls
        playsinline
        preload="metadata"
        class="bg-muted w-56 max-w-full rounded-xl"
        @error="failed"
    />
    <a
        v-else
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        class="bg-card text-foreground hover:bg-muted/60 flex max-w-64 items-center gap-2 rounded-xl border px-3 py-2 text-sm"
    >
        <FileText class="text-primary size-4 shrink-0" />
        <span class="flex min-w-0 flex-col">
            <span class="truncate">{{ attachmentName(url) }}</span>
            <span
                v-if="attachmentExtension(url)"
                class="text-muted-foreground text-xs uppercase"
                >{{ attachmentExtension(url) }}</span
            >
        </span>
    </a>
</template>
