<script setup lang="ts">
import { appStoreUrl } from '@/lib/appStore';

/**
 * Where to get the mobile apps. The App Store link uses Apple's own badge,
 * unaltered, as its guidelines ask. Android is not on Google Play yet, so it
 * is a label rather than a link; swap it for Google's badge when it launches.
 * The label takes its colour from where it sits, as these badges appear on both
 * the navy panels and the plain page background.
 */
const { heading = '' } = defineProps<{
    heading?: string;
}>();
</script>

<template>
    <div class="flex flex-col gap-3">
        <p
            v-if="heading"
            class="text-xs font-medium tracking-wider uppercase opacity-70"
        >
            {{ heading }}
        </p>

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
                class="flex h-[3.25rem] flex-col justify-center rounded-lg border border-current/25 px-4 leading-tight opacity-80"
            >
                <span class="text-[0.7rem]">Coming soon</span>
                <span class="text-sm font-semibold">Android</span>
            </li>
        </ul>
    </div>
</template>
