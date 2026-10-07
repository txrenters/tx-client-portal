<script setup lang="ts">
import {
    Form,
    Head,
    InfiniteScroll,
    Link,
    router,
    usePoll,
} from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import {
    ArrowLeft,
    Check,
    FileText,
    MessageSquare,
    Paperclip,
    Plus,
    Search,
    Send,
    SlidersHorizontal,
    Wrench,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import type { ComponentPublicInstance } from 'vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import FilePreviewInput from '@/components/FilePreviewInput.vue';
import InputError from '@/components/InputError.vue';
import LinkifiedText from '@/components/LinkifiedText.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxList,
} from '@/components/ui/combobox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Message,
    MessageAvatar,
    MessageContent,
} from '@/components/ui/message';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { Textarea } from '@/components/ui/textarea';
import { dashboard as ownerDashboard } from '@/routes/owner';
import { show as ownerWorkOrder } from '@/routes/owner/maintenance';
import {
    index as ownerMessages,
    reply as ownerReply,
    store as ownerStore,
} from '@/routes/owner/messages';
import { dashboard as residentDashboard } from '@/routes/resident';
import { show as residentWorkOrder } from '@/routes/resident/maintenance';
import {
    index as residentMessages,
    reply as residentReply,
    store as residentStore,
} from '@/routes/resident/messages';
import { presetDates } from '@/lib/dateRange';
import {
    appDateFormatter,
    appDateString,
    appDayOffset,
    isSameAppDay,
} from '@/lib/timezone';
import type { ChatConversation, ChatThreadMessage, ChatThread } from '@/types';

/**
 * Conversations with TexasRenters.com support and maintenance. They live in
 * the messaging hub, so a reply here reaches staff in Chat Support, and staff
 * replies arrive here and by text.
 */
/** The header beside the sidebar's collapse button, for whichever portal this is. */
defineOptions({
    layout: ({ portal }: { portal: 'owner' | 'resident' }) => ({
        breadcrumbs: [
            {
                title: 'Home',
                href:
                    portal === 'owner' ? ownerDashboard() : residentDashboard(),
            },
            {
                title: 'Messages',
                href: portal === 'owner' ? ownerMessages() : residentMessages(),
            },
        ],
    }),
});

type PropertyOption = { id: number; label: string };

const props = defineProps<{
    portal: 'owner' | 'resident';
    /** False until the messaging hub is configured for this app. */
    available: boolean;
    contactId: string;
    selectedThreadId: number | null;
    filters: {
        search: string | null;
        status: 'open' | 'closed' | null;
        range: string | null;
        from: string | null;
        to: string | null;
    };
    /** The owner's properties, for the new-message picker. Null when they couldn't be loaded. */
    properties?: PropertyOption[] | null;
    /** Loaded after the page, then page by page as the list scrolls. */
    threads?: { data: ChatThread[] };
    conversation?: ChatConversation | null;
    /** Work order texts from before the messaging hub, as closed conversations. */
    earlierTexts?: ChatThread[] | null;
}>();

const routes =
    props.portal === 'owner'
        ? {
              index: ownerMessages,
              reply: ownerReply,
              store: ownerStore,
              workOrder: ownerWorkOrder,
          }
        : {
              index: residentMessages,
              reply: residentReply,
              store: residentStore,
              workOrder: residentWorkOrder,
          };

/**
 * What each category looks like in the list. The word is always there, so the
 * tint only helps the eye group rows; it never carries the meaning alone.
 */
const categoryLabel = {
    leasing: 'Leasing',
    accounting: 'Accounting',
    maintenance: 'Maintenance',
} as const;

/** The three choices in the New message form, in the order clients scan them. */
const categoryOptions = [
    {
        value: 'leasing',
        label: 'Leasing',
        hint: 'Lease, renewal, move-in or move-out',
        dot: 'bg-category-leasing',
    },
    {
        value: 'accounting',
        label: 'Accounting',
        hint: 'Statements, rent, fees or payments',
        dot: 'bg-category-accounting',
    },
    {
        value: 'maintenance',
        label: 'Maintenance',
        hint: 'Repairs and work order questions',
        dot: 'bg-category-maintenance',
    },
] as const;

const categoryTint = {
    leasing: 'bg-category-leasing text-category-foreground',
    accounting: 'bg-category-accounting text-category-foreground',
    maintenance: 'bg-category-maintenance text-category-foreground',
} as const;

const startOpen = ref(false);

/** The category the new message is about; the chatbot answers on that footing. */
const startCategory = ref<string | undefined>();

/** The property an owner picked, when they have more than one. */
const startProperty = ref<PropertyOption | undefined>();

/** What they typed; there is nothing to send until it says something. */
const startBody = ref('');

const canStart = computed(
    () => startCategory.value !== undefined && startBody.value.trim() !== '',
);

/** An owner with one property is never asked which; the message is about that one. */
const onlyProperty = computed(() =>
    props.properties?.length === 1 ? props.properties[0] : undefined,
);

const startPropertyId = computed(
    () => startProperty.value?.id ?? onlyProperty.value?.id ?? '',
);

watch(startOpen, () => {
    startCategory.value = undefined;
    startProperty.value = undefined;
    startBody.value = '';
});
const searchTerm = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const dateRange = ref(props.filters.range ?? 'all');
const dateFrom = ref(
    props.filters.range === 'custom' ? (props.filters.from ?? '') : '',
);
const dateTo = ref(
    props.filters.range === 'custom' ? (props.filters.to ?? '') : '',
);

