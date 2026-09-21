<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
    }>(),
    {
        title: "We couldn't load this right now",
        description: 'Please try again in a few minutes.',
    },
);
</script>

<template>
    <EmptyState
        :icon="TriangleAlert"
        :title="title"
        :description="description"
    />
</template>
