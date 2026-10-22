<script setup lang="ts">
import PaymentStatusBadge from '@/components/PaymentStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { OWNER_ENTRY_KINDS } from '@/lib/ownerLedger';
import type { OwnerLedgerEntry } from '@/types';

/**
 * What an owner's ledger line was. A tenant's payment shows whether it has
 * reached the bank; every other line shows which way its money went.
 */
const props = defineProps<{
    entry: OwnerLedgerEntry;
}>();

/** Paid in blue, charged in red, as on the tenant's side and the charts. */
const tones = {
    in: 'bg-primary/10 text-primary border-primary/20',
    out: 'bg-destructive/10 text-destructive border-destructive/20',
    neutral: 'bg-muted text-muted-foreground border-border',
};
</script>

<template>
    <PaymentStatusBadge
        v-if="props.entry.settled != null"
        :settled="props.entry.settled"
    />
    <Badge
        v-else-if="OWNER_ENTRY_KINDS[props.entry.kind]"
        variant="outline"
        :class="tones[OWNER_ENTRY_KINDS[props.entry.kind].tone]"
        >{{ OWNER_ENTRY_KINDS[props.entry.kind].label }}</Badge
    >
</template>
