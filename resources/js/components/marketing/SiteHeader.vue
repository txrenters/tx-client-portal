<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ThemeToggle from '@/components/marketing/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { dashboard, home, login } from '@/routes';

const page = usePage();
const isSignedIn = computed(() => page.props.auth.user !== null);
</script>

<template>
    <!-- Navy in both themes, like the app's own header, so the white
     screens of the phones stand out. -->
    <header
        class="bg-scrim/95 text-scrim-foreground border-scrim-foreground/10 sticky top-0 z-50 border-b backdrop-blur"
    >
        <div
            class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
        >
            <Link :href="home()" class="flex items-center">
                <img
                    src="/texas-renters-logo.png"
                    alt="TexasRenters.com"
                    class="h-8 w-auto object-contain brightness-0 invert sm:h-10"
                    width="400"
                    height="111"
                />
            </Link>

            <div class="flex items-center gap-1">
                <ThemeToggle
                    class="text-scrim-foreground hover:bg-scrim-foreground/10 hover:text-scrim-foreground"
                />
                <Button
                    v-if="isSignedIn"
                    as-child
                    size="sm"
                    class="bg-scrim-foreground text-scrim hover:bg-scrim-foreground/90"
                >
                    <Link :href="dashboard()">Go to dashboard</Link>
                </Button>
                <template v-else>
                    <Button
                        as-child
                        size="sm"
                        variant="ghost"
                        class="text-scrim-foreground hover:bg-scrim-foreground/10 hover:text-scrim-foreground hidden sm:inline-flex"
                    >
                        <Link :href="login()">Sign in</Link>
                    </Button>
                    <Button
                        as-child
                        size="sm"
                        class="bg-scrim-foreground text-scrim hover:bg-scrim-foreground/90"
                    >
                        <a :href="`${home().url}#download`">Get the app</a>
                    </Button>
                </template>
            </div>
        </div>
    </header>
</template>
