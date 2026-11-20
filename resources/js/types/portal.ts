export type WorkOrderStatus = 'open' | 'scheduled' | 'completed' | 'canceled';

export type WorkOrder = {
    id: string;
    approved?: boolean;
    /** Owners only: still waiting on their approval. */
    awaitingApproval?: boolean;
    number: string | null;
    title: string;
    description: string;
    status: WorkOrderStatus;
    statusLabel: string;
    type: string | null;
    property: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    scheduledFor: string | null;
    completedAt: string | null;
    startedAt: string | null;
    entryDate: string | null;
    priority: string | null;
    category: string | null;
    buildingId: number | null;
};

export type WorkOrderVendor = {
    name: string;
    type: string | null;
    phone: string | null;
    email: string | null;
};

export type WorkOrderDetail = WorkOrder & {
    location: string | null;
    specificLocation: string | null;
    ageInDays: number | null;
    authorizedToEnter: string | null;
    source: string | null;
    requestedBy: string | null;
    managedBy: string | null;
    leaseName: string | null;
    contractNumber: string | null;
    requiredMaterials: string | null;
    maintenanceNotice: string | null;
    approved: boolean;
    vendors: WorkOrderVendor[];
    ownerCost: number | null;
    actualCost: number | null;
    costEstimate: number | null;
    hourEstimate: number | null;
    startedAt: string | null;
    entryDate: string | null;
    timeline: { label: string; at: string }[];
};

export type PortalDocument = {
    id: string;
    fileName: string;
    fileType: string | null;
    description: string | null;
    category: string;
    viewable: boolean;
    createdAt: string | null;
    workOrderId?: string | null;
    workOrderNumber?: string | null;
    propertyName?: string | null;
};

export type MaintenanceGroups = {
    status: 'ready' | 'error';
    groups: Record<WorkOrderStatus, WorkOrder[]>;
    counts: Partial<Record<WorkOrderStatus, number>>;
};

