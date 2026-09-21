<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { onUnmounted } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/EmptyState.vue';

/**
 * Shown while a client's documents are still being read from Propertyware.
 * Checks back every few seconds, and says so when they arrive.
 */
const props = defineProps<{
    /** The page props to reload while waiting. */
    only: string[];
}>();

usePoll(3000, { only: props.only });

onUnmounted(() => {
    toast.success('Your documents are ready.');
});
</script>

<template>
    <div role="status" aria-live="polite">
        <EmptyState
            :icon="RefreshCw"
            title="Importing your documents from Propertyware"
            description="This can take a minute the first time for larger accounts. You can keep using the portal; they'll appear here on their own."
            spin
        />
    </div>
</template>
