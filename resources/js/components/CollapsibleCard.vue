<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

/**
 * A titled card whose body folds away, starting closed. Used for the side
 * sections of the property and work order pages so both look the same.
 */
defineProps<{
    title: string;
    /** Shown beside the chevron, e.g. how many items are inside. */
    count?: number;
}>();
</script>

<template>
    <Collapsible v-slot="{ open }" as-child>
        <Card class="glass min-w-0 gap-0 rounded-2xl py-0">
            <CollapsibleTrigger
                class="hover:bg-muted/40 focus-visible:ring-ring/60 flex w-full items-center justify-between gap-3 rounded-2xl px-4 py-4 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
                <CardTitle class="text-base">{{ title }}</CardTitle>
                <span
                    class="text-muted-foreground flex shrink-0 items-center gap-2 text-xs"
                >
                    <template v-if="count !== undefined">{{ count }}</template>
                    <ChevronDown
                        class="size-4 transition-transform duration-200"
                        :class="{ 'rotate-180': open }"
                        aria-hidden="true"
                    />
                </span>
            </CollapsibleTrigger>

            <CollapsibleContent>
                <CardContent class="pb-4">
                    <slot />
                </CardContent>
            </CollapsibleContent>
        </Card>
    </Collapsible>
</template>
