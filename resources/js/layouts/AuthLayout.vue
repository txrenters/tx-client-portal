<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSplitLayout.vue';

const {
    title = '',
    description = '',
    showAppLinks = false,
} = defineProps<{
    title?: string;
    description?: string;
    showAppLinks?: boolean;
}>();
</script>

<template>
    <AuthLayout
        :title="title"
        :description="description"
        :show-app-links="showAppLinks"
    >
        <slot />
    </AuthLayout>
</template>
