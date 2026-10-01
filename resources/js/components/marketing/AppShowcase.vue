<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { useTemplateRef } from 'vue';
import AppStoreBadges from '@/components/marketing/AppStoreBadges.vue';
import { Button } from '@/components/ui/button';

/**
 * The mobile app's promo video and store screenshots, under the landing
 * page hero. The video comes from tx-mobile's store/promo (Remotion), compressed
 * into public/videos.
 *
 * The images are the App Store slides themselves (tx-mobile's store/out,
 * exported to public/images/app as 600px WebP), so the website shows exactly
 * what the store listings show. Each one's caption is its alt text. Signing
 * in and the welcome slides lead, in the order a new client meets them.
 */
const slides = [
    { file: '10', alt: 'Signed in, in seconds: the login screen' },
    { file: '11', alt: 'Set up in a minute: creating an account' },
    { file: '12', alt: 'Your home, handled: the welcome screen' },
    { file: '13', alt: 'Report it in a minute: reporting a repair' },
    {
        file: '14',
        alt: 'Everything, always to hand: documents and payment history',
    },
    { file: '15', alt: 'Only what matters: choosing notifications' },
    { file: '01', alt: 'Your home, in your pocket: the resident home screen' },
    {
        file: '02',
        alt: 'Repairs, sorted in a tap: requesting a repair with photos',
    },
    { file: '03', alt: 'Message the team, anytime: a conversation with staff' },
    { file: '04', alt: 'Your lease and documents' },
    { file: '05', alt: 'Every payment, at a glance: the payments ledger' },
    {
        file: '06',
        alt: 'Approve work with one tap: an owner approving an estimate',
    },
    { file: '07', alt: 'Your rentals, by the numbers: the owner home screen' },
    { file: '08', alt: 'Book time with us: meetings with the office' },
    { file: '09', alt: 'Locked with Face ID: the app lock screen' },
];

const track = useTemplateRef<HTMLElement>('track');

/** Move the row by most of its visible width, so the next slides come into view. */
const scrollBy = (direction: 1 | -1): void => {
    const element = track.value;

    if (element === null) {
        return;
    }

    element.scrollBy({
        left: direction * element.clientWidth * 0.8,
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
    });
};
</script>

<template>
    <section id="app" class="scroll-mt-24 py-16 sm:py-20">
        <div class="mx-auto w-full max-w-6xl px-4 sm:px-6">
            <div class="max-w-2xl">
                <h2
                    class="font-heading text-3xl font-semibold tracking-tight text-balance"
                >
                    Take the portal with you
                </h2>
                <p class="text-muted-foreground mt-3 text-pretty">
                    The TexasRenters.com app for iPhone and Android: request
                    repairs, message the team, check payments and approve work,
                    wherever you are.
                </p>
                <AppStoreBadges class="mt-5" />
            </div>
        </div>

        <!-- The promo video: landscape from tablets up, portrait on phones,
             where it fills the screen. preload="none" with a poster means only
             the one being shown downloads, and only once it is played. -->
        <div class="mx-auto mt-10 w-full max-w-6xl px-4 sm:px-6">
            <video
                class="border-border/60 bg-muted hidden aspect-video w-full rounded-2xl border shadow-lg md:block"
                src="/videos/app-promo-landscape.mp4"
                poster="/videos/app-promo-landscape.webp"
                preload="none"
                controls
                playsinline
                aria-label="TexasRenters.com app promo video"
            ></video>
            <video
                class="border-border/60 bg-muted mx-auto aspect-[9/16] w-full max-w-sm rounded-2xl border shadow-lg md:hidden"
                src="/videos/app-promo-portrait.mp4"
                poster="/videos/app-promo-portrait.webp"
                preload="none"
                controls
                playsinline
                aria-label="TexasRenters.com app promo video"
            ></video>
        </div>

        <!-- The arrows sit right above the row they scroll. -->
        <div
            class="mx-auto mt-12 flex w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
        >
            <h3 class="font-heading text-xl font-semibold tracking-tight">
                Inside the app
            </h3>
            <div class="hidden gap-2 sm:flex">
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Previous screenshots"
                    @click="scrollBy(-1)"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Next screenshots"
                    @click="scrollBy(1)"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>

        <!-- Starts in line with the page content and runs off the right edge,
             so it reads as a row to swipe through. -->
        <ul
            ref="track"
            class="mt-5 flex snap-x snap-mandatory scroll-px-4 [scrollbar-width:none] gap-4 overflow-x-auto px-4 pb-6 sm:scroll-px-6 sm:gap-5 sm:px-6 lg:scroll-px-[max(1.5rem,calc((100vw-72rem)/2+1.5rem))] lg:px-[max(1.5rem,calc((100vw-72rem)/2+1.5rem))] [&::-webkit-scrollbar]:hidden"
            aria-label="App screenshots"
        >
            <li
                v-for="slide in slides"
                :key="slide.file"
                class="w-[62vw] max-w-64 shrink-0 snap-start sm:w-56 lg:w-60"
            >
                <img
                    :src="`/images/app/slide-${slide.file}.webp`"
                    :alt="slide.alt"
                    width="600"
                    height="1304"
                    loading="lazy"
                    decoding="async"
                    class="border-border/60 h-auto w-full rounded-[1.75rem] border shadow-lg"
                />
            </li>
        </ul>
    </section>
</template>
