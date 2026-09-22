import { usePage } from '@inertiajs/vue3';
import {
    Banknote,
    CalendarClock,
    Building2,
    CircleQuestionMark,
    FileText,
    Home,
    LayoutGrid,
    MessageSquare,
    ScrollText,
    ShieldCheck,
    Settings,
    User,
    Wrench,
} from '@lucide/vue';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import { dashboard as adminDashboard } from '@/routes/admin';
import { help } from '@/routes';
import {
    dashboard as ownerDashboard,
    documents as ownerDocuments,
    financials,
    portfolio,
} from '@/routes/owner';
import { index as ownerMaintenance } from '@/routes/owner/maintenance';
import { index as ownerMeetings } from '@/routes/owner/meetings';
import { index as ownerMessages } from '@/routes/owner/messages';
import { index as ownerProperties } from '@/routes/owner/properties';
import { edit as editProfile } from '@/routes/profile';
import {
    dashboard as residentDashboard,
    documents as residentDocuments,
    lease,
} from '@/routes/resident';
import { index as residentMaintenance } from '@/routes/resident/maintenance';
import { index as residentMessages } from '@/routes/resident/messages';
import type { ClientRole, NavItem } from '@/types';

export type UseClientNavigationReturn = {
    activeRole: ComputedRef<ClientRole | null>;
    availableRoles: ComputedRef<ClientRole[]>;
    mainNavItems: ComputedRef<NavItem[]>;
    secondaryNavItems: ComputedRef<NavItem[]>;
    mobileNavItems: ComputedRef<NavItem[]>;
};

const residentNavItems = (): NavItem[] => [
    { title: 'Dashboard', href: residentDashboard(), icon: Home },
    { title: 'Work Orders', href: residentMaintenance(), icon: Wrench },
    { title: 'Messages', href: residentMessages(), icon: MessageSquare },
    { title: 'Lease', href: lease(), icon: ScrollText },
    { title: 'Documents', href: residentDocuments(), icon: FileText },
];

const ownerNavItems = (): NavItem[] => [
    { title: 'Dashboard', href: ownerDashboard(), icon: Home },
    { title: 'Portfolio', href: portfolio(), icon: LayoutGrid },
    { title: 'My Ledger', href: financials(), icon: Banknote },
    { title: 'Properties', href: ownerProperties(), icon: Building2 },
    { title: 'Work Orders', href: ownerMaintenance(), icon: Wrench },
    { title: 'Messages', href: ownerMessages(), icon: MessageSquare },
    { title: 'Meetings', href: ownerMeetings(), icon: CalendarClock },
    { title: 'Documents', href: ownerDocuments(), icon: FileText },
];

/**
 * Navigation for the portal the client is currently using.
 */
export function useClientNavigation(): UseClientNavigationReturn {
    const page = usePage();
    const activeRole = computed(() => page.props.client?.activeRole ?? null);
    const availableRoles = computed(() => page.props.client?.roles ?? []);

    const mainNavItems = computed<NavItem[]>(() => {
        if (activeRole.value === 'owner') {
            return ownerNavItems();
        }

        return activeRole.value === 'resident' ? residentNavItems() : [];
    });

    const secondaryNavItems = computed<NavItem[]>(() => [
        // Staff only; the server decides, and every admin route checks again.
        ...(page.props.auth?.isAdmin
            ? [{ title: 'Admin', href: adminDashboard(), icon: ShieldCheck }]
            : []),
        { title: 'Help', href: help(), icon: CircleQuestionMark },
        { title: 'Profile', href: editProfile(), icon: Settings },
    ]);

    const mobileNavItems = computed<NavItem[]>(() => {
        const items = mainNavItems.value.filter((item) =>
            ['Dashboard', 'Work Orders', 'Messages', 'Properties'].includes(
                item.title,
            ),
        );

        const priority =
            activeRole.value === 'owner'
                ? ['Dashboard', 'Properties', 'Work Orders', 'Messages']
                : ['Dashboard', 'Work Orders', 'Messages'];

        return [
            ...priority
                .map((title) => items.find((item) => item.title === title))
                .filter((item): item is NavItem => item !== undefined),
            { title: 'Profile', href: editProfile(), icon: User },
        ];
    });

    return {
        activeRole,
        availableRoles,
        mainNavItems,
        secondaryNavItems,
        mobileNavItems,
    };
}
