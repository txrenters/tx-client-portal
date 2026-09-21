<script setup lang="ts">
import { useFormattedDate } from '@/composables/useFormattedDate';

defineProps<{
    events: { label: string; at: string }[];
}>();

const { formatDate } = useFormattedDate();
</script>

<template>
    <ol class="flex flex-col gap-4">
        <li
            v-for="(event, index) in events"
            :key="`${event.label}-${event.at}`"
            class="flex gap-3"
        >
            <div class="flex flex-col items-center">
                <span
                    class="bg-primary mt-1.5 size-2.5 shrink-0 rounded-full"
                    aria-hidden="true"
                />
                <span
                    v-if="index < events.length - 1"
                    class="bg-border w-px flex-1"
                    aria-hidden="true"
                />
            </div>
            <div class="pb-1">
                <p class="text-sm font-medium">{{ event.label }}</p>
                <p class="text-muted-foreground text-xs">
                    {{ formatDate(event.at) }}
                </p>
            </div>
        </li>
    </ol>
</template>
