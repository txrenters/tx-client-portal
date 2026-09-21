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
    accounts: ClientAccountOption[];
};
