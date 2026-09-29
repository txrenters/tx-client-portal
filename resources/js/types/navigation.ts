import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Another site, opened in a new tab rather than visited in the portal. */
    external?: boolean;
};

/** A labelled run of sidebar links, so a long menu reads as a few short lists. */
export type NavGroup = {
    label: string;
    items: NavItem[];
};
