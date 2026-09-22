<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { CircleAlert, RefreshCw, ScrollText } from '@lucide/vue';
import AppCard from '@/components/AppCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import IntegrationStatusList from '@/components/admin/IntegrationStatusList.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { refresh } from '@/routes/admin/integrations';
import type { AdminIntegration, AdminQueueHealth } from '@/types';

defineProps<{
    queue: AdminQueueHealth;
    /** Undefined while the services are being checked. */
    integrations?: AdminIntegration[];
}>();
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
        </AppCard>
    </div>
</template>
