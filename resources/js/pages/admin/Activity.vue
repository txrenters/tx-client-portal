<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppCard from '@/components/AppCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import ActivityFeed from '@/components/admin/ActivityFeed.vue';
import type { AdminActivityEvent } from '@/types';

defineProps<{
    /** Undefined while the feed is still being read. */
    activity?: AdminActivityEvent[];
}>();
</script>

<template>
    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Head title="Activity" />

        <PageHeader
            title="Activity"
            description="What has happened in the portal lately, newest first."
        />

        <AppCard>
            <ActivityFeed :events="activity" />
            <p class="text-muted-foreground mt-6 text-sm text-pretty">
                Built from the timestamps the portal's own tables carry, so it
                shows what leaves a record behind. It is not a security audit
                log, and it does not include anything that happened in
                Propertyware.
            </p>
        </AppCard>
    </div>
</template>
