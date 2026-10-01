<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';

/**
 * The navy logo block at the top of the client and staff sidebars. It uses
 * the same navy and white logo as the landing page header, so signing in
 * feels like stepping into the same product. Collapsed to icons, only the
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
        class="bg-scrim text-scrim-foreground focus-visible:ring-sidebar-ring flex min-w-0 items-center gap-3 rounded-lg px-3 py-3 outline-none group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-1 group-data-[collapsible=icon]:py-2 focus-visible:ring-2"
    >
        <img
            src="/texas-renters-logo.png"
            alt="TexasRenters.com"
            class="h-7 w-auto min-w-0 shrink object-contain brightness-0 invert"
            width="400"
            height="111"
        />
        <span
            class="border-scrim-foreground/25 text-scrim-foreground/75 shrink-0 border-l pl-3 text-xs group-data-[collapsible=icon]:hidden"
        >
            {{ label }}
        </span>
    </Link>
</template>
