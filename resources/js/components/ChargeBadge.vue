<script setup lang="ts">
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
        Charged
    </Badge>
</template>