/** How many filters in the Filters dialog are set, shown on its button. */
const activeFilterCount = computed(
    () => Number(status.value !== 'all') + Number(dateRange.value !== 'all'),
);

const isFiltered = computed(
    () => searchTerm.value.trim() !== '' || activeFilterCount.value > 0,
);

/** The list filters as URL query values; empty ones are left out. */
function filterQuery(): Record<string, string> {
    const dates =
        dateRange.value === 'all'
            ? {}
            : presetDates(dateRange.value, dateFrom.value, dateTo.value);

    return Object.fromEntries(
        Object.entries({
            search: searchTerm.value.trim(),
            status: status.value === 'all' ? '' : status.value,
            range: dateRange.value === 'all' ? '' : dateRange.value,
            from: dates.from ?? '',
            to: dates.to ?? '',
        }).filter(([, value]) => value !== ''),
    );
}

/* The Filters dialog edits a draft, applied only when the client confirms. */
const filtersOpen = ref(false);
const draft = ref({ status: 'all', range: 'all', from: '', to: '' });

function openFilters(): void {
    draft.value = {
        status: status.value,
        range: dateRange.value,
        from: dateFrom.value,
        to: dateTo.value,
    };
    filtersOpen.value = true;
}

const draftIsIncomplete = computed(
    () =>
        draft.value.range === 'custom' &&
        (!draft.value.from || !draft.value.to),
);

function showResults(): void {
    status.value = draft.value.status;
    dateRange.value = draft.value.range;
    dateFrom.value = draft.value.range === 'custom' ? draft.value.from : '';
    dateTo.value = draft.value.range === 'custom' ? draft.value.to : '';
    filtersOpen.value = false;
    applyFilters();
}

function resetDraft(): void {
    draft.value = { status: 'all', range: 'all', from: '', to: '' };
}
const messageList = ref<HTMLElement | null>(null);
const replyAttachments = ref<InstanceType<typeof FilePreviewInput> | null>(
    null,
);

const replyBody = ref('');

/** Something to send: text that isn't only spaces, or a file. */
const canSend = computed(
    () =>
        replyBody.value.trim() !== '' ||
        (replyAttachments.value?.count ?? 0) > 0,
);

/** Sent with each reply so a retried send never posts it twice. */
const replyKey = ref(crypto.randomUUID());

const timeFormatter = appDateFormatter({
    hour: 'numeric',
    minute: '2-digit',
});
const shortDateFormatter = appDateFormatter({
    month: 'short',
    day: 'numeric',
});
const dayFormatter = appDateFormatter({
    weekday: 'short',
    month: 'short',
    day: 'numeric',
});

/** "3:57 PM" today, "Jul 18" before that. */
function listTime(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return isSameAppDay(date, new Date())
        ? timeFormatter.format(date)
        : shortDateFormatter.format(date);
}

function dayLabel(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (isSameAppDay(date, new Date())) {
        return 'Today';
    }

    return appDateString(date) === appDayOffset(-1)
        ? 'Yesterday'
        : dayFormatter.format(date);
}

