export type ClientRole = 'resident' | 'owner';

export type ClientAccountOption = {
    id: number;
    label: string;
    reference: string;
    portal: string;
    active: boolean;
};

export type ClientPortal = {
    roles: ClientRole[];
    activeRole: ClientRole | null;
    /** Propertyware's own owner or tenant portal for the role in view. */
    propertywarePortalUrl: string | null;
    /** The referral reward, for an owner's sidebar card; null for residents. */
    referralReward: string | null;
    accounts: ClientAccountOption[];
};
