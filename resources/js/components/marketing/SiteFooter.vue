<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Globe, LineChart } from '@lucide/vue';
import { appStoreUrl } from '@/lib/appStore';
import { home, login, privacy, support } from '@/routes';
</script>

<!-- The footer's links and copyright line, inside a page's own navy <footer>. -->
<template>
    <div
        class="mx-auto grid w-full max-w-6xl gap-8 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-4"
    >
        <div class="flex flex-col gap-3">
            <img
                src="/texas-renters-logo.png"
                alt="TexasRenters.com"
                class="h-8 w-auto self-start object-contain brightness-0 invert"
                width="400"
                height="111"
            />
            <p class="text-scrim-foreground/70 text-sm text-pretty">
                Houston property management for owners and residents. Serving
                Houston, Katy, Sugar Land, The Woodlands, Cypress, Pearland and
                surrounding areas.
            </p>
        </div>

        <div class="flex flex-col gap-2 text-sm">
            <p class="font-medium">The app</p>
            <a
                :href="appStoreUrl"
                target="_blank"
                rel="noopener"
                class="text-scrim-foreground/70 hover:text-scrim-foreground"
            >
                App Store
            </a>
            <a
                :href="`${home().url}#the-app`"
                class="text-scrim-foreground/70 hover:text-scrim-foreground"
            >
                See the app
            </a>
            <a
                :href="`${home().url}#how-it-works`"
                class="text-scrim-foreground/70 hover:text-scrim-foreground"
            >
                Getting started
            </a>
            <a
                :href="`${home().url}#security`"
                class="text-scrim-foreground/70 hover:text-scrim-foreground"
            >
                Security
            </a>
        </div>

        <div class="flex flex-col gap-2 text-sm">
            <p class="font-medium">Web portal</p>
            <Link
                :href="login()"
                class="text-scrim-foreground/70 hover:text-scrim-foreground"
            >
                Sign in
            </Link>
        </div>

        <div class="flex flex-col gap-2 text-sm">
            <p class="font-medium">Contact</p>
            <a
                href="https://www.texasrenters.com"
                class="text-scrim-foreground/70 hover:text-scrim-foreground flex items-center gap-2"
                rel="noopener"
            >
                <Globe class="size-3.5" />
                texasrenters.com
            </a>
            <a
                href="https://www.texasrenters.com/property-management-landlord-protection"
                class="text-scrim-foreground/70 hover:text-scrim-foreground flex items-center gap-2"
                rel="noopener"
            >
                <LineChart class="size-3.5" />
                Free rental analysis
            </a>
            <Link
                :href="support()"
                class="text-scrim-foreground/70 hover:text-scrim-foreground"
            >
                Support
            </Link>
            <Link
                :href="privacy()"
                class="text-scrim-foreground/70 hover:text-scrim-foreground"
            >
                Privacy policy
            </Link>
        </div>
    </div>

    <div class="border-scrim-foreground/15 border-t">
        <div
            class="text-scrim-foreground/70 mx-auto flex w-full max-w-6xl flex-col gap-1 px-4 py-6 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
            <span>&copy; {{ new Date().getFullYear() }} TexasRenters.com</span>
            <span>
                Developed and maintained by
                <a
                    href="https://corepathbpo.com"
                    target="_blank"
                    rel="noopener"
                    class="hover:text-scrim-foreground font-medium underline-offset-4 hover:underline"
                    >CorePathBPO.com</a
                >
            </span>
        </div>
    </div>
</template>
