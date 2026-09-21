<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { WorkOrderStatus } from '@/types';

const props = defineProps<{
    status: WorkOrderStatus;
    label: string;
}>();

const variants: Record<WorkOrderStatus, string> = {
    open: 'bg-primary/10 text-primary border-primary/20',
    scheduled:
        'bg-amber-500/10 text-amber-700 border-amber-500/20 dark:text-amber-400',
    completed: 'bg-success/10 text-success border-success/20',
    canceled: 'bg-muted text-muted-foreground border-transparent',
};
</script>

<template>
    <Badge variant="outline" :class="variants[props.status]">{{ label }}</Badge>
</template>
