<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useClientNavigation } from '@/composables/useClientNavigation';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';

const { mobileNavItems } = useClientNavigation();
const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <nav
        v-if="mobileNavItems.length > 0"
        class="border-border/70 bg-background/80 fixed inset-x-0 bottom-0 z-40 border-t pb-[env(safe-area-inset-bottom)] saturate-150 backdrop-blur-xl md:hidden"
        aria-label="Primary"
    >
        <ul class="flex items-stretch justify-around">
            <li
                v-for="item in mobileNavItems"
                :key="toUrl(item.href)"
                class="min-w-0 flex-1"
            >
                <Link
                    :href="item.href"
                    prefetch
                    class="flex min-h-[3.25rem] flex-col items-center justify-center gap-1 px-0.5 py-2 text-[11px] font-medium transition-colors duration-150"
                    :class="
                        isCurrentOrParentUrl(item.href)
                            ? 'text-primary'
                            : 'text-muted-foreground'
                    "
                    :aria-current="
                        isCurrentOrParentUrl(item.href) ? 'page' : undefined
                    "
                >
                    <component :is="item.icon" class="size-5" />
                    <!-- One line each, so a longer label like "Work Orders" never drops below the rest. -->
                    <span
                        class="max-w-full truncate leading-tight whitespace-nowrap"
                        >{{ item.title }}</span
                    >
                </Link>
            </li>
        </ul>
    </nav>
</template>
