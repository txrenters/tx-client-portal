<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

const { resolvedAppearance, updateAppearance } = useAppearance();

const toggle = (): void => {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
};
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        :aria-label="
            resolvedAppearance === 'dark'
                ? 'Switch to light mode'
                : 'Switch to dark mode'
        "
        @click="toggle"
    >
        <Sun class="hidden size-4 dark:block" />
        <Moon class="size-4 dark:hidden" />
    </Button>
</template>
