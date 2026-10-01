<script setup lang="ts">
/**
 * One of the mobile app's screens in a plain phone outline, for the landing
 * page. The screens are the simulator captures from tx-mobile's store/raw,
 * exported to public/images/app/screens as 640px WebP, so the website shows
 * the real app without the store slides' baked-in headlines.
 */
const {
    screen,
    alt,
    eager = false,
} = defineProps<{
    screen: string;
    alt: string;
    eager?: boolean;
}>();
</script>

<template>
    <div
        class="bg-foreground ring-foreground/10 dark:bg-card dark:ring-border rounded-[2.4rem] p-[0.45rem] shadow-2xl ring-1"
    >
        <img
            :src="`/images/app/screens/${screen}.webp`"
            :alt="alt"
            width="640"
            height="1391"
            :loading="eager ? 'eager' : 'lazy'"
            decoding="async"
            draggable="false"
            class="bg-background block h-auto w-full rounded-[2rem] select-none"
        />
    </div>
</template>
