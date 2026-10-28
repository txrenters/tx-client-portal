<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';

/**
 * The logo at the top of the client and staff sidebars, full width and in
 * white against the navy sidebar, with the portal name set in small capitals
 * between two hairlines beneath it. Collapsed to icons, only the logo shows.
 */
defineProps<{
    href: NonNullable<InertiaLinkProps['href']>;
    label: string;
}>();
</script>

<template>
    <Link
        :href="href"
        class="focus-visible:ring-sidebar-ring flex min-w-0 flex-col items-center gap-3 rounded-lg px-3 pt-3 pb-2 outline-none group-data-[collapsible=icon]:px-0.5 group-data-[collapsible=icon]:py-2 focus-visible:ring-2"
    >
        <img
            src="/texas-renters-logo.png"
            alt="TexasRenters.com"
            class="h-auto w-full min-w-0 object-contain brightness-0 invert"
            width="400"
            height="111"
        />
        <span
            class="text-sidebar-foreground flex w-full items-center gap-3 text-xs font-bold tracking-[0.2em] uppercase group-data-[collapsible=icon]:hidden"
        >
            <span class="bg-sidebar-border h-px flex-1" />
            {{ label }}
            <span class="bg-sidebar-border h-px flex-1" />
        </span>
    </Link>
</template>
