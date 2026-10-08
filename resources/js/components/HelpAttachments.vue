<script setup lang="ts">
import { FileText } from "@lucide/vue";
import type { HelpRequestAttachment } from "@/types";

/**
 * The files sent with a help request: pictures as thumbnails, anything else
 * (PDFs, HEIC photos browsers outside Safari cannot draw) by name. Each opens
 * in a new tab.
 */
defineProps<{
    attachments: HelpRequestAttachment[];
}>();
</script>

<template>
    <ul v-if="attachments.length > 0" class="flex flex-wrap gap-2">
        <li v-for="attachment in attachments" :key="attachment.id">
            <a
                :href="attachment.url"
                target="_blank"
                rel="noopener"
                :title="attachment.name"
                class="border-border hover:border-primary focus-visible:ring-ring block overflow-hidden rounded-md border transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
                <img
                    v-if="attachment.isImage"
                    :src="attachment.url"
                    :alt="attachment.name"
                    loading="lazy"
                    class="bg-muted size-20 object-cover"
                />
                <span
                    v-else
                    class="bg-muted text-muted-foreground flex h-20 w-32 flex-col items-center justify-center gap-1 px-2 text-xs"
                >
                    <FileText class="size-5" />
                    <span class="w-full truncate text-center">{{
                        attachment.name
                    }}</span>
                </span>
            </a>
        </li>
    </ul>
</template>
