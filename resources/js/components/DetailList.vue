<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    items: { label: string; value: string | number | null }[];
    /** One label per row, for a narrow side column. */
    stacked?: boolean;
}>();

const visible = computed(() =>
    props.items.filter(
        (item) =>
            item.value !== null &&
            item.value !== '' &&
            item.value !== undefined,
    ),
);
</script>

<template>
    <dl
        v-if="visible.length > 0"
        :class="
            stacked ? 'grid gap-3' : 'grid grid-cols-2 gap-4 sm:grid-cols-3'
        "
    >
        <div v-for="item in visible" :key="item.label">
            <dt class="text-muted-foreground text-xs">{{ item.label }}</dt>
            <dd
                class="font-medium wrap-anywhere"
                :class="{ 'text-sm': stacked }"
            >
                {{ item.value }}
            </dd>
        </div>
    </dl>
</template>
