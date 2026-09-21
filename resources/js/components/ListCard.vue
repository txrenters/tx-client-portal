<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { Card, CardContent } from '@/components/ui/card';

/**
 * The row card used for every list in the portal: work orders,
 * properties, documents, and vendors. It keeps one visual rhythm - optional
 * leading icon, an eyebrow line, title, subtitle, meta row, and trailing slot.
 */
defineProps<{
    title: string;
    eyebrow?: string | null;
    subtitle?: string | null;
    icon?: LucideIcon;
    href?: NonNullable<InertiaLinkProps['href']>;
}>();
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        class="block"
        :class="
            href
                ? 'group focus-visible:ring-ring/60 rounded-xl focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none'
                : ''
        "
    >
        <Card
            class="gap-0 py-4 shadow-xs transition-colors"
            :class="
                href
                    ? 'group-hover:border-primary/40 group-hover:bg-accent/40'
                    : ''
            "
        >
            <CardContent class="flex items-start gap-3 px-4">
                <component
                    :is="icon"
                    v-if="icon"
                    class="text-muted-foreground mt-0.5 size-5 shrink-0"
                />

                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <p
                                v-if="eyebrow"
                                class="text-muted-foreground font-mono text-xs"
                            >
                                {{ eyebrow }}
                            </p>
                            <p class="truncate font-medium">{{ title }}</p>
                            <p
                                v-if="subtitle"
                                class="text-muted-foreground truncate text-sm"
                            >
                                {{ subtitle }}
                            </p>
                        </div>

                        <div v-if="$slots.trailing" class="shrink-0">
                            <slot name="trailing" />
                        </div>
                    </div>

                    <div
                        v-if="$slots.meta"
                        class="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
                    >
                        <slot name="meta" />
                    </div>

                    <slot />
                </div>
            </CardContent>
        </Card>
    </component>
</template>
