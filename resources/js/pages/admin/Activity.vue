<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppCard from '@/components/AppCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import ActivityFeed from '@/components/admin/ActivityFeed.vue';
import type { AdminActivityEvent } from '@/types';
import { dashboard as adminHome } from '@/routes/admin';
import { activity as activityCrumb } from '@/routes/admin';

/** The trail in the header beside the sidebar's collapse button. */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: adminHome() },
            { title: 'Activity', href: activityCrumb() },
        ],
    },
});

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
                Every action staff take in the admin area is recorded here with
                who took it. Client activity comes from the timestamps the
                portal's own tables carry, so it only shows what leaves a record
                behind. Owners and admins also see each change to a work order's
                approval or approval comment in Propertyware, wherever it was
                made, as Propertyware keeps no history of them.
            </p>
        </AppCard>
    </div>
</template>
