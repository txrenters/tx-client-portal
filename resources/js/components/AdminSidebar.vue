<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeftRight,
    Building2,
    CalendarClock,
    ChartColumn,
    Gift,
    LayoutGrid,
    LifeBuoy,
    Mail,
    Megaphone,
    Plug,
    ShieldAlert,
    ShieldCheck,
    ScrollText,
    Sparkles,
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
import {
    activity,
    dashboard,
    insights,
    properties,
    security,
} from '@/routes/admin';
import { index as announcements } from '@/routes/admin/announcements';
import { index as helpRequests } from '@/routes/admin/help-requests';
import { index as integrations } from '@/routes/admin/integrations';
import { index as invitations } from '@/routes/admin/invitations';
import { index as meetings } from '@/routes/admin/meetings';
import { index as promotions } from '@/routes/admin/promotions';
import { index as staff } from '@/routes/admin/staff';
import { index as referrals } from '@/routes/admin/referrals';
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
const clientItems = computed<NavItem[]>(() => [
    { title: 'Overview', href: dashboard(), icon: LayoutGrid },
    { title: 'Users', href: users(), icon: Users },
    { title: 'Invitations', href: invitations(), icon: Mail },
    { title: 'Find by property', href: properties(), icon: Building2 },
    { title: 'Help requests', href: helpRequests(), icon: LifeBuoy },
    { title: 'Insights', href: insights(), icon: ChartColumn },
]);

const outreachItems = computed<NavItem[]>(() => [
    { title: 'Announcements', href: announcements(), icon: Megaphone },
    { title: 'Meetings', href: meetings(), icon: CalendarClock },
    { title: 'Promotions', href: promotions(), icon: Sparkles },
    { title: 'Referrals', href: referrals(), icon: Gift },
]);

const page = usePage();

/** Staff only shows for those who may manage it: owners and admins. */
const systemItems = computed<NavItem[]>(() => [
    ...(page.props.auth.canManageStaff
        ? [{ title: 'Staff', href: staff(), icon: ShieldCheck }]
        : []),
    { title: 'Security', href: security(), icon: ShieldAlert },
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
            <NavMain :items="clientItems" label="Clients" />
            <NavMain :items="outreachItems" label="Outreach" />
            <NavMain :items="systemItems" label="System" />
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
