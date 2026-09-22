/**
 * Types for the staff-only admin dashboard.
 *
 * Everything here comes from the portal's own tables or from checking a
 * service, never from Propertyware.
 */

export type AdminStats = {
    users: {
        total: number;
        verified: number;
        unverified: number;
        withTwoFactor: number;
        joinedThisMonth: number;
    };
    accounts: {
        total: number;
        owners: number;
        residents: number;
        linkedUsers: number;
    };
    invitations: { pending: number; accepted: number };
    sessions: { mobileTokens: number; activeThisWeek: number };
    signups: { date: string; count: number }[];
};

/** Configured and answering, configured but untestable, or not set up at all. */
export type AdminIntegrationStatus =
    | 'ok'
    | 'failing'
    | 'configured'
    | 'not_configured';

export type AdminIntegration = {
    key: string;
    name: string;
    purpose: string;
    status: AdminIntegrationStatus;
    detail: string | null;
    checkedAt: string;
};

export type AdminQueueHealth = {
    failed: number;
    pending: number;
    lastFailureAt: string | null;
};

export type AdminActivityEvent = {
    type: string;
    at: string;
    summary: string;
    actor: string | null;
};

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    verifiedAt: string | null;
    twoFactor: boolean;
    joinedAt: string | null;
    accounts: { id: number; role: string; propertywareId: string | null }[];
    appSessions: number;
    appLastUsedAt: string | null;
};
