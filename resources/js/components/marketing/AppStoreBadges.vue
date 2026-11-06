<script setup lang="ts">
import { appStoreUrl } from '@/lib/appStore';

/**
 * Where to get the mobile apps. The App Store link uses Apple's own badge,
 * unaltered, as its guidelines ask. Android is not on Google Play yet, so it
 * is a label rather than a link; swap it for Google's badge when it launches.
 */
</script>

<template>
    <ul class="flex flex-wrap items-center gap-3">
        <li>
            <a
                :href="appStoreUrl"
                target="_blank"
                rel="noopener"
                class="focus-visible:ring-ring/50 block rounded-lg outline-none focus-visible:ring-4"
            >
                <img
                    src="/images/app/download-on-the-app-store.svg"
                    alt="Download on the App Store"
                    width="150"
                    height="50"
                    class="h-[3.25rem] w-auto"
                />
            </a>
        </li>
        <li
            class="border-scrim-foreground/25 text-scrim-foreground/80 flex h-[3.25rem] flex-col justify-center rounded-lg border px-4 leading-tight"
        >
            <span class="text-[0.7rem]">Coming soon</span>
            <span class="text-sm font-semibold">Android</span>
        </li>
    </ul>
</template>
