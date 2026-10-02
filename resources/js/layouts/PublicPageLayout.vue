<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ThemeToggle from '@/components/marketing/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { home, login, privacy, support } from '@/routes';

defineProps<{
    title: string;
    description: string;
    updated?: string;
}>();
</script>

<template>
    <div class="bg-background text-foreground flex min-h-svh flex-col">
        <Head :title="title">
            <meta name="description" :content="description" />
        </Head>

        <header
            class="bg-scrim/95 text-scrim-foreground border-scrim-foreground/10 sticky top-0 z-50 border-b backdrop-blur"
        >
            <div
                class="mx-auto flex h-16 w-full max-w-3xl items-center justify-between gap-4 px-4 sm:px-6"
            >
                <Link :href="home()" class="flex items-center gap-3">
                    <img
                        src="/texas-renters-logo.png"
                        alt="TexasRenters.com"
                        class="h-7 w-auto object-contain brightness-0 invert sm:h-8"
                        width="400"
                        height="111"
                    />
                </Link>

                <div class="flex items-center gap-1">
                    <ThemeToggle
                        class="text-scrim-foreground hover:bg-scrim-foreground/10 hover:text-scrim-foreground"
                    />
                    <Button
                        as-child
                        size="sm"
                        variant="ghost"
                        class="text-scrim-foreground hover:bg-scrim-foreground/10 hover:text-scrim-foreground"
                    >
                        <Link :href="login()">Sign in</Link>
                    </Button>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-12 sm:px-6">
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                {{ title }}
            </h1>
            <p v-if="updated" class="text-muted-foreground mt-2 text-sm">
                Last updated {{ updated }}
            </p>

            <div
                class="[&_a]:text-primary mt-8 space-y-4 leading-relaxed [&_a]:underline-offset-4 hover:[&_a]:underline [&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-semibold [&_li]:ml-5 [&_li]:list-disc [&_ul]:space-y-2"
            >
                <slot />
            </div>
        </main>

        <footer class="bg-scrim text-scrim-foreground">
            <div
                class="text-scrim-foreground/70 mx-auto flex w-full max-w-3xl flex-col gap-2 px-4 py-6 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6"
            >
                <span
                    >&copy;
                    {{ new Date().getFullYear() }} TexasRenters.com</span
                >
                <nav class="flex gap-4" aria-label="Legal">
                    <Link :href="privacy()" class="hover:text-scrim-foreground">
                        Privacy
                    </Link>
                    <Link :href="support()" class="hover:text-scrim-foreground">
                        Support
                    </Link>
                </nav>
            </div>
        </footer>
    </div>
</template>
