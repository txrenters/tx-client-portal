<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
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
        <SidebarGroupLabel class="text-muted-foreground text-xs font-medium">{{
            label
        }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    class="data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:font-medium"
                    as-child
                    :is-active="
                        !item.external && isCurrentOrParentUrl(item.href)
                    "
                    :tooltip="item.title"
                >
                    <a
                        v-if="item.external"
                        :href="
                            typeof item.href === 'string'
                                ? item.href
                                : item.href.url
                        "
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <ExternalLink
                            class="text-muted-foreground ml-auto size-3.5"
                            aria-hidden="true"
                        />
                    </a>
                    <Link v-else :href="item.href" prefetch>
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
