<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeftRight,
    LayoutGrid,
    Plug,
    ScrollText,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard as clientDashboard } from '@/routes';
import { activity, dashboard } from '@/routes/admin';
import { index as integrations } from '@/routes/admin/integrations';
import { index as users } from '@/routes/admin/users';
import type { NavItem } from '@/types';

/**
 * The staff side's own navigation.
 *
 * Deliberately not the client sidebar: an admin is not looking at one
 * portfolio, so the portals, properties and ledger belong elsewhere. The last
 * item is the way back into the client portal for staff who also hold
 * accounts of their own.
 */
const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Overview', href: dashboard(), icon: LayoutGrid },
    { title: 'Users', href: users(), icon: Users },
    { title: 'Integrations', href: integrations(), icon: Plug },
    { title: 'Activity', href: activity(), icon: Activity },
]);
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
            <p
                class="text-muted-foreground px-2 text-xs font-medium tracking-wider uppercase group-data-[collapsible=icon]:hidden"
            >
                Staff
            </p>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child>
                        <a href="/log-viewer">
                            <ScrollText />
                            <span>Logs</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child>
                        <Link :href="clientDashboard()">
                            <ArrowLeftRight />
                            <span>Client portal</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
