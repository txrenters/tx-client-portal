<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

/**
 * The section container used across the portal: a titled glass panel with an
 * optional header link and footer. Every page-level block uses this so spacing,
 * radius, and elevation stay identical everywhere.
 */
defineProps<{
    title?: string;
    description?: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    linkLabel?: string;
}>();
</script>

<template>
    <Card class="glass min-w-0 gap-4 rounded-2xl">
        <CardHeader v-if="title || $slots.header">
            <slot name="header">
                <CardTitle class="text-base">{{ title }}</CardTitle>
                <CardDescription v-if="description">
                    {{ description }}
                </CardDescription>
                <CardAction v-if="href">
                    <Link
                        :href="href"
                        class="text-primary text-sm font-medium hover:underline"
                    >
                        {{ linkLabel ?? 'View all' }}
                    </Link>
                </CardAction>
            </slot>
        </CardHeader>

        <CardContent>
            <slot />
        </CardContent>

        <CardFooter v-if="$slots.footer" class="text-muted-foreground text-sm">
            <slot name="footer" />
        </CardFooter>
    </Card>
</template>
