<script setup lang="ts">
/**
 * One of the web portal's pages in a plain browser window, for the landing
 * page. The screens are 1440×900 captures of the demo owner and tenant
 * (made-up records), exported to public/images/web as 1920px WebP, with
 * dark-mode captures of the same pages in public/images/web/dark.
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
        class="bg-card ring-foreground/10 dark:ring-border overflow-hidden rounded-xl shadow-2xl ring-1"
    >
        <div
            class="bg-muted border-border flex items-center gap-3 border-b px-3 py-2"
            aria-hidden="true"
        >
            <div class="flex gap-1.5">
                <span class="bg-foreground/15 size-2.5 rounded-full"></span>
                <span class="bg-foreground/15 size-2.5 rounded-full"></span>
                <span class="bg-foreground/15 size-2.5 rounded-full"></span>
            </div>
            <span
                class="bg-background text-muted-foreground mx-auto truncate rounded-md px-3 py-0.5 text-[0.7rem] sm:px-6"
            >
                app.texasrenters.com
            </span>
            <span class="w-[2.6rem]"></span>
        </div>
        <!-- Light and dark captures; the hidden one is never shown, and when
             lazy it is never downloaded either. -->
        <img
            :src="`/images/web/${screen}.webp`"
            :alt="alt"
            width="1920"
            height="1200"
            :loading="eager ? 'eager' : 'lazy'"
            decoding="async"
            draggable="false"
            class="bg-background block h-auto w-full select-none dark:hidden"
        />
        <img
            :src="`/images/web/dark/${screen}.webp`"
            :alt="alt"
            width="1920"
            height="1200"
            :loading="eager ? 'eager' : 'lazy'"
            decoding="async"
            draggable="false"
            class="bg-background hidden h-auto w-full select-none dark:block"
        />
    </div>
</template>
