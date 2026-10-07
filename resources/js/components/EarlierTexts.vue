<script setup lang="ts">
import { FileText } from '@lucide/vue';
import LinkifiedText from '@/components/LinkifiedText.vue';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { formatInAppZone } from '@/lib/timezone';
import type { ChatThreadMessage } from '@/types';

/**
 * Texts about a work order from before the messaging hub, read-only: what the
 * client and our maintenance team sent each other.
 */
defineProps<{
    messages: ChatThreadMessage[];
}>();

/** Photos show as pictures; anything else as a file chip with its name. */
const isImage = (url: string): boolean =>
    /\.(jpe?g|png|gif|webp|heic|heif)(\?|$)/i.test(url);

const fileName = (url: string): string =>
    decodeURIComponent(url.split('?')[0].split('/').pop() ?? 'File');

const sentAt = (value: string | null): string =>
    formatInAppZone(value, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
</script>

<template>
    <ol class="flex flex-col gap-3">
        <li
            v-for="message in messages"
            :key="message.id"
            class="flex flex-col gap-1"
            :class="message.fromClient ? 'items-end' : 'items-start'"
        >
            <Bubble
                :variant="message.fromClient ? 'default' : 'outline'"
                class="max-w-[85%]"
            >
                <BubbleContent
                    class="flex flex-col gap-1.5 rounded-2xl px-3.5 py-2 shadow-xs"
                    :class="
                        message.fromClient
                            ? 'rounded-br-md'
                            : 'bg-card rounded-bl-md'
                    "
                >
                    <LinkifiedText
                        v-if="message.body"
                        :text="message.body"
                        class="whitespace-pre-line"
                    />
                    <template v-for="url in message.mediaUrls" :key="url">
                        <a
                            v-if="isImage(url)"
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
                            />
                        </a>
                        <a
                            v-else
                            :href="url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="bg-card text-foreground hover:bg-muted/60 flex max-w-64 items-center gap-2 rounded-xl border px-3 py-2 text-sm"
                        >
                            <FileText class="text-primary size-4 shrink-0" />
                            <span class="truncate">{{ fileName(url) }}</span>
                        </a>
                    </template>
                </BubbleContent>
            </Bubble>
            <span class="text-muted-foreground px-1 text-xs">
                {{ message.fromClient ? 'You' : message.author }} ·
                <time :datetime="message.at ?? undefined">{{
                    sentAt(message.at)
                }}</time>
            </span>
        </li>
    </ol>
</template>
