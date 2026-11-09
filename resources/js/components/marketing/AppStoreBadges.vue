<script setup lang="ts">
import { appStoreUrl, googlePlayUrl } from '@/lib/appStore';

/**
 * Where to get the mobile apps, with Apple's and Google's own badges, as their
 * guidelines ask. Google's is trimmed of its transparent margin only, so both
 * badges stand the same height.
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
            <li>
                <a
                    :href="googlePlayUrl"
                    target="_blank"
                    rel="noopener"
                    class="focus-visible:ring-ring/50 block rounded-lg outline-none focus-visible:ring-4"
                >
                    <img
                        src="/images/app/get-it-on-google-play.png"
                        alt="Get it on Google Play"
                        width="564"
                        height="168"
                        class="h-[3.25rem] w-auto"
                    />
                </a>
            </li>
        </ul>
    </div>
</template>
