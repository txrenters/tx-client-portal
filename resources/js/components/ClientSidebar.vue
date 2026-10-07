<script setup lang="ts">
import AccountSwitcher from '@/components/AccountSwitcher.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import SidebarBrand from '@/components/SidebarBrand.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { useClientNavigation } from '@/composables/useClientNavigation';
import { dashboard } from '@/routes';

const { mainNavGroups, secondaryNavItems } = useClientNavigation();
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <SidebarBrand :href="dashboard()" label="Client Portal" />
            <AccountSwitcher />
        </SidebarHeader>

        <SidebarContent class="gap-2 py-2">
            <NavMain
                v-for="group in mainNavGroups"
                :key="group.label"
                :items="group.items"
                :label="group.label"
            />
            <NavMain
                :items="secondaryNavItems"
                label="More"
                class="border-sidebar-border mt-auto border-t pt-2"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
