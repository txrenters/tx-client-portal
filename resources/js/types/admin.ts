/**
 * Types for the staff-only admin dashboard.
 *
 * Everything here comes from the portal's own tables or from checking a
 * service, never from Propertyware.
 */

import type { HelpRequestAttachment } from './portal';

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
    /** Set while staff have shut the login out. */
    suspendedAt: string | null;
    /** Staff logins, which cannot be impersonated. */
    isStaff: boolean;
    /** One of the App Review logins, which see made-up records. */
    isDemo: boolean;
    joinedAt: string | null;
    accounts: { id: number; role: string; propertywareId: string | null }[];
    appSessions: number;
    appLastUsedAt: string | null;
};

/** A referred property owner, with the details staff follow up on. */
export type AdminReferral = {
    id: number;
    name: string;
    firstName: string;
    lastName: string;
    email: string;
    phone: string;
    propertyAddress: string;
    propertyDetails: string | null;
    source: string;
    referrerName: string;
    referrerRole: 'owner' | 'resident';
    status: 'new' | 'contacted' | 'signed' | 'not_a_fit';
    statusLabel: string;
    statusChangedAt: string | null;
    sentAt: string;
    /** When the website's leads webhook accepted it; null while pending or failing. */
    leadSentAt: string | null;
    leadError: string | null;
};

/** A promotion popup with how it has done, for staff. */
export type AdminPromotion = {
    id: number;
    title: string;
    body: string;
    buttonLabel: string;
    buttonLink: string;
    theme: string;
    icon: string;
    link: { type: 'referrals' } | { type: 'url'; url: string };
    audience: 'all' | 'owner' | 'resident';
    startsAt: string | null;
    endsAt: string | null;
    isActive: boolean;
    priority: number;
    stopAfterReferral: boolean;
    stats: { shown: number; clicked: number; hidden: number };
};

/** One person in full, for their page in the admin area. */
export type AdminUserDetail = Omit<AdminUser, 'accounts'> & {
    accounts: {
        id: number;
        role: string;
        propertywareId: string | null;
        label: string | null;
        linkedAt: string | null;
    }[];
    sessions: {
        id: number;
        name: string;
        lastUsedAt: string | null;
        createdAt: string | null;
    }[];
    /** Phones registered for push notifications. */
    devices: number;
    openHelpRequests: number;
};

/** Queued work that gave up, for staff to retry or discard. */
export type AdminFailedJob = {
    uuid: string;
    job: string;
    queue: string;
    failedAt: string;
    error: string;
};

/** A client's request for help with the portal or the app, for staff. */
export type AdminHelpRequest = {
    id: number;
    topic: string;
    topicLabel: string;
    message: string;
    platform: string;
    status: 'open' | 'resolved';
    statusLabel: string;
    staffNote: string | null;
    resolvedAt: string | null;
    resolvedBy: string | null;
    sentAt: string;
    appVersion: string | null;
    userAgent: string | null;
    attachments: HelpRequestAttachment[];
    user: { id: number; name: string; email: string };
};

/** An invitation to the portal, for staff. */
export type AdminInvitation = {
    id: number;
    email: string;
    name: string;
    role: 'owner' | 'resident';
    propertywareId: string | null;
    status: 'pending' | 'accepted' | 'expired' | 'cancelled';
    sentAt: string | null;
    expiresAt: string | null;
    acceptedAt: string | null;
    cancelledAt: string | null;
    invitedBy: string | null;
};

/** Whether a Propertyware record is on the portal yet. */
export type AdminPortalStatus = {
    linkedTo: string | null;
    linkedUserId: number | null;
    pendingInvitation: boolean;
};

/** A Propertyware contact using an email address, for the invite dialog. */
export type AdminContactMatch = AdminPortalStatus & {
    id: string;
    role: 'owner' | 'resident';
    name: string;
    portfolios: number;
    leases: number;
};

/** An owner or tenant attached to a property. */
export type AdminPropertyPerson = AdminPortalStatus & {
    contactId: string;
    role: 'owner' | 'resident';
    name: string;
    email: string | null;
};

export type AdminPropertyMatch = {
    id: number;
    address: string;
    name: string | null;
    occupied: boolean;
    owners: AdminPropertyPerson[];
    residents: AdminPropertyPerson[];
};

/** An announcement with how far it has reached, for staff. */
export type AdminAnnouncement = {
    id: number;
    title: string;
    body: string;
    tone: 'info' | 'warning';
    audience: 'all' | 'owner' | 'resident';
    postedAt: string;
    startsAt: string | null;
    endsAt: string | null;
    isLive: boolean;
    emailedAt: string | null;
    pushedAt: string | null;
    dismissals: number;
    createdBy: string | null;
};

export type AdminInsightPerson = {
    id: number;
    name: string;
    email: string;
    joinedAt: string | null;
    lastSeenAt: string | null;
    roles: string[];
};

export type AdminInsightList = {
    total: number;
    people: AdminInsightPerson[];
};

/** Someone who can use the admin area. Owners come from ADMIN_EMAILS and are fixed. */
export type AdminStaffMember = {
    id: number | null;
    name: string;
    email: string;
    role: 'owner' | 'admin' | 'staff';
    fixed: boolean;
    lastSeenAt: string | null;
    twoFactor: boolean;
};

/** One sign-in or failed attempt, for the sign-in log. */
export type AdminSignInEvent = {
    id: number;
    event:
        | 'signed_in'
        | 'failed'
        | 'failed_two_factor'
        | 'locked_out'
        | 'blocked';
    email: string | null;
    user: { id: number; name: string } | null;
    channel: 'web' | 'app';
    method: string | null;
    ipAddress: string | null;
    /** e.g. "Chrome on Mac". */
    device: string | null;
    at: string;
};

/** An address with failed sign-ins or other signals, for the Security page. */
export type AdminSuspiciousIp = {
    ip: string;
    failures: number;
    emails: number;
    sampleEmails: string[];
    /** Scanner probes, admin attempts, bad invitation links, bad webhook signatures. */
    probes: number;
    lastAt: string;
    blocked: boolean;
};

export type AdminBlockedIp = {
    id: number;
    ip: string;
    reason: string;
    source: 'manual' | 'automatic';
    active: boolean;
    expiresAt: string | null;
    blockedBy: string | null;
    hits: number;
    lastHitAt: string | null;
    createdAt: string;
};

export type AdminSecurityEvent = {
    id: number;
    type: string;
    label: string;
    ip: string | null;
    path: string | null;
    user: { id: number; email: string } | null;
    reason: string | null;
    at: string;
};