export type MaintenanceFilterValues = {
    status: WorkOrderStatus | null;
    approval?: 'needed' | null;
    search: string | null;
    property: string | null;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type PaginatedWorkOrders = Paginated<WorkOrder>;

export type MaintenancePage = {
    status: 'ready' | 'error';
    counts: Partial<Record<WorkOrderStatus, number>>;
    total: number;
    properties: string[];
    workOrders: PaginatedWorkOrders | null;
    /** Owners only: how many work orders wait for their approval. */
    awaitingApproval?: number;
};

export type ApprovalSummary = {
    count: number;
    items: WorkOrder[];
};

/** What a document is, read from its name: the Documents page's buttons. */
export type DocumentKind =
    | 'leases'
    | 'statements'
    | 'tax'
    | 'invoices'
    | 'other';

export type DocumentFilterValues = {
    search: string | null;
    category: string | null;
    kind: DocumentKind | null;
    type: 'images' | 'pdf' | 'other' | null;
    from: string | null;
    to: string | null;
    range: '30' | '90' | '365' | 'year' | 'custom' | null;
    property: number | null;
    work_order: string | null;
};

export type DocumentsImporting = { status: 'importing' };

export type UploadTarget = { id: number; label: string };

export type WorkOrderApprovalState = {
    canDecide: boolean;
    awaiting: boolean;
    decision: 'approved' | 'declined' | null;
    decidedAt: string | null;
    decidedBy: string | null;
    note: string | null;
    pendingSync: boolean;
};

export type WorkOrderRequestOptions = {
    categories: { value: string; label: string }[];
    properties: { id: number; label: string }[];
    maxPhotos: number;
    emergencyPhone: string | null;
};

export type UploadTargets = {
    properties: UploadTarget[];
    portfolios: UploadTarget[];
    workOrders: UploadTarget[];
    leases: UploadTarget[];
};

export type DocumentsPage = {
    status: 'ready';
    total: number;
    categories: string[];
    kindCounts: Record<DocumentKind, number>;
    typeCounts: Record<'images' | 'pdf' | 'other', number>;
    properties: { id: number; name: string }[];
    workOrders: {
        id: string;
        number: string | null;
        category: string | null;
        title: string;
    }[];
    results: Paginated<PortalDocument>;
};

export type MaintenanceSummary = {
    status: 'ready' | 'error';
    counts: Partial<Record<WorkOrderStatus, number>>;
    recent: WorkOrder[];
};

export type PropertyDetails = {
    sections: { title: string; items: { label: string; value: string }[] }[];
    amenities: string[];
    managers: { name: string; email: string | null }[];
};

export type PropertyHistoryEvent = {
    type:
        | 'work_order_opened'
        | 'work_order_scheduled'
        | 'work_order_completed'
        | 'work_order_canceled'
        | 'document_added';
    title: string;
    detail: string | null;
    occurredAt: string;
    workOrderId: string | null;
    documentId: string | null;
};

export type PropertyFilterValues = {
    search: string | null;
    occupancy: 'occupied' | 'vacant' | null;
    city: string | null;
    bedrooms: '1' | '2' | '3' | '4' | null;
};

export type PropertiesPage = {
    total: number;
    counts: { occupied: number; vacant: number };
    cities: string[];
    results: Paginated<PortalProperty>;
};

export type PortalProperty = {
    id: number;
    name: string | null;
    address: string;
    city: string | null;
    state: string | null;
    postalCode: string | null;
    unitName: string | null;
    fullAddress: string;
    type: string | null;
    category: string | null;
    status: string | null;
    bedrooms: number | null;
    bathrooms: number | null;
    area: string | null;
    yearBuilt: string | null;
    neighborhood: string | null;
    county: string | null;
    occupied: boolean;
    /**
     * The current lease, on an owner's property list only: what it rents for
     * and when it ends. Null when vacant; absent elsewhere.
     */
    lease?: PortalPropertyLease | null;
};

export type PortalPropertyLease = {
    rent: number | null;
    /** YYYY-MM-DD; null when the lease runs month to month. */
    endDate: string | null;
    /** YYYY-MM-DD the tenant is moving out, when they have given notice. */
    moveOutDate: string | null;
    /** It ends, or they move out, within 60 days. */
    endsSoon: boolean;
};

/** A group of what Propertyware holds on the client, e.g. Contact or Mailing address. */
export type ContactDetailSection = {
    title: string;
    items: { label: string; value: string }[];
};

export type ConversationMessage = {
    id: string;
    text: string;
    /** The Propertyware login that wrote the message. */
    author: string | null;
    /** Written by the client, rather than TexasRenters.com staff. */
    fromClient: boolean;
    at: string | null;
};

export type Conversation = {
    id: string;
    subject: string;
    /** On one of the client's properties, or on their own account. */
    about: 'property' | 'account';
    buildingId: number | null;
    property: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    messageCount: number;
    preview: string | null;
    /** Oldest first. */
    messages: ConversationMessage[];
    /** Work orders mentioned in the messages that the client can open. */
    workOrders: { id: string; number: string }[];
};

export type ConversationFilterValues = {
    search: string | null;
    property: number | 'account' | null;
};

export type ConversationsPage = {
    total: number;
    properties: { id: number; label: string }[];
    results: Paginated<Conversation>;
};

/** A conversation with TexasRenters.com support or maintenance, from the messaging hub. */
export type ChatThread = {
    id: number;
    subject: string;
    kind: 'support' | 'maintenance';
    workOrderId: string | null;
    workOrderNumber: string | null;
    isClosed: boolean;
    /** The staff member handling it, or null while no one has picked it up. */
    assignedStaff: { name: string; photoUrl: string | null } | null;
    /** When the last message was written. */
    updatedAt: string | null;
    lastMessage: {
        id: number;
        /** Who wrote it, when it was TexasRenters.com. */
        author: string | null;
        preview: string;
        fromClient: boolean;
        hasAttachment: boolean;
    } | null;
    /** Has a reply the client hasn't opened yet. */
    unread?: boolean;
    /** The client's own number, on the open conversation only. */
    phone?: string | null;
    /** What the conversation is about, when the client picked a category. */
    category?: 'leasing' | 'accounting' | 'maintenance' | null;
    /** Work order texts from before the messaging hub: closed, with a negative id. */
    isHistory?: boolean;
};

export type ChatThreadMessage = {
    id: number;
    body: string;
    mediaUrls: string[];
    /** The number the text used; null when it was written in the app. */
    number?: string | null;
    /** Written by the client (in this app or by text), rather than TexasRenters.com. */
    fromClient: boolean;
    author: string | null;
    /** The staff member's profile photo, when they have one. */
    authorPhotoUrl: string | null;
    at: string | null;
};

export type ChatConversation = {
    thread: ChatThread;
    messages: ChatThreadMessage[];
};

/** One of the client's latest conversations with staff, on the dashboard. */
export type DashboardMessage = {
    id: number;
    subject: string;
    preview: string;
    author: string | null;
    unread: boolean;
    at: string | null;
};

/** The dashboard's newest documents, or an import still running. */
export type DashboardDocuments = {
    status: 'ready' | 'importing';
    items: {
        id: string;
        fileName: string;
        description: string | null;
        category: string;
        /** A PDF or image the viewer can open; anything else downloads. */
        viewable: boolean;
        createdAt: string | null;
    }[];
};

/** One line of the dashboard's recent activity feed. */
export type DashboardActivityEntry = {
    type: 'maintenance' | 'document' | 'message';
    id: string;
    title: string;
    detail: string | null;
    at: string | null;
    url: string;
};

/** A tenant's lease, as the portal shows it. */
export type ResidentLease = {
    id: number;
    /** Propertyware's label for the lease, e.g. "Vega, Y." */
    name: string | null;
    status: string | null;
    active: boolean;
    startDate: string | null;
    endDate: string | null;
    moveInDate: string | null;
    moveOutDate: string | null;
    noticeGivenDate: string | null;
    rent: number | null;
    balance: number | null;
    lateFeeRule: string | null;
    /** The lease ends, or they move out, within 60 days. */
    endsSoon: boolean;
};

/** A tenant's charges and payments, newest first. */
export type LeaseLedgerEntry = {
    id: string;
    date: string;
    /**
     * What it was: billed, money received, a payment that came back (an NSF
     * return), a credit, or prepaid money applied.
     */
    kind: 'charge' | 'payment' | 'return' | 'credit' | 'prepayment';
    /** Credits come off the balance, debits add to it. */
    direction: 'credit' | 'debit';
    amount: number;
    description: string;
    detail: string | null;
    reference: string | null;
    /**
     * Whether a payment has reached the bank: false while it is on its way,
     * null on any other line or when Propertyware would not say.
     */
    settled: boolean | null;
    /** The day a settled payment was deposited. */
    settledOn: string | null;
};

export type LeaseLedger = {
    /** True while the first read of this tenancy is still running. */
    syncing: boolean;
    /** The page on screen. */
    entries: LeaseLedgerEntry[];
    results: Paginated<LeaseLedgerEntry>;
    charged: number;
    paid: number;
    /** The last 12 months, oldest first, for the dashboard chart. */
    months: LeaseMonthlyTotals[];
    from: string;
    to: string;
};

export type LeaseMonthlyTotals = {
    month: string;
    charged: number;
    paid: number;
};

/** What an owner has been billed, by portfolio. */
export type OwnerFinancials = {
    /** True while bills for some portfolios are still being imported. */
    importing: boolean;
    portfolios: { id: number; name: string; expenses: number; bills: number }[];
    bills: {
        id: string;
        /** The number Propertyware and our office both quote. */
        number: string | null;
        /** The day it reached the owner's statement: paid, else billed. */
        date: string;
        billDate: string;
        dueDate: string | null;
        paymentDate: string | null;
        vendor: string;
        description: string | null;
        /** What it was spent on, from Propertyware's chart of accounts. */
        accounts: string[];
        /** The buildings and unit the bill was split onto. */
        buildings: string[];
        unit: string | null;
        amount: number;
        paid: number;
        status: 'paid' | 'part-paid' | 'unpaid';
        workOrderId: string | null;
        portfolios: string[];
    }[];
    /** The page of bills on screen; the totals count every one. */
    results: Paginated<unknown>;
    totals: {
        expenses: number;
        paid: number;
        outstanding: number;
        bills: number;
    };
    from: string;
    to: string;
};

/** What each of an owner's properties brought in over a period. */
export type OwnerRevenue = {
    /** True while the background import is still reading their leases. */
    importing: boolean;
    properties: {
        id: number;
        name: string;
        portfolioId: number;
        billed: number;
        received: number;
        leases: number;
    }[];
    totals: { billed: number; received: number; properties: number };
    from: string;
    to: string;
};

/** One line of the owner's ledger, as Propertyware's owner portal shows it. */
/**
 * What a line was to the owner: rent or other income in (or refunded back
 * out), a bill paid (or credited back), money sent to them or put in by them.
 */
export type OwnerLedgerEntryKind =
    | 'income'
    | 'refund'
    | 'expense'
    | 'credit'
    | 'draw'
    | 'contribution';

export type OwnerLedgerEntry = {
    id: number;
    date: string;
    reference: string | null;
    location: string | null;
    description: string;
    kind: OwnerLedgerEntryKind;
    details: string | null;
    amount: number;
    balance: number;
    /** On a tenant payment: whether it has reached the bank. Null on any other line. */
    settled?: boolean | null;
    settledOn?: string | null;
};

/** An owner's statement for one portfolio and one finished month. */
export type OwnerStatement = {
    portfolio: { id: number; name: string } | null;
    month: string;
    label: string;
    from: string;
    to: string;
    openingBalance: number;
    income: number;
    expense: number;
    managementFee: number;
    draws: number;
    endingBalance: number;
    fromInception: boolean;
    incomeAccounts: { name: string; amount: number }[];
    expenseAccounts: { name: string; amount: number }[];
    /** Oldest first, each with the balance it left behind. */
    entries: OwnerLedgerEntry[];
};

export type OwnerLedgerData = {
    income: number;
    expense: number;
    managementFee: number;
    openingBalance: number;
    endingBalance: number;
    net: number;
    /** True when the balance runs from the portfolio's first day. */
    fromInception: boolean;
    entries: OwnerLedgerEntry[];
    from: string;
    to: string;
};

/** One GL account's share of a period's income or expense. */
export type OwnerLedgerAccount = {
    name: string;
    amount: number;
};

/** The owner dashboard's money summary for the current period. */
export type OwnerDashboardLedger = OwnerLedgerData & {
    months: OwnerMonthlyTotals[];
    incomeAccounts: OwnerLedgerAccount[];
    expenseAccounts: OwnerLedgerAccount[];
    portfolioMinimum: number;
    outstanding: number;
    availableBalance: number;
};

/** One month of an owner's income and expense. */
export type OwnerMonthlyTotals = {
    month: string;
    income: number;
    expense: number;
};

/** A meeting staff offer, from tx-scheduling. */
export type MeetingType = {
    slug: string;
    /** The booking page this one is booked on; they differ within an org. */
    page: string;
    /** The departments listing it on coresched's departments page; may be empty. */
    departments?: string[];
    host: string | null;
    name: string;
    description: string | null;
    color: string;
    durationMinutes: number;
    locationType: string;
    locationLabel: string;
    url: string;
};

/** A meeting the client already has booked. */
export type UpcomingMeeting = {
    id: number;
    uid: string;
    name: string | null;
    startsAt: string;
    endsAt: string;
    timezone: string;
    locationLabel: string | null;
    meetingUrl: string | null;
    manageUrl: string;
};

/** A property owner the client referred, as they see it. */
export type Referral = {
    id: number;
    name: string;
    propertyAddress: string;
    status: 'new' | 'contacted' | 'signed' | 'not_a_fit';
    statusLabel: string;
    sentAt: string;
};

/** A notice from TexasRenters.com shown as a banner until the client closes it. */
export type Announcement = {
    id: number;
    title: string;
    body: string;
    tone: 'info' | 'warning';
    audience: 'all' | 'owner' | 'resident';
    postedAt: string;
};

/** A request for help with the portal or the app, as the client sees it. */
/** A screenshot or file sent with a help request, behind a checked link. */
export type HelpRequestAttachment = {
    id: number;
    name: string;
    mimeType: string;
    size: number;
    /** Whether a browser can draw it, so it is shown as a thumbnail. */
    isImage: boolean;
    url: string;
};

export type HelpRequest = {
    id: number;
    topic: string;
    topicLabel: string;
    message: string;
    platform: string;
    status: 'open' | 'resolved';
    statusLabel: string;
    /** What staff did about it; only set once resolved. */
    staffNote: string | null;
    resolvedAt: string | null;
    sentAt: string;
    attachments: HelpRequestAttachment[];
};

/** A promotion popping up for the client today (the referral program first). */
export type Promotion = {
    id: number;
    title: string;
    body: string;
    buttonLabel: string;
    /** A name from promotionDesign.ts; older promotions are "blue". */
    theme?: string;
    /** A name from promotionDesign.ts; older promotions are "gift". */
    icon?: string;
    link: { type: 'referrals' } | { type: 'url'; url: string };
};

/** A stored notice: a lease reminder, a work order update or a meeting reminder. */
export type Notice = {
    id: string;
    type: 'lease_expiring' | 'work_order_update' | 'meeting_reminder';
    role: 'owner' | 'resident' | null;
    title: string;
    body: string;
    message: string;
    building: string | null;
    unit: string | null;
    buildingId: number | null;
    startDate: string | null;
    endDate: string | null;
    renewalDeadline: string | null;
    url: string | null;
    readAt: string | null;
    createdAt: string | null;
};
