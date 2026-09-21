<script setup lang="ts">
import { Head, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Hammer } from '@lucide/vue';
import { watchEffect } from 'vue';
import AppCard from '@/components/AppCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { dashboard as ownerDashboard } from '@/routes/owner';
import { dashboard as residentDashboard } from '@/routes/resident';

const props = defineProps<{
    title: string;
}>();

const page = usePage();

/**
 * The header beside the sidebar's collapse button. This page stands in for
 * several sections, so its own crumb follows whichever one is open.
 */
watchEffect(() => {
    const role = page.props.client?.activeRole ?? null;
    const here = { title: props.title, href: page.url };

    setLayoutProps({
        breadcrumbs:
            role === null
                ? [here]
                : [
                      {
                          title: 'Home',
                          href:
                              role === 'owner'
                                  ? ownerDashboard()
                                  : residentDashboard(),
                      },
                      here,
                  ],
    });
});
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>

        <AppCard>
            <EmptyState
                :icon="Hammer"
                title="Coming soon"
                :description="`${title} isn't available yet. We're connecting it to your account and it will show up here soon.`"
            />
        </AppCard>
    </div>
</template>
