<script setup lang="ts">
import { Receipt } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';

/**
 * Marks something billed to a tenant, so it never reads as money they sent.
 */
</script>

<template>
    <Badge
        variant="outline"
        class="bg-destructive/10 text-destructive border-destructive/20"
    >
        <Receipt data-icon="inline-start" aria-hidden="true" />
        Charged
    </Badge>
</template>
