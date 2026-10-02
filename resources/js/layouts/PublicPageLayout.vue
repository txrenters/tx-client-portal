<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import SiteFooter from '@/components/marketing/SiteFooter.vue';
import SiteHeader from '@/components/marketing/SiteHeader.vue';

defineProps<{
    title: string;
    description: string;
    updated?: string;
}>();
</script>

<!-- The landing page's header and footer around a page of plain text, with a
     navy title band like the landing page's hero. -->
<template>
    <div
        class="bg-background text-foreground flex min-h-svh flex-col overflow-x-clip"
    >
        <Head :title="title">
            <meta name="description" :content="description" />
        </Head>

        <SiteHeader />

        <main class="flex-1">
            <section class="bg-scrim text-scrim-foreground">
                <div
                    class="mx-auto w-full max-w-3xl px-4 py-14 sm:px-6 sm:py-20"
                >
                    <h1
                        class="font-heading text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="updated"
                        class="text-scrim-foreground/70 mt-3 text-sm"
                    >
                        Last updated {{ updated }}
                    </p>
                </div>
            </section>

            <div
                class="[&_a]:text-primary [&_h2]:font-heading mx-auto w-full max-w-3xl space-y-4 px-4 py-12 leading-relaxed sm:px-6 [&_a]:underline-offset-4 hover:[&_a]:underline [&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-semibold [&_li]:ml-5 [&_li]:list-disc [&_ul]:space-y-2"
            >
                <slot />
            </div>
        </main>

        <footer class="bg-scrim text-scrim-foreground">
            <SiteFooter />
        </footer>
    </div>
</template>
