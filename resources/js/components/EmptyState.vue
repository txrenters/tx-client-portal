<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';

defineProps<{
    title: string;
    description?: string;
    icon?: LucideIcon;
    /** Spins the icon, for loading states. Respects reduced-motion settings. */
    spin?: boolean;
}>();
</script>

<template>
    <Empty class="border-none">
        <EmptyHeader>
            <EmptyMedia v-if="icon" variant="icon">
                <component
                    :is="icon"
                    :class="{ 'motion-safe:animate-spin': spin }"
                />
            </EmptyMedia>
            <EmptyTitle>{{ title }}</EmptyTitle>
            <EmptyDescription v-if="description">{{
                description
            }}</EmptyDescription>
        </EmptyHeader>
        <EmptyContent v-if="$slots.default">
            <slot />
        </EmptyContent>
    </Empty>
</template>
