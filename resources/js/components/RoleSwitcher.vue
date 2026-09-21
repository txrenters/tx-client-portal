<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, ChevronsUpDown } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useClientNavigation } from '@/composables/useClientNavigation';
import { dashboard as ownerDashboard } from '@/routes/owner';
import { dashboard as residentDashboard } from '@/routes/resident';
import type { ClientRole } from '@/types';

const { activeRole, availableRoles } = useClientNavigation();

const portalLabels: Record<ClientRole, string> = {
    resident: 'Resident portal',
    owner: 'Owner portal',
};

const switchTo = (role: ClientRole) => {
    router.visit(role === 'owner' ? ownerDashboard() : residentDashboard());
};
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        class="text-muted-foreground"
                        data-test="role-switcher"
                    >
                        <span class="truncate">{{
                            activeRole ? portalLabels[activeRole] : 'Portal'
                        }}</span>
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-48"
                    align="start"
                >
                    <DropdownMenuItem
                        v-for="role in availableRoles"
                        :key="role"
                        class="cursor-pointer"
                        @select="switchTo(role)"
                    >
                        {{ portalLabels[role] }}
                        <Check
                            v-if="role === activeRole"
                            class="ml-auto size-4"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
