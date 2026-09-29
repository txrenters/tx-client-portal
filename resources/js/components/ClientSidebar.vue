<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AccountSwitcher from '@/components/AccountSwitcher.vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import RoleSwitcher from '@/components/RoleSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useClientNavigation } from '@/composables/useClientNavigation';
import { dashboard } from '@/routes';

const { mainNavGroups, secondaryNavItems, availableRoles } =
    useClientNavigation();
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        class="h-auto justify-center p-0 hover:bg-transparent active:bg-transparent"
                        as-child
                    >
                        <Link :href="dashboard()" class="justify-center p-0">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <AccountSwitcher />
            <RoleSwitcher v-if="availableRoles.length > 1" />
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
