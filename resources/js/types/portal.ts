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

export type DocumentFilterValues = {
    search: string | null;
    category: string | null;
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
};

export type LeaseLedger = {
    /** True while the first read of this tenancy is still running. */
    syncing: boolean;
    /** The page on screen. */
    entries: LeaseLedgerEntry[];
    results: Paginated<LeaseLedgerEntry>;
    charged: number;
    paid: number;
    from: string;
    to: string;
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
