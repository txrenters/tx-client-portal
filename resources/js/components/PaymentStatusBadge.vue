<script setup lang="ts">
import { CheckCircle2, Clock } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';

/**
 * Whether a tenant's payment has reached the bank: settled once it was
 * deposited, pending while it is still on its way.
 */
const props = defineProps<{
    settled: boolean;
}>();
</script>

<template>
    <Badge
        variant="outline"
        :class="
            props.settled
                ? 'bg-primary/10 text-primary border-primary/20'
                : 'bg-muted text-muted-foreground border-border'
        "
    >
        <component
            :is="props.settled ? CheckCircle2 : Clock"
            data-icon="inline-start"
            aria-hidden="true"
        />
        {{ props.settled ? 'Settled' : 'Pending' }}
    </Badge>
</template>
