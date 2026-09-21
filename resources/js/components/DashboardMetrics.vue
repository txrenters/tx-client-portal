<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardAction,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

export type Metric = {
    label: string;
    value: string | number | null;
    loading?: boolean;
    badge?: string | null;
    icon?: LucideIcon;
    caption?: string | null;
    hint?: string | null;
};

defineProps<{
    metrics: Metric[];
    loading?: boolean;
}>();
</script>

<template>
    <div
        class="grid min-w-0 grid-cols-1 gap-4 @xl/main:grid-cols-2 @5xl/main:grid-cols-4"
    >
        <Card
            v-for="metric in metrics"
            :key="metric.label"
            class="glass @container/card min-w-0 rounded-2xl"
        >
            <CardHeader>
                <CardDescription class="flex items-center gap-2">
                    <span
                        v-if="metric.icon"
                        class="bg-primary/10 text-primary flex size-7 items-center justify-center rounded-lg"
                    >
                        <component :is="metric.icon" class="size-4" />
                    </span>
                    {{ metric.label }}
                </CardDescription>
                <CardTitle
                    class="min-w-0 text-2xl font-semibold wrap-anywhere tabular-nums @[250px]/card:text-3xl"
                >
                    <Skeleton
                        v-if="metric.loading ?? loading"
                        class="h-8 w-20"
                    />
                    <template v-else>{{ metric.value ?? '—' }}</template>
                </CardTitle>
                <CardAction v-if="metric.badge">
                    <Badge variant="outline">{{ metric.badge }}</Badge>
                </CardAction>
            </CardHeader>
            <CardFooter
                v-if="metric.caption || metric.hint"
                class="flex-col items-start gap-1.5 text-sm"
            >
                <div
                    v-if="metric.caption"
                    class="text-muted-foreground text-xs tracking-wide wrap-anywhere uppercase"
                >
                    {{ metric.caption }}
                </div>
                <div v-if="metric.hint" class="text-muted-foreground">
                    {{ metric.hint }}
                </div>
            </CardFooter>
        </Card>
    </div>
</template>
