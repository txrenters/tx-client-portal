import { usePage } from "@inertiajs/vue3";
import {
    Banknote,
    CalendarClock,
    Building2,
    Building,
    CircleQuestionMark,
    FileText,
    Gift,
    Home,
    LayoutGrid,
    MessageSquare,
    ScrollText,
    ShieldCheck,
    User,
    Wrench,
} from "@lucide/vue";
import type { ComputedRef } from "vue";
import { computed } from "vue";
import { dashboard as adminDashboard } from "@/routes/admin";
import { help } from "@/routes";
import {
    dashboard as ownerDashboard,
    documents as ownerDocuments,
    financials,
    portfolio,
} from "@/routes/owner";
import { index as ownerMaintenance } from "@/routes/owner/maintenance";
import { index as ownerMeetings } from "@/routes/owner/meetings";
import { index as ownerMessages } from "@/routes/owner/messages";
import { index as ownerProperties } from "@/routes/owner/properties";
import { index as ownerReferrals } from "@/routes/owner/referrals";
import { edit as editProfile } from "@/routes/profile";
import {
    dashboard as residentDashboard,
    documents as residentDocuments,
    lease,
} from "@/routes/resident";
import { index as residentMaintenance } from "@/routes/resident/maintenance";
import { index as residentMessages } from "@/routes/resident/messages";
import { index as residentReferrals } from "@/routes/resident/referrals";
import type { ClientRole, NavGroup, NavItem } from "@/types";

export type UseClientNavigationReturn = {
    activeRole: ComputedRef<ClientRole | null>;
    mainNavGroups: ComputedRef<NavGroup[]>;
    secondaryNavItems: ComputedRef<NavItem[]>;
    mobileNavItems: ComputedRef<NavItem[]>;
};

/**
 * Residents have a short menu, so it splits in three: where they land, what
 * they do about their home, and the paperwork they look up.
 */
const residentNavGroups = (): NavGroup[] => [
    {
        label: "Overview",
        items: [{ title: "Dashboard", href: residentDashboard(), icon: Home }],
    },
    {
        label: "My home",
        items: [
            { title: "Work Orders", href: residentMaintenance(), icon: Wrench },
            {
                title: "Messages",
                href: residentMessages(),
                icon: MessageSquare,
            },
        ],
    },
    {
        label: "Records",
        items: [
            { title: "Lease", href: lease(), icon: ScrollText },
            { title: "Documents", href: residentDocuments(), icon: FileText },
        ],
    },
];

/**
 * Owners have the longer menu, grouped by the job at hand rather than one
 * nine-item list: how the portfolio is doing, the properties themselves, the
 * money, and talking to us.
 */
const ownerNavGroups = (): NavGroup[] => [
    {
        label: "Overview",
        items: [
            { title: "Dashboard", href: ownerDashboard(), icon: Home },
            { title: "Portfolio", href: portfolio(), icon: LayoutGrid },
        ],
    },
    {
        label: "Properties",
        items: [
            { title: "Properties", href: ownerProperties(), icon: Building2 },
            { title: "Work Orders", href: ownerMaintenance(), icon: Wrench },
        ],
    },
    {
        label: "Finances",
        items: [
            { title: "My Ledger", href: financials(), icon: Banknote },
            { title: "Documents", href: ownerDocuments(), icon: FileText },
        ],
    },
    {
        label: "Connect",
        items: [
            { title: "Messages", href: ownerMessages(), icon: MessageSquare },
            {
                title: "Meetings",
                href: ownerMeetings(),
                icon: CalendarClock,
            },
        ],
    },
];

/**
 * Referrals are a one-off rather than daily work, so they sit with the other
 * extras at the foot of the sidebar instead of in the main menu.
 */
const referralNavItems = (role: ClientRole | null): NavItem[] => {
    if (role === "owner") {
        return [
            { title: "Refer a friend", href: ownerReferrals(), icon: Gift },
        ];
    }

    if (role === "resident") {
        return [
            { title: "Refer a friend", href: residentReferrals(), icon: Gift },
        ];
    }

    return [];
};

/**
 * Navigation for the portal the client is currently using.
 */
export function useClientNavigation(): UseClientNavigationReturn {
    const page = usePage();
    const activeRole = computed(() => page.props.client?.activeRole ?? null);

    const mainNavGroups = computed<NavGroup[]>(() => {
        if (activeRole.value === "owner") {
            return ownerNavGroups();
        }

        return activeRole.value === "resident" ? residentNavGroups() : [];
    });

    const secondaryNavItems = computed<NavItem[]>(() => [
        ...referralNavItems(activeRole.value),
        // Staff only; the server decides, and every admin route checks again.
        // Staff with client accounts of their own switch with the account switcher instead.
        ...(page.props.auth?.isAdmin &&
        (page.props.client?.accounts ?? []).length === 0
            ? [{ title: "Admin", href: adminDashboard(), icon: ShieldCheck }]
            : []),
        { title: "Help", href: help(), icon: CircleQuestionMark },
        // Propertyware's own owner or tenant portal, for what this portal does not do itself.
        ...(page.props.client?.propertywarePortalUrl
            ? [
                  {
                      title: "PropertyWare Portal",
                      href: page.props.client.propertywarePortalUrl,
                      icon: Building,
                      external: true,
                  },
              ]
            : []),
        // No Profile here: the name menu at the foot of the sidebar opens
        // the same Settings page. Phones keep it in the bottom bar.
    ]);

    const mobileNavItems = computed<NavItem[]>(() => {
        const items = mainNavGroups.value
            .flatMap((group) => group.items)
            .filter((item) =>
                ["Dashboard", "Work Orders", "Messages", "Properties"].includes(
                    item.title,
                ),
            );

        const priority =
            activeRole.value === "owner"
                ? ["Dashboard", "Properties", "Work Orders", "Messages"]
                : ["Dashboard", "Work Orders", "Messages"];

        return [
            ...priority
                .map((title) => items.find((item) => item.title === title))
                .filter((item): item is NavItem => item !== undefined),
            { title: "Profile", href: editProfile(), icon: User },
        ];
    });

    return {
        activeRole,
        mainNavGroups,
        secondaryNavItems,
        mobileNavItems,
    };
}
