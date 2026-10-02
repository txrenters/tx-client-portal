<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

/**
 * The header switch between light and dark. It flips whatever is showing now,
 * so a "system" choice becomes an explicit one; Settings still offers system.
 */
const { resolvedAppearance, updateAppearance } = useAppearance();

function toggle(): void {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}
</script>

<template>
    <Button
        variant="ghost"
        size="icon-sm"
        :aria-label="
            resolvedAppearance === 'dark'
                ? 'Switch to light mode'
                : 'Switch to dark mode'
        "
        @click="toggle"
    >
        <Sun v-if="resolvedAppearance === 'dark'" class="size-4" />
        <Moon v-else class="size-4" />
    </Button>
</template>
