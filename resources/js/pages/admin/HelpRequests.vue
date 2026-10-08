<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleCheck, LifeBuoy, RotateCcw, Search } from '@lucide/vue';
import { refDebounced } from '@vueuse/core';
import { reactive, ref, watch } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import HelpAttachments from '@/components/HelpAttachments.vue';
import PageHeader from '@/components/PageHeader.vue';
import ResultsPagination from '@/components/ResultsPagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { appDateFormatter } from '@/lib/timezone';
import {
    index as helpRequestsRoute,
    update as updateRoute,
} from '@/routes/admin/help-requests';
import { show as showUser } from '@/routes/admin/users';
import type { AdminHelpRequest, Paginated } from '@/types';
import { dashboard as adminHome } from '@/routes/admin';
import { index as helpRequestsCrumb } from '@/routes/admin/help-requests';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Help requests', href: helpRequestsCrumb() },
        ],
    },
});

const props = defineProps<{
    status: 'open' | 'resolved' | 'all';
    search: string;
    openCount: number;
    requests: Paginated<AdminHelpRequest>;
}>();

const status = ref(props.status);
const search = ref(props.search);
const debouncedSearch = refDebounced(search, 300);

function reload(page?: number): void {
    router.get(
        helpRequestsRoute().url,
        { status: status.value, search: search.value || undefined, page },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([status, debouncedSearch], () => reload());

/** The note being written for each request, kept per row. */
const notes = reactive<Record<number, string>>({});
const saving = ref<number | null>(null);

function settle(request: AdminHelpRequest, next: 'open' | 'resolved'): void {
    router.patch(
        updateRoute(request.id).url,
        { status: next, staff_note: notes[request.id] ?? null },
        {
            preserveScroll: true,
            onStart: () => (saving.value = request.id),
            onFinish: () => (saving.value = null),
            onSuccess: () => delete notes[request.id],
        },
    );
}

const platformLabels: Record<string, string> = {
    web: 'Web portal',
    ios: 'iPhone app',
    android: 'Android app',
    app: 'App',
};

const sent = appDateFormatter({ dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Help requests" />

        <PageHeader
            title="Help requests"
            description="Problems clients reported with the portal or the app, from the Help page on the web and in the app."
        />

        <div class="flex flex-wrap items-center gap-3">
            <Tabs v-model="status">
                <TabsList>
                    <TabsTrigger value="open"
                        >Open
                        <Badge
                            v-if="openCount > 0"
                            variant="secondary"
                            class="ml-1"
                            >{{ openCount }}</Badge
                        ></TabsTrigger
                    >
                    <TabsTrigger value="resolved">Resolved</TabsTrigger>
                    <TabsTrigger value="all">All</TabsTrigger>
                </TabsList>
            </Tabs>
            <div class="relative w-full max-w-sm">
                <Search
                    class="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="search"
                    class="pl-9"
                    placeholder="Search person or message"
                    aria-label="Search help requests"
                />
            </div>
        </div>

        <AppCard v-if="requests.data.length === 0">
            <EmptyState
                :icon="LifeBuoy"
                title="Nothing here"
                :description="
                    status === 'open' && !search
                        ? 'Every help request has been dealt with.'
                        : 'No help requests match this search.'
                "
            />
        </AppCard>

        <AppCard v-for="request in requests.data" :key="request.id">
            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">{{ request.topicLabel }}</p>
                        <p class="text-muted-foreground text-sm">
                            <Link
                                :href="showUser(request.user.id)"
                                class="text-primary hover:underline"
                                >{{ request.user.name }}</Link
                            >
                            · {{ request.user.email }} ·
                            {{ sent.format(new Date(request.sentAt)) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge variant="outline">{{
                            platformLabels[request.platform] ?? request.platform
                        }}</Badge>
                        <Badge
                            :variant="
                                request.status === 'open'
                                    ? 'default'
                                    : 'secondary'
                            "
                            >{{ request.statusLabel }}</Badge
                        >
                    </div>
                </div>

                <p class="text-sm whitespace-pre-line">{{ request.message }}</p>

                <HelpAttachments :attachments="request.attachments" />

                <p
                    v-if="request.appVersion || request.userAgent"
                    class="text-muted-foreground text-xs break-all"
                >
                    <span v-if="request.appVersion"
                        >App version {{ request.appVersion }}.
                    </span>
                    {{ request.userAgent }}
                </p>

                <div
                    v-if="request.status === 'open'"
                    class="border-border/60 flex flex-col gap-2 border-t pt-4"
                >
                    <Label :for="`note-${request.id}`">
                        Note for the client
                        <span class="text-muted-foreground font-normal"
                            >(optional, shown on their Help page)</span
                        >
                    </Label>
                    <Textarea
                        :id="`note-${request.id}`"
                        v-model="notes[request.id]"
                        rows="2"
                        maxlength="2000"
                        placeholder="What was wrong, and what you did about it"
                    />
                    <div>
                        <Button
                            size="sm"
                            :disabled="saving === request.id"
                            @click="settle(request, 'resolved')"
                        >
                            <Spinner v-if="saving === request.id" />
                            <CircleCheck v-else class="size-4" />
                            Mark resolved
                        </Button>
                    </div>
                </div>

                <div
                    v-else
                    class="border-border/60 flex flex-wrap items-end justify-between gap-3 border-t pt-4"
                >
                    <div class="min-w-0 text-sm">
                        <p v-if="request.staffNote" class="whitespace-pre-line">
                            {{ request.staffNote }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Resolved
                            <span v-if="request.resolvedAt">{{
                                sent.format(new Date(request.resolvedAt))
                            }}</span>
                            <span v-if="request.resolvedBy">
                                by {{ request.resolvedBy }}</span
                            >
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="saving === request.id"
                        @click="settle(request, 'open')"
                    >
                        <Spinner v-if="saving === request.id" />
                        <RotateCcw v-else class="size-4" />
                        Reopen
                    </Button>
                </div>
            </div>
        </AppCard>

        <ResultsPagination
            :results="requests"
            @change="(page) => reload(page)"
        />
    </div>
</template>
