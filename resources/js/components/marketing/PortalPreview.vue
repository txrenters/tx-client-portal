<script setup lang="ts">
import { CalendarClock, CheckCircle2, Wrench } from '@lucide/vue';

/**
 * A static illustration of the portal for the landing page. It is decorative:
 * real figures come from Propertyware once a client signs in.
 */
const stats = [
    { label: 'Properties', value: '3' },
    { label: 'Open', value: '1' },
    { label: 'Scheduled', value: '2' },
    { label: 'Completed', value: '45' },
];

const rows = [
    {
        number: '#43761',
        title: 'AC not cooling in the main bedroom',
        status: 'Open',
        icon: Wrench,
        tone: 'text-primary bg-primary/10',
    },
    {
        number: '#43744',
        title: 'Annual HVAC service visit',
        status: 'Scheduled',
        icon: CalendarClock,
        tone: 'text-amber-700 bg-amber-500/10 dark:text-amber-400',
    },
    {
        number: '#43702',
        title: 'Replaced kitchen faucet',
        status: 'Completed',
        icon: CheckCircle2,
        tone: 'text-success bg-success/10',
    },
];
</script>

<template>
    <div class="glass-strong overflow-hidden rounded-3xl" aria-hidden="true">
        <div
            class="border-border/70 flex items-center gap-2 border-b px-4 py-3"
        >
            <span class="bg-destructive/60 size-2.5 rounded-full" />
            <span class="size-2.5 rounded-full bg-amber-400/70" />
            <span class="bg-success/60 size-2.5 rounded-full" />
            <span class="text-muted-foreground ml-2 font-mono text-xs">
                portal.texasrenters.com
            </span>
        </div>

        <div class="flex flex-col gap-4 p-4 sm:p-5">
            <div class="grid grid-cols-4 gap-2">
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    class="from-primary/5 to-card border-border rounded-lg border bg-gradient-to-t p-3"
                >
                    <p class="text-lg font-semibold tabular-nums">
                        {{ stat.value }}
                    </p>
                    <p
                        class="text-muted-foreground text-[10px] font-medium tracking-wider uppercase"
                    >
                        {{ stat.label }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-2">
                <div
                    v-for="row in rows"
                    :key="row.number"
                    class="border-border/60 bg-card/50 flex items-center gap-3 rounded-xl border px-3 py-2.5"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-md"
                        :class="row.tone"
                    >
                        <component :is="row.icon" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-muted-foreground font-mono text-[11px]">
                            {{ row.number }}
                        </p>
                        <p class="truncate text-sm font-medium">
                            {{ row.title }}
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="row.tone"
                    >
                        {{ row.status }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
