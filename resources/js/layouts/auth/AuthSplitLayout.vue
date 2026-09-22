<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, FileText, Wrench } from '@lucide/vue';
import AppStoreBadges from '@/components/marketing/AppStoreBadges.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
    showAppLinks?: boolean;
}>();

const highlights = [
    { label: 'Work Orders', icon: Wrench },
    { label: 'Documents', icon: FileText },
    { label: 'Property', icon: Building2 },
];
</script>

<template>
    <div
        class="bg-background relative grid min-h-dvh grid-cols-1 items-center px-6 py-10 lg:grid-cols-2 lg:px-0 lg:py-0"
    >
        <aside
            class="relative isolate hidden h-full flex-col justify-end overflow-hidden p-10 lg:flex"
        >
            <!--
                The property photograph sits on top of a brand gradient, so the
                panel still reads as intentional if the image ever fails to
                load. Swap public/property-hero.jpg for one of our own listing
                photos whenever we have one; a tall crop works best, because the
                panel is half the viewport wide and full height.

                The photograph is deliberately knocked back: it is blurred and
                sits under a near-opaque navy-to-green brand wash, so it reads
                as texture rather than as a picture. It is scaled up slightly so
                the blur leaves no soft edge at the panel border.

                Current image: Unsplash photo 1570129477492-45c003edd2be, used
                under the Unsplash License (commercial use, no attribution
                required), cropped to 900x1200.
            -->
            <img
                src="/property-hero.jpg"
                alt=""
                aria-hidden="true"
                class="absolute inset-0 size-full scale-110 object-cover blur-[3px]"
                width="900"
                height="1200"
            />
            <div
                class="from-scrim/95 via-scrim/85 to-scrim-accent/90 absolute inset-0 bg-gradient-to-br"
                aria-hidden="true"
            />
            <div
                class="from-scrim/90 absolute inset-0 bg-gradient-to-t from-10% to-transparent to-65%"
                aria-hidden="true"
            />

            <div
                class="text-scrim-foreground relative z-10 flex flex-col gap-6"
            >
                <Link :href="home()" class="flex w-fit items-center">
                    <img
                        src="/texas-renters-logo.png"
                        alt="TexasRenters.com"
                        class="h-11 w-auto object-contain"
                        width="400"
                        height="111"
                    />
                    <span class="sr-only">{{ name }}</span>
                </Link>

                <div class="flex flex-col gap-3">
                    <h2
                        class="font-heading text-3xl font-semibold tracking-tight text-balance"
                    >
                        Work orders, documents and property information.
                    </h2>
                    <p class="text-scrim-foreground/80 text-pretty">
                        The resident and owner portal for TexasRenters.com
                        property management in Houston.
                    </p>
                </div>

                <ul class="flex flex-wrap items-center gap-2">
                    <li v-for="highlight in highlights" :key="highlight.label">
                        <span
                            class="border-scrim-foreground/25 bg-scrim-foreground/10 flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm backdrop-blur-sm"
                        >
                            <component :is="highlight.icon" class="size-4" />
                            {{ highlight.label }}
                        </span>
                    </li>
                </ul>

                <AppStoreBadges
                    v-if="showAppLinks"
                    heading="Also on your phone"
                    class="pt-2"
                />
            </div>
        </aside>

        <div class="w-full lg:p-8">
            <div
                class="mx-auto flex w-full max-w-sm flex-col justify-center space-y-6"
            >
                <Link :href="home()" class="flex justify-center lg:hidden">
                    <img
                        src="/texas-renters-logo.png"
                        alt="TexasRenters.com"
                        class="h-10 w-auto object-contain dark:brightness-0 dark:invert"
                        width="400"
                        height="111"
                    />
                    <span class="sr-only">{{ name }}</span>
                </Link>
                <div class="flex flex-col space-y-2 text-center">
                    <h1 v-if="title" class="text-xl font-medium tracking-tight">
                        {{ title }}
                    </h1>
                    <p v-if="description" class="text-muted-foreground text-sm">
                        {{ description }}
                    </p>
                </div>
                <slot />

                <AppStoreBadges
                    v-if="showAppLinks"
                    class="items-center pt-2 lg:hidden"
                />
            </div>
        </div>
    </div>
</template>
