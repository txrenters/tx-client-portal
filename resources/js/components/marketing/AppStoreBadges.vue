<script setup lang="ts">
import { computed } from 'vue';

/**
 * App Store and Google Play badges for the TexasRenters.com mobile apps.
 *
 * Neither store listing is live yet, so a badge without a URL renders as a
 * "Coming soon" label rather than a dead link. Pass `iosUrl` / `androidUrl`
 * once the listings exist and the same badge becomes a real link.
 */
const {
    iosUrl = '',
    androidUrl = '',
    heading = '',
} = defineProps<{
    iosUrl?: string;
    androidUrl?: string;
    heading?: string;
}>();

type Store = {
    id: 'ios' | 'android';
    name: string;
    lead: string;
    url: string;
};

const stores = computed<Store[]>(() => [
    {
        id: 'ios',
        name: 'App Store',
        lead: 'Download on the',
        url: iosUrl,
    },
    {
        id: 'android',
        name: 'Google Play',
        lead: 'Get it on',
        url: androidUrl,
    },
]);
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
            <li v-for="store in stores" :key="store.id">
                <component
                    :is="store.url ? 'a' : 'span'"
                    :href="store.url || undefined"
                    :target="store.url ? '_blank' : undefined"
                    :rel="store.url ? 'noreferrer' : undefined"
                    :aria-disabled="store.url ? undefined : 'true'"
                    class="border-scrim-foreground/25 bg-scrim text-scrim-foreground flex items-center gap-3 rounded-xl border px-4 py-2.5"
                    :class="
                        store.url
                            ? 'hover:border-scrim-foreground/50 transition-colors duration-200'
                            : 'cursor-default opacity-70'
                    "
                >
                    <svg
                        v-if="store.id === 'ios'"
                        class="size-6 shrink-0"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"
                        />
                    </svg>
                    <svg
                        v-else
                        class="size-6 shrink-0"
                        viewBox="0 0 24 24"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path
                            d="M22.018 13.298l-3.919 2.218-3.515-3.493 3.543-3.521 3.891 2.202a1.49 1.49 0 0 1 0 2.594zM1.337.924a1.486 1.486 0 0 0-.112.568v21.017c0 .217.045.419.124.6l11.155-11.087zm12.207 10.065l3.258-3.238L3.45.195a1.466 1.466 0 0 0-.946-.179zm0 2.067l-11 10.933c.298.036.612-.016.903-.147l13.361-7.566z"
                        />
                    </svg>

                    <span class="flex flex-col leading-tight">
                        <span class="text-[11px]">
                            {{ store.url ? store.lead : 'Coming soon' }}
                        </span>
                        <span class="text-sm font-semibold">
                            {{ store.name }}
                        </span>
                    </span>
                </component>
            </li>
        </ul>
    </div>
</template>
