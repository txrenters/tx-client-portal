<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    {
        label: 'Menu',
    },
);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup v-if="items.length > 0" class="px-2 py-0">
        <SidebarGroupLabel
            class="text-muted-foreground/80 text-[11px] font-semibold tracking-wider uppercase"
            >{{ label }}</SidebarGroupLabel
        >
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    class="data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:font-medium"
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href" prefetch>
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
