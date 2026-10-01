<script setup lang="ts">
import {
    CircleAlert,
    CircleCheck,
    CircleMinus,
    CircleSlash,
} from '@lucide/vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { AdminIntegration } from '@/types';

defineProps<{
    /** Undefined while the services are still being checked. */
    integrations?: AdminIntegration[];
}>();

const presentation = {
    ok: { icon: CircleCheck, tone: 'text-success', label: 'Answering' },
    failing: { icon: CircleAlert, tone: 'text-destructive', label: 'Failing' },
    configured: {
        icon: CircleMinus,
        tone: 'text-muted-foreground',
        label: 'Set up',
    },
    not_configured: {
        icon: CircleSlash,
        tone: 'text-muted-foreground',
        label: 'Not set up',
    },
} as const;
</script>

<template>
    <div v-if="integrations === undefined" class="flex flex-col gap-3">
        <div v-for="row in 4" :key="row" class="flex items-center gap-3">
            <Skeleton class="size-5 shrink-0 rounded-full" />
            <div class="flex-1 space-y-1.5">
                <Skeleton class="h-4 w-32" />
                <Skeleton class="h-3 w-56" />
            </div>
        </div>
    </div>

    <ul v-else class="flex flex-col gap-3">
        <li
            v-for="integration in integrations"
            :key="integration.key"
            class="flex items-start gap-3"
        >
            <component
                :is="presentation[integration.status].icon"
                class="mt-0.5 size-5 shrink-0"
                :class="presentation[integration.status].tone"
            />
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2 font-medium">
                    {{ integration.name }}
                    <span class="text-muted-foreground text-xs font-normal">
                        {{ presentation[integration.status].label }}
                    </span>
                </p>
                <p class="text-muted-foreground text-sm text-pretty">
                    {{ integration.detail ?? integration.purpose }}
                </p>
            </div>
        </li>
    </ul>
</template>
