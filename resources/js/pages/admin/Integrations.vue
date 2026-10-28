<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    CircleAlert,
    RefreshCw,
    RotateCcw,
    ScrollText,
    Trash2,
} from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import ConfirmAction from '@/components/admin/ConfirmAction.vue';
import IntegrationStatusList from '@/components/admin/IntegrationStatusList.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { appDateFormatter } from '@/lib/timezone';
import { refresh } from '@/routes/admin/integrations';
import { forget, retry } from '@/routes/admin/integrations/failed-jobs';
import type {
    AdminFailedJob,
    AdminIntegration,
    AdminQueueHealth,
} from '@/types';
import { dashboard as adminHome } from '@/routes/admin';
import { index as integrationsCrumb } from '@/routes/admin/integrations';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Integrations', href: integrationsCrumb() },
        ],
    },
});

defineProps<{
    queue: AdminQueueHealth;
    failedJobs: AdminFailedJob[];
    /** Undefined while the services are being checked. */
    integrations?: AdminIntegration[];
}>();

const failedAt = appDateFormatter({ dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Integrations" />

        <PageHeader
            title="Integrations"
            description="Every service the portal depends on, and whether it is answering right now."
        >
            <template #actions>
                <Form v-bind="refresh.form()" #default="{ processing }">
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" class="size-4" />
                        <RefreshCw v-else class="size-4" />
                        Check again
                    </Button>
                </Form>
            </template>
        </PageHeader>

        <AppCard>
            <IntegrationStatusList :integrations="integrations" />
        </AppCard>

        <AppCard
            title="Background work"
            description="Jobs the portal runs out of sight: syncing ledgers, sending mail, pushing approvals."
        >
            <div class="flex flex-wrap gap-6">
                <div>
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ queue.pending }}
                    </p>
                    <p class="text-muted-foreground text-sm">Waiting to run</p>
                </div>
                <div>
                    <p
                        class="text-3xl font-semibold tabular-nums"
                        :class="queue.failed > 0 ? 'text-destructive' : ''"
                    >
                        {{ queue.failed }}
                    </p>
                    <p class="text-muted-foreground text-sm">Failed</p>
                </div>
            </div>

            <div
                v-if="queue.failed > 0"
                class="border-destructive/40 bg-destructive/5 mt-4 flex items-start gap-3 rounded-lg border p-3"
            >
                <CircleAlert class="text-destructive mt-0.5 size-5 shrink-0" />
                <div class="space-y-2">
                    <p class="text-sm text-pretty">
                        Failed jobs usually mean an integration broke while
                        nobody was watching. The log says which one and why.
                        <span v-if="queue.lastFailureAt">
                            Most recent failure: {{ queue.lastFailureAt }}.
                        </span>
                    </p>
                    <Button as-child variant="outline" size="sm">
                        <a href="/log-viewer">
                            <ScrollText class="size-4" />
                            Open the log
                        </a>
                    </Button>
                </div>
            </div>

            <ul
                v-if="failedJobs.length > 0"
                class="divide-border/60 mt-4 divide-y border-t"
            >
                <li
                    v-for="job in failedJobs"
                    :key="job.uuid"
                    class="flex flex-wrap items-start justify-between gap-3 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">
                            {{ job.job }}
                            <span class="text-muted-foreground font-normal"
                                >on {{ job.queue }} ·
                                {{
                                    failedAt.format(new Date(job.failedAt))
                                }}</span
                            >
                        </p>
                        <p
                            class="text-muted-foreground mt-1 text-xs break-words"
                        >
                            {{ job.error }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Form
                            v-bind="retry.form(job.uuid)"
                            :options="{ preserveScroll: true }"
                            #default="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" class="size-4" />
                                <RotateCcw v-else class="size-4" />
                                Retry
                            </Button>
                        </Form>
                        <ConfirmAction
                            title="Discard this failed job?"
                            description="It will not run again, and the record of why it failed is gone. Retry it instead if the work still needs doing."
                            confirm-label="Discard"
                            destructive
                            :action="forget(job.uuid)"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                            >
                                <Trash2 class="size-4" />
                                Discard
                            </Button>
                        </ConfirmAction>
                    </div>
                </li>
            </ul>

            <div
                v-if="queue.failingSchedules.length > 0"
                class="border-border/60 mt-4 border-t pt-4"
            >
                <p class="text-sm font-medium">Scheduled jobs failing now</p>
                <p class="text-muted-foreground text-xs text-pretty">
                    The owners were emailed when each started failing. They
                    clear once the job runs successfully again.
                </p>
                <ul class="divide-border/60 mt-2 divide-y">
                    <li
                        v-for="schedule in queue.failingSchedules"
                        :key="schedule.command"
                        class="py-3"
                    >
                        <p class="text-sm font-medium">
                            {{ schedule.command }}
                            <span class="text-muted-foreground font-normal"
                                >failing since
                                {{ failedAt.format(new Date(schedule.since)) }}
                                · last
                                {{
                                    failedAt.format(
                                        new Date(schedule.lastFailedAt),
                                    )
                                }}</span
                            >
                        </p>
                        <p class="text-destructive mt-1 text-xs break-words">
                            {{ schedule.reason }}
                        </p>
                    </li>
                </ul>
            </div>
        </AppCard>
    </div>
</template>
