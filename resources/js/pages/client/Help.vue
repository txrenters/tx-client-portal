<script setup lang="ts">
import {
    Form,
    Head,
    InfiniteScroll,
    Link,
    setLayoutProps,
    usePage,
} from '@inertiajs/vue3';
import { LifeBuoy, MessageSquare, Send } from '@lucide/vue';
import { computed, ref, watchEffect } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import FilePreviewInput from '@/components/FilePreviewInput.vue';
import HelpAttachments from '@/components/HelpAttachments.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { appDateFormatter } from '@/lib/timezone';
import { help } from '@/routes';
import { store } from '@/routes/help';
import { dashboard as ownerDashboard } from '@/routes/owner';
import { index as ownerMessages } from '@/routes/owner/messages';
import { dashboard as residentDashboard } from '@/routes/resident';
import { index as residentMessages } from '@/routes/resident/messages';
import type { HelpRequest } from '@/types';

/**
 * Ask TexasRenters.com for help with the portal or the app itself. Anything
 * about a property goes through Messages instead. The apps use
 * /api/v1/me/help-requests.
 */
defineProps<{
    topics: { value: string; label: string }[];
    /** Loaded page by page as the list scrolls. */
    requests?: { data: HelpRequest[] };
}>();

const page = usePage();
const role = computed(() => page.props.client?.activeRole ?? null);

watchEffect(() => {
    const here = { title: 'Help', href: help() };

    setLayoutProps({
        breadcrumbs:
            role.value === null
                ? [here]
                : [
                      {
                          title: 'Home',
                          href:
                              role.value === 'owner'
                                  ? ownerDashboard()
                                  : residentDashboard(),
                      },
                      here,
                  ],
    });
});

const messagesRoute = computed(() => {
    if (role.value === 'owner') {
        return ownerMessages();
    }

    return role.value === 'resident' ? residentMessages() : null;
});

const topic = ref('');

const sentFormatter = appDateFormatter({ dateStyle: 'medium' });

/** The first error about the attached files, whichever file it was. */
function attachmentError(errors: Record<string, string>): string | undefined {
    return (
        errors.attachments ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('attachments.'),
        )?.[1]
    );
}
</script>

<template>
    <Head title="Help" />

    <div class="flex flex-col gap-6 p-4">
        <PageHeader
            title="Help"
            description="Trouble with the portal or the app? Tell us what's going on and our team will sort it out."
        />

        <Alert>
            <MessageSquare />
            <AlertTitle>About your property, lease or payments?</AlertTitle>
            <AlertDescription>
                <span>
                    This page is for problems with the portal and the app. For
                    anything else,
                    <Link
                        v-if="messagesRoute"
                        :href="messagesRoute"
                        class="text-primary hover:underline"
                        >send us a message</Link
                    ><template v-else>send us a message</template> and the right
                    team will answer.
                </span>
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <AppCard
                title="Ask for help"
                description="The more detail you give, the faster we can fix it."
            >
                <Form
                    :action="store()"
                    reset-on-success
                    :options="{
                        preserveScroll: true,
                        only: ['requests'],
                        reset: ['requests'],
                    }"
                    class="grid gap-4"
                    v-slot="{ errors, processing }"
                    @success="topic = ''"
                >
                    <div class="grid gap-2">
                        <Label for="topic">What is it about?</Label>
                        <Select v-model="topic" name="topic">
                            <SelectTrigger id="topic" class="w-full">
                                <SelectValue placeholder="Choose a topic" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in topics"
                                    :key="option.value"
                                    :value="option.value"
                                    >{{ option.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.topic" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="message">What happened?</Label>
                        <Textarea
                            id="message"
                            name="message"
                            rows="5"
                            maxlength="3000"
                            placeholder="What you were trying to do, what you saw instead, and on which page"
                            required
                        />
                        <InputError :message="errors.message" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="help-attachments">
                            Screenshots or files
                            <span class="text-muted-foreground font-normal"
                                >(optional, up to 5)</span
                            >
                        </Label>
                        <FilePreviewInput
                            id="help-attachments"
                            name="attachments[]"
                            multiple
                            :max="5"
                            accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.pdf"
                        />
                        <p class="text-muted-foreground text-xs">
                            A screenshot of what you see helps us fix it faster.
                        </p>
                        <InputError :message="attachmentError(errors)" />
                    </div>

                    <div>
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />
                            <Send v-else class="size-4" />
                            Send to our team
                        </Button>
                    </div>
                </Form>
            </AppCard>

            <AppCard
                title="Your requests"
                description="What you've asked us, and what we did about it."
            >
                <div
                    v-if="requests === undefined"
                    class="flex flex-col gap-2"
                    role="status"
                    aria-label="Loading help requests"
                >
                    <Skeleton
                        v-for="item in 3"
                        :key="item"
                        class="h-14 w-full rounded-xl"
                    />
                </div>

                <EmptyState
                    v-else-if="requests.data.length === 0"
                    :icon="LifeBuoy"
                    title="No requests yet"
                    description="Anything you ask us will show up here."
                />

                <InfiniteScroll
                    v-else
                    data="requests"
                    as="ul"
                    class="divide-border/60 divide-y"
                >
                    <li
                        v-for="request in requests.data"
                        :key="request.id"
                        class="flex flex-col gap-1 py-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-medium">{{ request.topicLabel }}</p>
                            <Badge
                                :variant="
                                    request.status === 'resolved'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ request.statusLabel }}</Badge
                            >
                        </div>
                        <p class="text-muted-foreground line-clamp-2 text-sm">
                            {{ request.message }}
                        </p>
                        <HelpAttachments :attachments="request.attachments" />
                        <p
                            v-if="request.staffNote"
                            class="bg-muted rounded-md p-2 text-sm whitespace-pre-line"
                        >
                            {{ request.staffNote }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Sent
                            {{ sentFormatter.format(new Date(request.sentAt)) }}
                        </p>
                    </li>
                </InfiniteScroll>
            </AppCard>
        </div>
    </div>
</template>
