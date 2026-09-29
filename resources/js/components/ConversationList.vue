<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { ChevronDown, MessageSquare, Wrench } from '@lucide/vue';
import LinkifiedText from '@/components/LinkifiedText.vue';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Card } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Message,
    MessageContent,
    MessageFooter,
} from '@/components/ui/message';
import { useFormattedDate } from '@/composables/useFormattedDate';
import { formatInAppZone } from '@/lib/timezone';
import type { Conversation } from '@/types';

/**
 * Conversations with TexasRenters.com, each folding open to its full thread:
 * staff messages on the left, the client's own on the right.
 */
withDefaults(
    defineProps<{
        conversations: Conversation[];
        /** Links a work order mentioned in a message to its page. */
        workOrderHref?: (id: string) => NonNullable<InertiaLinkProps['href']>;
        showProperty?: boolean;
    }>(),
    { workOrderHref: undefined, showProperty: true },
);

const { formatDate } = useFormattedDate();

function formatDateTime(value: string | null): string {
    return formatInAppZone(value, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}
</script>

<template>
    <ul class="flex flex-col gap-3">
        <li v-for="conversation in conversations" :key="conversation.id">
            <Collapsible v-slot="{ open }" as-child>
                <Card class="glass min-w-0 gap-0 rounded-2xl py-0">
                    <CollapsibleTrigger
                        class="hover:bg-muted/40 focus-visible:ring-ring/60 flex w-full items-start gap-3 rounded-2xl p-4 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <MessageSquare
                            class="text-primary mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="font-medium wrap-anywhere">{{
                                conversation.subject
                            }}</span>
                            <span class="text-muted-foreground text-xs">
                                <template v-if="showProperty">
                                    {{
                                        conversation.about === 'account'
                                            ? 'Your account'
                                            : conversation.property
                                    }}
                                    ·
                                </template>
                                {{ conversation.messageCount }}
                                {{
                                    conversation.messageCount === 1
                                        ? 'message'
                                        : 'messages'
                                }}
                                · {{ formatDate(conversation.updatedAt) }}
                            </span>
                            <span
                                v-if="!open && conversation.preview"
                                class="text-muted-foreground line-clamp-2 text-sm"
                                >{{ conversation.preview }}</span
                            >
                        </span>
                        <ChevronDown
                            class="text-muted-foreground mt-0.5 size-4 shrink-0 transition-transform duration-200"
                            :class="{ 'rotate-180': open }"
                            aria-hidden="true"
                        />
                    </CollapsibleTrigger>

                    <CollapsibleContent>
                        <div class="flex flex-col gap-3 px-4 pb-4">
                            <ol class="flex flex-col gap-3">
                                <Message
                                    v-for="message in conversation.messages"
                                    :key="message.id"
                                    as="li"
                                    :align="
                                        message.fromClient ? 'end' : 'start'
                                    "
                                >
                                    <MessageContent class="gap-1">
                                        <Bubble
                                            :variant="
                                                message.fromClient
                                                    ? 'tinted'
                                                    : 'muted'
                                            "
                                        >
                                            <BubbleContent
                                                ><LinkifiedText
                                                    :text="message.text"
                                                    class="whitespace-pre-line"
                                            /></BubbleContent>
                                        </Bubble>
                                        <MessageFooter class="font-normal">
                                            <template v-if="message.author"
                                                >{{
                                                    message.author
                                                }}&nbsp;·&nbsp;</template
                                            >
                                            <time
                                                :datetime="
                                                    message.at ?? undefined
                                                "
                                                >{{
                                                    formatDateTime(message.at)
                                                }}</time
                                            >
                                        </MessageFooter>
                                    </MessageContent>
                                </Message>
                            </ol>

                            <div
                                v-if="
                                    workOrderHref &&
                                    conversation.workOrders.length > 0
                                "
                                class="flex flex-wrap gap-2"
                            >
                                <Link
                                    v-for="workOrder in conversation.workOrders"
                                    :key="workOrder.id"
                                    :href="workOrderHref(workOrder.id)"
                                    class="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline"
                                >
                                    <Wrench class="size-3.5" />
                                    Work order #{{ workOrder.number }}
                                </Link>
                            </div>
                        </div>
                    </CollapsibleContent>
                </Card>
            </Collapsible>
        </li>
    </ul>
</template>
