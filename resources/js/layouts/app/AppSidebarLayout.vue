<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
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
</script>

<template>
    <AppShell variant="sidebar">
        <ClientSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="@container/main relative isolate flex-1 pb-20 md:pb-0">
                <PageGlow />
                <slot />
            </div>
        </AppContent>
        <MobileNavigation />
        <Toaster />
    </AppShell>
</template>
