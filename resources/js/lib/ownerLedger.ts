import type { OwnerLedgerEntry, OwnerLedgerEntryKind } from '@/types';

/**
 * How each kind of owner ledger line reads: its badge, and the word before
 * its date ("Paid Sep 16"), so every line says which way the money went.
 */
export const OWNER_ENTRY_KINDS: Record<
    OwnerLedgerEntryKind,
    { label: string; verb: string; tone: 'in' | 'out' | 'neutral' }
> = {
    income: { label: 'Income', verb: 'Received', tone: 'in' },
    refund: { label: 'Refund', verb: 'Refunded', tone: 'out' },
    expense: { label: 'Expense', verb: 'Paid', tone: 'out' },
    credit: { label: 'Credit', verb: 'Credited', tone: 'in' },
    draw: { label: 'Paid to you', verb: 'Sent', tone: 'in' },
    contribution: { label: 'Added by you', verb: 'Added', tone: 'neutral' },
};

/** The colour of a line's amount: paid in blue, charged in red. */
export function ownerAmountClass(entry: OwnerLedgerEntry): string {
    const tone = OWNER_ENTRY_KINDS[entry.kind]?.tone;

    if (tone === 'in') {
        return 'text-primary';
    }

    return tone === 'out' ? 'text-destructive' : '';
}
