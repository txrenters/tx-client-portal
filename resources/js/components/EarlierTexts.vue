<script setup lang="ts">
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
                    <a
                        v-for="(url, index) in message.mediaUrls"
                        :key="url"
                        :href="url"
                        target="_blank"
                        rel="noopener"
                        class="text-sm underline"
                        >Photo {{ index + 1 }}</a
                    >
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
