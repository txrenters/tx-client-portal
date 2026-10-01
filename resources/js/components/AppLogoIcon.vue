<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- The house-and-star mark on its own (the same one as the favicon). -->
    <img
        src="/icon-192.png"
        alt="TexasRenters.com"
        :class="className"
        v-bind="$attrs"
    />
</template>
