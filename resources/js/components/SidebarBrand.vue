<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';

/**
 * The navy logo block at the top of the client and staff sidebars. It uses
 * the same navy and white logo as the landing page header, so signing in
 * feels like stepping into the same product. The logo spans the full width
 * with the portal name set large beneath it. Collapsed to icons, only the
 * logo shows.
 */
defineProps<{
    href: NonNullable<InertiaLinkProps['href']>;
    label: string;
}>();
</script>

<template>
    <Link
        :href="href"
        class="bg-scrim text-scrim-foreground focus-visible:ring-sidebar-ring flex min-w-0 flex-col gap-2.5 rounded-lg px-4 py-4 outline-none group-data-[collapsible=icon]:items-center group-data-[collapsible=icon]:px-1 group-data-[collapsible=icon]:py-2 focus-visible:ring-2"
    >
        <img
            src="/texas-renters-logo.png"
            alt="TexasRenters.com"
            class="h-auto w-full min-w-0 object-contain brightness-0 invert"
            width="400"
            height="111"
        />
        <span
            class="border-scrim-foreground/20 text-scrim-foreground border-t pt-2.5 text-lg leading-tight font-semibold tracking-tight group-data-[collapsible=icon]:hidden"
        >
            {{ label }}
        </span>
    </Link>
</template>