function initials(name: string | null): string {
    return (name ?? 'TR')
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

function isImage(url: string): boolean {
    return /\.(jpe?g|png|gif|webp|heic|heif)(\?|$)/i.test(url);
}

function fileName(url: string): string {
    return decodeURIComponent(url.split('?')[0].split('/').pop() ?? 'File');
}

type Row = {
    message: ChatThreadMessage;
    /** The message's attachments, plus those of attachment-only messages folded into it. */
    media: string[];
    /** A date divider before this message. */
    day: string | null;
    /** First message in a run from the same person: show their name and photo. */
    startsRun: boolean;
};

/**
 * How close an attachment must follow a message to count as the same send. The
 * portal posts a send's extra files one after another, seconds apart; anything
 * later was sent on its own and keeps its own bubble.
 */
const ATTACHMENT_FOLD_MS = 15 * 1000;

/**
 * A hub message carries one attachment, so a send with several files arrives
 * as the text with the first file, then one message per further file. Those
 * are shown inside the first message's bubble.
 */
function foldsInto(message: ChatThreadMessage, row: Row | undefined): boolean {
    if (!row || message.body !== '' || message.mediaUrls.length === 0) {
        return false;
    }

    const gap =
        message.at && row.message.at
            ? new Date(message.at).getTime() -
              new Date(row.message.at).getTime()
            : Infinity;

    return (
        row.message.fromClient === message.fromClient &&
        row.message.author === message.author &&
        gap <= ATTACHMENT_FOLD_MS
    );
}

/** Messages with date dividers, grouped into runs from the same person. */
const rows = computed<Row[]>(() => {
    const result: Row[] = [];

    for (const message of props.conversation?.messages ?? []) {
        const previousRow = result[result.length - 1];

        if (foldsInto(message, previousRow)) {
            previousRow.media = [...previousRow.media, ...message.mediaUrls];

            continue;
        }

        const previous = previousRow?.message;
        const day = dayLabel(message.at);
        const newDay = !previous || dayLabel(previous.at) !== day;

        result.push({
            message,
            media: message.mediaUrls,
            day: newDay ? day : null,
            startsRun:
                newDay ||
                previous.fromClient !== message.fromClient ||
                previous.author !== message.author,
        });
    }

    return result;
});

/**
 * The list, with the open conversation showing its newest message, whoever
 * wrote it. Opening or answering a conversation reloads only that
 * conversation, so the list would otherwise keep the preview it was loaded with.
 */
const listedThreads = computed<ChatThread[]>(() => {
    const threads = props.threads?.data ?? [];
    const open = props.conversation;
    const latest = open?.messages[open.messages.length - 1];

    if (!open || !latest || latest.id < 0) {
        return threads;
    }

    return threads.map((thread) =>
        thread.id !== open.thread.id ||
        (thread.lastMessage?.id ?? 0) >= latest.id
            ? thread
            : {
                  ...thread,
                  updatedAt: latest.at ?? thread.updatedAt,
                  lastMessage: {
                      id: latest.id,
                      author: latest.author,
                      preview: latest.body,
                      fromClient: latest.fromClient,
                      hasAttachment: latest.mediaUrls.length > 0,
                  },
              },
    );
});

/**
 * The earlier work order texts the applied filters allow. They are all closed,
 * so they drop out when only open conversations are asked for.
 */
const listedEarlierTexts = computed<ChatThread[]>(() => {
    const { search, status: shownStatus, from, to } = props.filters;

    if (!props.earlierTexts || shownStatus === 'open') {
        return [];
    }

    const term = (search ?? '').trim().toLowerCase();

    return props.earlierTexts.filter((thread) => {
        const day = thread.updatedAt?.slice(0, 10) ?? '';
        const text =
            `${thread.subject} ${thread.lastMessage?.preview ?? ''} ${thread.workOrderNumber ?? ''}`.toLowerCase();

        return (
            (!term || text.includes(term)) &&
            (!from || day >= from) &&
            (!to || day <= to)
        );
    });
});

/** Conversations opened on this page; the server records them as read too. */
const openedThreadIds = ref(new Set<number>());

function isUnread(thread: ChatThread): boolean {
    return Boolean(thread.unread) && !openedThreadIds.value.has(thread.id);
}

function open(threadId: number | null): void {
    if (threadId !== null) {
        openedThreadIds.value = new Set([...openedThreadIds.value, threadId]);
    }

    router.get(
        routes.index.url(),
        {
            ...(threadId === null ? {} : { thread: threadId }),
            ...filterQuery(),
        },
        {
            only: ['selectedThreadId', 'conversation'],
            preserveState: true,
            preserveScroll: true,
        },
    );
}

/** A new search or filter starts the list over instead of adding to it. */
function applyFilters(): void {
    router.get(
        routes.index.url(),
        {
            ...filterQuery(),
            ...(props.selectedThreadId
                ? { thread: props.selectedThreadId }
                : {}),
        },
        {
            only: ['threads', 'filters'],
            reset: ['threads'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function clearFilters(): void {
    searchTerm.value = '';
    status.value = 'all';
    dateRange.value = 'all';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
}

watchDebounced(searchTerm, applyFilters, { debounce: 300 });

/** The first error about the attached files, whichever file it was. */
function attachmentError(errors: Record<string, string>): string | undefined {
    return (
        errors.attachments ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('attachments.'),
        )?.[1]
    );
}

/** What was typed, kept until the hub has it so a failed send can be put back. */
const pendingReply = ref('');

/**
 * Shows the reply in the conversation the moment it is sent. The hub can take
 * a few seconds, and Inertia rolls this back by itself if the send fails.
 */
function withPendingReply(
    conversation: ChatConversation,
    body: unknown,
): ChatConversation {
    return {
        ...conversation,
        messages: [
            ...conversation.messages,
            {
                // Negative, so it never collides with a real message and reads as pending.
                id: -Date.now(),
                body: String(body ?? '').trim(),
                mediaUrls: [],
                fromClient: true,
                author: null,
                authorPhotoUrl: null,
                at: new Date().toISOString(),
            },
        ],
    };
}

/** The composer clears as soon as the reply is on its way. */
function replyStarted(): void {
    sending.value = true;
    pendingReply.value = replyBody.value;
    replyBody.value = '';
}

function replyFailed(): void {
    replyBody.value = pendingReply.value;
}

function replySent(): void {
    replyKey.value = crypto.randomUUID();
    pendingReply.value = '';
    replyAttachments.value?.clear();
}

/** Enter sends, Shift+Enter starts a new line. */
function sendOnEnter(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();

        if (canSend.value) {
            (event.target as HTMLTextAreaElement).form?.requestSubmit();
        }
    }
}

/** Staff replies and delivery changes arrive over Reverb; reload what they touch. */
/**
 * True while this page is sending a message of its own. The portal announces
 * it before the send has finished here, and reloading then would cancel the
 * send's own visit, which already brings the conversation back.
 */
const sending = ref(false);

/**
 * A fallback for the live updates: a staff reply only arrives live once the
 * hub's webhook reaches the portal, so an open conversation is also re-read
 * every 30 seconds. Skipped while this page is sending, like the live reload.
 */
const conversationPoll = usePoll(
    30_000,
    () => ({
        only: ['conversation'],
        onBefore: () => !sending.value,
    }),
    { autoStart: false, mode: 'rest' },
);

watch(
    () => props.available && props.selectedThreadId !== null,
    (open) => (open ? conversationPoll.start() : conversationPoll.stop()),
    { immediate: true },
);

/** The conversation list's own scroll box (wide screens; phones scroll the page). */
const listCard = useTemplateRef<ComponentPublicInstance>('listCard');

/** A change arrived while the client was scrolled down the list. */
const hasNewActivity = ref(false);

/** Whether the client has scrolled away from the top of the list. */
function isListScrolled(): boolean {
    const box = listCard.value?.$el as HTMLElement | undefined;

    return (box?.scrollTop ?? 0) > 40 || window.scrollY > 200;
}

/** The list from its first page again, newest change first, scrolled to the top. */
function showNewActivity(): void {
    hasNewActivity.value = false;

    // Without the list's page number, which it keeps in the address as it
    // scrolls: a plain reload would start over at that page, not the newest.
    router.get(
        routes.index.url(),
        {
            ...filterQuery(),
            ...(props.selectedThreadId
                ? { thread: props.selectedThreadId }
                : {}),
        },
        {
            only: ['threads'],
            reset: ['threads'],
            preserveState: true,
            replace: true,
            // After the list is back: the visit puts back where it was scrolled.
            onSuccess: () => {
                nextTick(() => {
                    (listCard.value?.$el as HTMLElement | undefined)?.scrollTo({
                        top: 0,
                    });
                    window.scrollTo({ top: 0 });
                });
            },
        },
    );
}

if (props.available) {
    useEcho<{ threadId: number }>(
        `contacts.${props.contactId}.messages`,
        '.conversation.updated',
        (event) => {
            if (sending.value) {
                return;
            }

            if (event.threadId === props.selectedThreadId) {
                router.reload({ only: ['conversation'] });
            }

            // Starting the list over moves it back to its first page, which
            // threw the client back up while they were scrolling down (every
            // delivery update does this). Offer it instead, unless they are at
            // the top anyway.
            if (isListScrolled()) {
                hasNewActivity.value = true;

                return;
            }

            showNewActivity();
        },
    );
}

watch(
    () => [props.selectedThreadId, props.conversation?.messages.length],
    async () => {
        await nextTick();
        messageList.value?.scrollTo({ top: messageList.value.scrollHeight });
    },
);
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Messages" />
        <PageHeader
            title="Messages"
            description="Your conversations with our office. Replies show up here and by text."
        >
            <template v-if="available" #actions>
                <Dialog v-model:open="startOpen">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="size-4" />
                            New message
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>New message</DialogTitle>
                            <DialogDescription>
                                We'll reply here and by text.
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            :action="routes.store()"
                            class="flex flex-col gap-4"
                            v-slot="{ errors, processing }"
                            @start="sending = true"
                            @finish="sending = false"
                            @success="startOpen = false"
                        >
                            <div
                                v-if="portal === 'owner' && properties?.length"
                                class="grid gap-2"
                            >
                                <Label for="message-property">Property</Label>
                                <p v-if="onlyProperty" class="text-sm">
                                    {{ onlyProperty.label }}
                                </p>
                                <Combobox
                                    v-else
                                    v-model="startProperty"
                                    by="id"
                                    :reset-search-term-on-select="false"
                                >
                                    <ComboboxAnchor class="w-full">
                                        <ComboboxInput
                                            id="message-property"
                                            :display-value="
                                                (value) =>
                                                    (
                                                        value as
                                                            | PropertyOption
                                                            | undefined
                                                    )?.label ?? ''
                                            "
                                            placeholder="Choose a property"
                                        />
                                    </ComboboxAnchor>
                                    <ComboboxList class="max-h-72">
                                        <ComboboxEmpty
                                            >No matching
                                            property.</ComboboxEmpty
                                        >
                                        <ComboboxGroup>
                                            <ComboboxItem
                                                v-for="item in properties"
                                                :key="item.id"
                                                :value="item"
                                            >
                                                {{ item.label }}
                                                <ComboboxItemIndicator>
                                                    <Check class="size-4" />
                                                </ComboboxItemIndicator>
                                            </ComboboxItem>
                                        </ComboboxGroup>
                                    </ComboboxList>
                                </Combobox>
                                <input
                                    type="hidden"
                                    name="property_id"
                                    :value="startPropertyId"
                                />
                                <InputError :message="errors.property_id" />
                            </div>
                            <div class="grid gap-2">
                                <span class="text-sm font-medium"
                                    >What is it about?</span
                                >
                                <RadioGroup
                                    v-model="startCategory"
                                    name="category"
                                    class="grid gap-2 sm:grid-cols-3"
                                >
                                    <Label
                                        v-for="option in categoryOptions"
                                        :key="option.value"
                                        :for="`category-${option.value}`"
                                        class="flex cursor-pointer flex-col items-start gap-2 rounded-xl border p-3 transition-colors"
                                        :class="
                                            startCategory === option.value
                                                ? 'border-primary bg-primary/5'
                                                : 'border-input hover:bg-muted/50'
                                        "
                                    >
                                        <span
                                            class="flex w-full items-center justify-between gap-2"
                                        >
                                            <span
                                                class="size-2.5 rounded-full"
                                                :class="option.dot"
                                                aria-hidden="true"
                                            />
                                            <RadioGroupItem
                                                :id="`category-${option.value}`"
                                                :value="option.value"
                                            />
                                        </span>
                                        <span class="text-sm font-medium">{{
                                            option.label
                                        }}</span>
                                        <span
                                            class="text-muted-foreground text-xs font-normal"
                                            >{{ option.hint }}</span
                                        >
                                    </Label>
                                </RadioGroup>
                                <InputError :message="errors.category" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="message-body">Message</Label>
                                <Textarea
                                    id="message-body"
                                    v-model="startBody"
                                    name="body"
                                    rows="5"
                                    maxlength="1600"
                                    required
                                />
                                <InputError :message="errors.body" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="message-attachments">
                                    Photos or files
                                    <span
                                        class="text-muted-foreground font-normal"
                                        >(optional, up to 5)</span
                                    >
                                </Label>
                                <FilePreviewInput
                                    id="message-attachments"
                                    name="attachments[]"
                                    multiple
                                    :max="5"
                                    accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.pdf,.doc,.docx"
                                />
                                <InputError
                                    :message="attachmentError(errors)"
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    :disabled="processing || !canStart"
                                >
                                    <Spinner v-if="processing" />
                                    Send
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </template>
        </PageHeader>

        <Card v-if="!available" class="glass rounded-2xl p-6">
            <EmptyState
                :icon="MessageSquare"
                title="Messaging is almost ready"
                description="You'll be able to message our office here soon."
            />
        </Card>

        <!--
          On wide screens both columns share one height that fits the window and
          scroll on their own, and reaching the end of one never scrolls the
          page (overscroll-contain), so nothing jumps or slips under the top bar.
        -->
        <div
            v-else
            class="grid items-start gap-4 lg:h-[calc(100dvh-12rem)] lg:min-h-[28rem] lg:grid-cols-[22rem_minmax(0,1fr)] lg:items-stretch"
        >
            <!-- Conversation list: paged as it scrolls. -->
            <div
                class="flex flex-col gap-3 lg:min-h-0"
                :class="{ 'hidden lg:flex': selectedThreadId !== null }"
            >
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <Search
                            class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="searchTerm"
                            type="search"
                            class="pl-9"
                            placeholder="Search messages"
                            aria-label="Search messages"
                        />
                    </div>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon"
                                class="relative shrink-0"
                                :aria-label="
                                    activeFilterCount > 0
                                        ? `Filters, ${activeFilterCount} on`
                                        : 'Filters'
                                "
                                @click="openFilters"
                            >
                                <SlidersHorizontal class="size-4" />
                                <span
                                    v-if="activeFilterCount > 0"
                                    class="bg-primary text-primary-foreground absolute -top-1.5 -right-1.5 flex size-4 items-center justify-center rounded-full text-[10px] font-semibold"
                                    aria-hidden="true"
                                    >{{ activeFilterCount }}</span
                                >
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Filters</TooltipContent>
                    </Tooltip>
                </div>

                <Button
                    v-if="isFiltered"
                    variant="link"
                    size="sm"
                    class="h-auto self-start p-0"
                    @click="clearFilters"
                    >Clear search and filters</Button
                >

                <Dialog v-model:open="filtersOpen">
                    <DialogContent class="sm:max-w-sm">
                        <DialogHeader>
                            <DialogTitle>Filters</DialogTitle>
                            <DialogDescription class="sr-only"
                                >Narrow your conversations by status and
                                date.</DialogDescription
                            >
                        </DialogHeader>

                        <div class="flex flex-col gap-5">
                            <div class="grid gap-2">
                                <Label for="messages-status">Status</Label>
                                <Select v-model="draft.status">
                                    <SelectTrigger
                                        id="messages-status"
                                        class="w-full"
                                        ><SelectValue
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all"
                                            >All conversations</SelectItem
                                        >
                                        <SelectItem value="open"
                                            >Open</SelectItem
                                        >
                                        <SelectItem value="closed"
                                            >Closed</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="grid gap-2">
                                <Label for="messages-date">Date</Label>
                                <Select v-model="draft.range">
                                    <SelectTrigger
                                        id="messages-date"
                                        class="w-full"
                                        ><SelectValue placeholder="Any date"
                                    /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all"
                                            >Any date</SelectItem
                                        >
                                        <SelectItem value="7"
                                            >Last 7 days</SelectItem
                                        >
                                        <SelectItem value="30"
                                            >Last 30 days</SelectItem
                                        >
                                        <SelectItem value="90"
                                            >Last 3 months</SelectItem
                                        >
                                        <SelectItem value="year"
                                            >This year</SelectItem
                                        >
                                        <SelectItem value="custom"
                                            >Custom range…</SelectItem
                                        >
                                    </SelectContent>
                                </Select>
                                <div
                                    v-if="draft.range === 'custom'"
                                    class="grid grid-cols-2 gap-2"
                                >
                                    <div class="grid gap-1">
                                        <Label
                                            for="messages-from"
                                            class="text-xs"
                                            >From</Label
                                        >
                                        <Input
                                            id="messages-from"
                                            v-model="draft.from"
                                            type="date"
                                            :max="draft.to || undefined"
                                        />
                                    </div>
                                    <div class="grid gap-1">
                                        <Label for="messages-to" class="text-xs"
                                            >To</Label
                                        >
                                        <Input
                                            id="messages-to"
                                            v-model="draft.to"
                                            type="date"
                                            :min="draft.from || undefined"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <DialogFooter class="gap-2 sm:justify-between">
                            <Button variant="ghost" @click="resetDraft"
                                >Reset</Button
                            >
                            <Button
                                :disabled="draftIsIncomplete"
                                @click="showResults"
                                >Show results</Button
                            >
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Card
                    ref="listCard"
                    class="glass relative gap-0 overflow-hidden rounded-2xl py-0 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:overscroll-contain"
                >
                    <!-- Changes that came in while the client was scrolled down. -->
                    <div
                        v-if="hasNewActivity"
                        class="sticky top-2 z-10 flex justify-center px-3 pt-2"
                    >
                        <Button
                            size="sm"
                            class="rounded-full shadow-md"
                            @click="showNewActivity"
                        >
                            New activity · Show
                        </Button>
                    </div>
                    <div
                        v-if="threads === undefined"
                        class="flex flex-col gap-2 p-3"
                        role="status"
                        aria-label="Loading conversations"
                    >
                        <Skeleton
                            v-for="item in 6"
                            :key="item"
                            class="h-16 w-full rounded-xl"
                        />
                    </div>

                    <EmptyState
                        v-else-if="
                            threads.data.length === 0 &&
                            listedEarlierTexts.length === 0
                        "
                        class="p-4"
                        :icon="MessageSquare"
                        :title="isFiltered ? 'No matches' : 'No messages yet'"
                        :description="
                            isFiltered
                                ? 'Try a different search or date.'
                                : 'Start one with the New message button.'
                        "
                    />

                    <InfiniteScroll
                        v-else
                        data="threads"
                        as="ul"
                        class="divide-border/60 divide-y"
                    >
                        <li v-for="thread in listedThreads" :key="thread.id">
                            <button
                                type="button"
                                class="hover:bg-muted/50 focus-visible:ring-ring/60 flex w-full items-start gap-3 px-3 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                :class="{
                                    'bg-primary/5':
                                        thread.id === selectedThreadId,
                                }"
                                :aria-current="
                                    thread.id === selectedThreadId
                                        ? 'true'
                                        : undefined
                                "
                                @click="open(thread.id)"
                            >
                                <!--
                                  Who is handling the conversation, so the list
                                  shows a person rather than the same icon on
                                  every row. Falls back to the icon until staff
                                  pick the thread up.
                                -->
                                <Avatar
                                    v-if="thread.assignedStaff"
                                    class="mt-0.5 size-9 shrink-0"
                                >
                                    <AvatarImage
                                        v-if="thread.assignedStaff.photoUrl"
                                        :src="thread.assignedStaff.photoUrl"
                                        alt=""
                                    />
                                    <AvatarFallback
                                        class="bg-primary/10 text-primary text-xs font-medium"
                                        >{{
                                            initials(thread.assignedStaff.name)
                                        }}</AvatarFallback
                                    >
                                </Avatar>
                                <span
                                    v-else
                                    class="bg-primary/10 text-primary mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full"
                                >
                                    <component
                                        :is="
                                            thread.kind === 'maintenance'
                                                ? Wrench
                                                : MessageSquare
                                        "
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span
                                    class="flex min-w-0 flex-1 flex-col gap-0.5"
                                >
                                    <span
                                        class="flex items-baseline justify-between gap-2"
                                    >
                                        <span
                                            class="truncate text-sm"
                                            :class="
                                                isUnread(thread)
                                                    ? 'font-semibold'
                                                    : 'font-medium'
                                            "
                                            >{{ thread.subject }}</span
                                        >
                                        <span
                                            class="text-muted-foreground flex shrink-0 items-center gap-1.5 text-xs"
                                        >
                                            <span
                                                v-if="isUnread(thread)"
                                                class="bg-primary size-2 rounded-full"
                                            />
                                            <span
                                                v-if="isUnread(thread)"
                                                class="sr-only"
                                                >Unread.</span
                                            >
                                            {{ listTime(thread.updatedAt) }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="thread.assignedStaff"
                                        class="sr-only"
                                        >Handled by
                                        {{ thread.assignedStaff.name }}.</span
                                    >
                                    <span
                                        v-if="thread.lastMessage"
                                        class="text-muted-foreground line-clamp-1 text-xs"
                                    >
                                        <template
                                            v-if="thread.lastMessage.fromClient"
                                            >You: </template
                                        >{{
                                            thread.lastMessage.preview ||
                                            (thread.lastMessage.hasAttachment
                                                ? 'Sent a file'
                                                : '')
                                        }}
                                    </span>
                                    <span
                                        class="flex flex-wrap items-center gap-1.5 pt-0.5"
                                    >
                                        <Badge
                                            v-if="thread.category"
                                            variant="secondary"
                                            class="h-5 border-transparent px-1.5 text-[10px] font-medium"
                                            :class="
                                                categoryTint[thread.category]
                                            "
                                        >
                                            {{ categoryLabel[thread.category] }}
                                        </Badge>
                                        <Badge
                                            v-else
                                            variant="secondary"
                                            class="h-5 px-1.5 text-[10px]"
                                        >
                                            {{
                                                thread.kind === 'maintenance'
                                                    ? 'Maintenance'
                                                    : 'Support'
                                            }}
                                        </Badge>
                                        <Badge
                                            v-if="thread.workOrderNumber"
                                            variant="outline"
                                            class="h-5 px-1.5 text-[10px]"
                                            >WO #{{
                                                thread.workOrderNumber
                                            }}</Badge
                                        >
                                        <Badge
                                            v-if="thread.isClosed"
                                            variant="outline"
                                            class="h-5 px-1.5 text-[10px]"
                                            >Closed</Badge
                                        >
                                    </span>
                                </span>
                            </button>
                        </li>
                        <template #loading>
                            <li
                                class="p-3"
                                role="status"
                                aria-label="Loading more"
                            >
                                <Skeleton class="h-16 w-full rounded-xl" />
                            </li>
                        </template>
                    </InfiniteScroll>

                    <!-- Work order texts from before the hub: closed, read-only. -->
                    <template v-if="listedEarlierTexts.length > 0">
                        <p
                            class="text-muted-foreground border-border/60 bg-muted/30 border-y px-3 py-2 text-xs font-medium"
                        >
                            Earlier work order texts
                        </p>
                        <ul class="divide-border/60 divide-y">
                            <li
                                v-for="thread in listedEarlierTexts"
                                :key="thread.id"
                            >
                                <button
                                    type="button"
                                    class="hover:bg-muted/50 focus-visible:ring-ring/60 flex w-full items-start gap-3 px-3 py-3 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                    :class="{
                                        'bg-primary/5':
                                            thread.id === selectedThreadId,
                                    }"
                                    :aria-current="
                                        thread.id === selectedThreadId
                                            ? 'true'
                                            : undefined
                                    "
                                    @click="open(thread.id)"
                                >
                                    <span
                                        class="bg-muted text-muted-foreground mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full"
                                    >
                                        <Wrench
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span
                                        class="flex min-w-0 flex-1 flex-col gap-0.5"
                                    >
                                        <span
                                            class="flex items-baseline justify-between gap-2"
                                        >
                                            <span
                                                class="truncate text-sm font-medium"
                                                >{{ thread.subject }}</span
                                            >
                                            <span
                                                class="text-muted-foreground shrink-0 text-xs"
                                                >{{
                                                    listTime(thread.updatedAt)
                                                }}</span
                                            >
                                        </span>
                                        <span
                                            v-if="thread.lastMessage"
                                            class="text-muted-foreground line-clamp-1 text-xs"
                                        >
                                            <template
                                                v-if="
                                                    thread.lastMessage
                                                        .fromClient
                                                "
                                                >You: </template
                                            >{{
                                                thread.lastMessage.preview ||
                                                (thread.lastMessage
                                                    .hasAttachment
                                                    ? 'Sent a file'
                                                    : '')
                                            }}
                                        </span>
                                        <span
                                            class="flex flex-wrap items-center gap-1.5 pt-0.5"
                                        >
                                            <Badge
                                                variant="secondary"
                                                class="h-5 border-transparent px-1.5 text-[10px] font-medium"
                                                :class="
                                                    categoryTint.maintenance
                                                "
                                            >
                                                {{ categoryLabel.maintenance }}
                                            </Badge>
                                            <Badge
                                                v-if="thread.workOrderNumber"
                                                variant="outline"
                                                class="h-5 px-1.5 text-[10px]"
                                                >WO #{{
                                                    thread.workOrderNumber
                                                }}</Badge
                                            >
                                            <Badge
                                                variant="outline"
                                                class="h-5 px-1.5 text-[10px]"
                                                >Closed</Badge
                                            >
                                        </span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </template>
                </Card>
            </div>

            <!-- Selected conversation: fits the screen, beside the list on wide screens. -->
            <Card
                class="glass flex h-[calc(100dvh-10rem)] min-h-[26rem] min-w-0 flex-col gap-0 overflow-hidden rounded-2xl py-0 lg:h-auto lg:min-h-0"
                :class="{ 'hidden lg:flex': selectedThreadId === null }"
            >
                <div
                    v-if="selectedThreadId === null"
                    class="flex flex-1 items-center justify-center p-6"
                >
                    <EmptyState
                        :icon="MessageSquare"
                        title="Pick a conversation"
                        description="Choose one from the list, or start a new message."
                    />
                </div>

                <div
                    v-else-if="conversation === undefined"
                    class="flex flex-1 flex-col gap-4 p-4"
                    role="status"
                    aria-label="Loading conversation"
                >
                    <Skeleton class="h-6 w-1/2" />
                    <Skeleton class="h-16 w-2/3 rounded-2xl" />
                    <Skeleton class="ml-auto h-12 w-1/2 rounded-2xl" />
                    <Skeleton class="h-20 w-3/5 rounded-2xl" />
                </div>

                <ErrorState
                    v-else-if="conversation === null"
                    class="p-4"
                    description="We couldn't load this conversation right now."
                />

                <template v-else>
                    <header
                        class="border-border/60 flex items-center gap-3 border-b px-4 py-3"
                    >
                        <Button
                            variant="ghost"
                            size="icon"
                            class="lg:hidden"
                            aria-label="Back to conversations"
                            @click="open(null)"
                        >
                            <ArrowLeft class="size-4" />
                        </Button>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate font-medium">
                                {{ conversation.thread.subject }}
                            </h2>
                            <p class="text-muted-foreground text-xs">
                                <Link
                                    v-if="conversation.thread.workOrderId"
                                    :href="
                                        routes.workOrder(
                                            conversation.thread.workOrderId,
                                        )
                                    "
                                    class="text-primary hover:underline"
                                >
                                    Work order #{{
                                        conversation.thread.workOrderNumber
                                    }}
                                </Link>
                                <template v-else>
                                    {{
                                        conversation.thread.kind ===
                                        'maintenance'
                                            ? 'Maintenance'
                                            : 'Support'
                                    }}
                                </template>
                                <template v-if="conversation.thread.isHistory">
                                    · Texts from before our new messaging
                                </template>
                                <template v-if="conversation.thread.phone">
                                    · Also texted to
                                    {{ conversation.thread.phone }}
                                </template>
                            </p>
                        </div>
                        <Badge
                            v-if="conversation.thread.isClosed"
                            variant="outline"
                            >Closed</Badge
                        >
                    </header>

                    <ol
                        ref="messageList"
                        class="bg-muted/20 flex flex-1 flex-col gap-1.5 overflow-y-auto overscroll-contain px-4 py-4"
                        aria-live="polite"
                    >
                        <template v-for="row in rows" :key="row.message.id">
                            <li
                                v-if="row.day"
                                class="text-muted-foreground my-2 flex items-center gap-3 text-xs"
                                aria-hidden="true"
                            >
                                <span class="bg-border h-px flex-1" />
                                {{ row.day }}
                                <span class="bg-border h-px flex-1" />
                            </li>

                            <Message
                                as="li"
                                :align="
                                    row.message.fromClient ? 'end' : 'start'
                                "
                                :class="{ 'mt-2': row.startsRun && !row.day }"
                            >
                                <MessageAvatar
                                    v-if="!row.message.fromClient"
                                    class="size-8 self-start"
                                    :class="{ invisible: !row.startsRun }"
                                >
                                    <Avatar class="size-8">
                                        <AvatarImage
                                            v-if="row.message.authorPhotoUrl"
                                            :src="row.message.authorPhotoUrl"
                                            :alt="row.message.author ?? ''"
                                        />
                                        <AvatarFallback class="text-[10px]">{{
                                            initials(row.message.author)
                                        }}</AvatarFallback>
                                    </Avatar>
                                </MessageAvatar>

                                <MessageContent
                                    class="max-w-[85%] gap-1 sm:max-w-[70%]"
                                >
                                    <span
                                        v-if="
                                            row.startsRun &&
                                            !row.message.fromClient
                                        "
                                        class="text-muted-foreground px-1 text-xs font-medium"
                                        >{{ row.message.author }}</span
                                    >

                                    <Bubble
                                        v-if="
                                            row.message.body || row.media.length
                                        "
                                        :variant="
                                            row.message.fromClient
                                                ? 'default'
                                                : 'outline'
                                        "
                                        class="max-w-full"
                                        :class="{
                                            'opacity-60': row.message.id < 0,
                                        }"
                                    >
                                        <BubbleContent
                                            class="flex flex-col gap-1.5 rounded-2xl shadow-xs"
                                            :class="[
                                                row.message.fromClient
                                                    ? 'rounded-br-md'
                                                    : 'bg-card rounded-bl-md',
                                                row.media.length
                                                    ? 'p-1.5'
                                                    : 'px-3.5 py-2',
                                            ]"
                                        >
                                            <LinkifiedText
                                                v-if="row.message.body"
                                                :text="row.message.body"
                                                class="whitespace-pre-line"
                                                :class="{
                                                    'px-2 pt-1':
                                                        row.media.length,
                                                }"
                                            />

                                            <!-- Photos and files sit inside the message they came with, below its text. -->
                                            <template
                                                v-for="url in row.media"
                                                :key="url"
                                            >
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
                                                    <FileText
                                                        class="text-primary size-4 shrink-0"
                                                    />
                                                    <span class="truncate">{{
                                                        fileName(url)
                                                    }}</span>
                                                </a>
                                            </template>
                                        </BubbleContent>
                                    </Bubble>

                                    <!-- The time sits under the bubble, on the sender's side. -->
                                    <span
                                        class="text-muted-foreground flex flex-wrap items-center gap-x-1.5 px-1 text-[10px]"
                                        :class="
                                            row.message.fromClient
                                                ? 'justify-end self-end'
                                                : 'self-start'
                                        "
                                    >
                                        <span v-if="row.message.number"
                                            >{{
                                                row.message.fromClient
                                                    ? 'Texted from'
                                                    : 'Texted to'
                                            }}
                                            {{ row.message.number }} ·</span
                                        >
                                        <span v-if="row.message.id < 0"
                                            >Sending…</span
                                        >
                                        <time
                                            v-else
                                            :datetime="
                                                row.message.at ?? undefined
                                            "
                                            >{{
                                                row.message.at
                                                    ? timeFormatter.format(
                                                          new Date(
                                                              row.message.at,
                                                          ),
                                                      )
                                                    : ''
                                            }}</time
                                        >
                                    </span>
                                </MessageContent>
                            </Message>
                        </template>
                    </ol>

                    <div
                        v-if="conversation.thread.isClosed"
                        class="border-border/60 bg-muted/30 flex flex-col items-center gap-2 border-t p-4 text-center"
                    >
                        <p class="text-muted-foreground text-sm">
                            This conversation is closed. Need anything else?
                        </p>
                        <Button size="sm" @click="startOpen = true">
                            <Plus class="size-4" />
                            New message
                        </Button>
                    </div>
                    <Form
                        v-else
                        :action="routes.reply(conversation.thread.id)"
                        :options="{
                            preserveScroll: true,
                            // Only what a reply changes, so nothing else on the page reloads.
                            only: ['conversation', 'threads'],
                            reset: ['threads'],
                        }"
                        :optimistic="
                            (current, data) => ({
                                conversation: withPendingReply(
                                    current.conversation as ChatConversation,
                                    data.body,
                                ),
                            })
                        "
                        reset-on-success
                        class="border-border/60 flex flex-col gap-2 border-t p-3"
                        v-slot="{ errors, processing }"
                        @start="replyStarted"
                        @finish="sending = false"
                        @error="replyFailed"
                        @success="replySent"
                    >
                        <input
                            type="hidden"
                            name="idempotency_key"
                            :value="replyKey"
                        />
                        <FilePreviewInput
                            id="reply-attachments"
                            ref="replyAttachments"
                            name="attachments[]"
                            multiple
                            hide-input
                            :max="5"
                            accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.pdf,.doc,.docx"
                        />

                        <Label for="reply-body" class="sr-only"
                            >Your message</Label
                        >
                        <Textarea
                            id="reply-body"
                            v-model="replyBody"
                            name="body"
                            rows="2"
                            maxlength="1600"
                            class="max-h-40 min-h-16 resize-none"
                            placeholder="Write a message"
                            @keydown="sendOnEnter"
                        />
                        <InputError
                            :message="errors.body ?? attachmentError(errors)"
                        />
                        <div class="flex items-center justify-between gap-2">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Attach photos or files"
                                        @click="replyAttachments?.pick()"
                                    >
                                        <Paperclip class="size-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent
                                    >Attach photos or files</TooltipContent
                                >
                            </Tooltip>
                            <Button
                                type="submit"
                                :disabled="processing || !canSend"
                            >
                                <Spinner v-if="processing" />
                                <Send v-else class="size-4" />
                                Send
                            </Button>
                        </div>
                    </Form>
                </template>
            </Card>
        </div>
    </div>
</template>
