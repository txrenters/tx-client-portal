<script setup lang="ts">
import AdminSidebar from '@/components/AdminSidebar.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

/**
 * The staff shell. Same furniture as the client one, its own navigation, and
 * no mobile portal bar: admin work is a desk job.
 */
const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppShell variant="sidebar">
        <AdminSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="@container/main relative isolate flex-1">
                <slot />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
