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

const { mainNavItems, secondaryNavItems, availableRoles } =
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

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Main" />
            <NavMain :items="secondaryNavItems" label="Other" class="mt-auto" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
