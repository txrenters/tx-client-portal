<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { detailsOnFile } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const page = usePage();

/** Details on file only exist for logins linked to a client account. */
const sidebarNavItems = computed<NavItem[]>(() => [
    {
        title: 'Profile',
        href: editProfile(),
    },
    ...((page.props.client?.accounts.length ?? 0) > 0
        ? [{ title: 'Details on file', href: detailsOnFile() }]
        : []),
    {
        title: 'Security',
        href: editSecurity(),
    },
    {
        title: 'Notifications',
        href: editNotifications(),
    },
    {
        title: 'Appearance',
        href: editAppearance(),
    },
]);

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <!-- One scrollable row on a phone, a sidebar from lg up. -->
                <nav
                    class="-mx-4 flex [scrollbar-width:none] gap-1 overflow-x-auto px-4 pb-1 lg:mx-0 lg:flex-col lg:gap-0 lg:space-y-1 lg:overflow-visible lg:px-0 lg:pb-0 [&::-webkit-scrollbar]:hidden"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'shrink-0 justify-start lg:w-full',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
