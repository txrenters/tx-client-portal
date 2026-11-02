<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminSidebar from '@/components/AdminSidebar.vue';
import AnnouncementBanner from '@/components/AnnouncementBanner.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import ClientSidebar from '@/components/ClientSidebar.vue';
import MobileNavigation from '@/components/MobileNavigation.vue';
import PageGlow from '@/components/PageGlow.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();

/**
 * Staff reach Settings from the admin area, so there it keeps the admin
 * navigation rather than dropping them into the client portal's. Not while
 * signed in as a client: then the page is the client's.
 */
const staffSettings = computed(
    () =>
        page.props.auth.isAdmin &&
        page.props.auth.portalSide === 'staff' &&
        !page.props.auth.impersonating &&
        page.url.startsWith('/settings'),
);
</script>

<template>
    <AppShell variant="sidebar">
        <AdminSidebar v-if="staffSettings" />
        <ClientSidebar v-else />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <ImpersonationBanner />
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="@container/main relative isolate flex-1 pb-20 md:pb-0">
                <PageGlow />
                <AnnouncementBanner />
                <slot />
            </div>
        </AppContent>
        <MobileNavigation v-if="!staffSettings" />
        <Toaster />
    </AppShell>
</template>
