<script setup lang="ts">
import { ChevronLeft, ChevronRight, Play } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import PhoneFrame from '@/components/marketing/PhoneFrame.vue';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { vReveal } from '@/lib/reveal';

type Audience = 'residents' | 'owners';

type Screen = {
    screen: string;
    title: string;
    description: string;
};

/**
 * The mobile app's promo video and a tour of its screens, under the landing
 * page hero. The video comes from tx-mobile's store/promo (Remotion),
 * compressed into public/videos. The captions are the store listing copy
 * (tx-mobile's store/slides.json), written out as text so they stay readable
 * at any size.
 */
const tours: Record<Audience, { points: string[]; screens: Screen[] }> = {
    residents: {
        points: [
            'See the home you rent, your lease dates and your terms',
            'Report a repair with photos, then follow it to finished',
            'Message our team and get an alert when we reply',
            'Read, download and upload your documents',
            'Refer a property owner you know, and get a reward when they sign',
        ],
        screens: [
            {
                screen: '01-home',
                title: 'Your home, in your pocket',
                description:
                    'Repairs, messages, your lease and payments, all in one app.',
            },
            {
                screen: '02-repairs',
                title: 'Repairs, sorted in a tap',
                description:
                    'Request service with photos and follow every step.',
            },
            {
                screen: '03-messages',
                title: 'Message the team, anytime',
                description: 'Real people and real answers, all in one thread.',
            },
            {
                screen: '04-documents',
                title: 'Your lease and documents',
                description: 'Every signed page, ready when you need it.',
            },
            {
                screen: '05-payments',
                title: 'Every payment, at a glance',
                description: 'See your charges, payments and balance.',
            },
            {
                screen: '09-lock',
                title: 'Locked with Face ID',
                description: 'Your account stays private on your phone.',
            },
        ],
    },
    owners: {
        points: [
            'Your whole portfolio, with occupied and vacant properties at a glance',
            'Work orders across every property, and estimates to approve or decline',
            'Expenses on each property, including what was charged to you',
            'Message our team, or book a call at a time that suits you',
            'Property paperwork in one library, with room for your own files',
        ],
        screens: [
            {
                screen: '07-financials',
                title: 'Your rentals, by the numbers',
                description: 'Income, expenses and occupancy, month by month.',
            },
            {
                screen: '06-approve',
                title: 'Approve work with one tap',
                description: 'Review estimates and decide on the go.',
            },
            {
                screen: '08-meetings',
                title: 'Book time with us',
                description: 'Pick a slot with your manager. No phone tag.',
            },
            {
                screen: '09-lock',
                title: 'Locked with Face ID',
                description: 'Your account stays private on your phone.',
            },
        ],
    },
};

const audience = ref<Audience>('residents');
const tour = computed(() => tours[audience.value]);

const video = useTemplateRef<HTMLVideoElement>('video');
const isVideoStarted = ref(false);

/** Swap the poster's play button for the player's own controls. */
const playVideo = async (): Promise<void> => {
    isVideoStarted.value = true;
    await nextTick();
    video.value?.play();
};

const track = useTemplateRef<HTMLElement>('track');
const canScrollBack = ref(false);
const canScrollForward = ref(true);

const updateScrollEnds = (): void => {
    const element = track.value;

    if (element === null) {
        return;
    }

    canScrollBack.value = element.scrollLeft > 4;
    canScrollForward.value =
        element.scrollLeft + element.clientWidth < element.scrollWidth - 4;
};

const prefersReducedMotion = (): boolean =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Move the row by most of its visible width, so the next screens come into view. */
const scrollByPage = (direction: 1 | -1): void => {
    track.value?.scrollBy({
        left: direction * track.value.clientWidth * 0.8,
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
    });
};

watch(audience, async () => {
    await nextTick();
    track.value?.scrollTo({ left: 0 });
    updateScrollEnds();
});

/**
 * Touch screens and trackpads already swipe the row; this lets a mouse drag
 * it too. Snapping is paused while dragging so the row follows the pointer,
 * then the browser settles it on the nearest screen.
 */
const isDragging = ref(false);
let dragStartX = 0;
let dragStartScroll = 0;
let hasDragged = false;

const startDrag = (event: PointerEvent): void => {
    if (event.pointerType !== 'mouse' || event.button !== 0 || !track.value) {
        return;
    }

    isDragging.value = true;
    hasDragged = false;
    dragStartX = event.clientX;
    dragStartScroll = track.value.scrollLeft;
    track.value.setPointerCapture(event.pointerId);
};

const moveDrag = (event: PointerEvent): void => {
    if (!isDragging.value || !track.value) {
        return;
    }

    const distance = event.clientX - dragStartX;

    if (Math.abs(distance) > 4) {
        hasDragged = true;
    }

    track.value.scrollLeft = dragStartScroll - distance;
};

const endDrag = (event: PointerEvent): void => {
    if (!isDragging.value) {
        return;
    }

    isDragging.value = false;
    track.value?.releasePointerCapture(event.pointerId);
};

/** A drag should never also count as a click on what it started over. */
const swallowClickAfterDrag = (event: MouseEvent): void => {
    if (hasDragged) {
        event.preventDefault();
        event.stopPropagation();
        hasDragged = false;
    }
};
</script>

<template>
    <div>
        <!-- The promo video. Landscape from tablets up, portrait on phones,
             where it fills the screen. preload="none" with a poster means only
             the one being shown downloads, and only once it is played. -->
        <section
            id="tour"
            class="mx-auto w-full max-w-6xl scroll-mt-24 px-4 pt-20 sm:px-6 sm:pt-28"
        >
            <div
                v-reveal
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <h2
                    class="font-heading max-w-xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    See the app in under a minute
                </h2>
                <p class="text-muted-foreground max-w-sm text-pretty">
                    Reporting a repair, messaging the team, finding your lease
                    and approving work, start to finish.
                </p>
            </div>

            <div
                v-reveal.zoom="120"
                class="bg-muted ring-border relative mx-auto mt-10 aspect-[9/16] max-w-sm overflow-hidden rounded-3xl ring-1 md:aspect-video md:max-w-none"
            >
                <video
                    ref="video"
                    class="hidden size-full object-cover md:block"
                    src="/videos/app-promo-landscape.mp4"
                    poster="/videos/app-promo-landscape.webp"
                    preload="none"
                    playsinline
                    :controls="isVideoStarted"
                    aria-label="TexasRenters.com app promo video"
                ></video>
                <video
                    class="size-full object-cover md:hidden"
                    src="/videos/app-promo-portrait.mp4"
                    poster="/videos/app-promo-portrait.webp"
                    preload="none"
                    playsinline
                    controls
                    aria-label="TexasRenters.com app promo video"
                ></video>

                <button
                    v-if="!isVideoStarted"
                    type="button"
                    class="group focus-visible:ring-ring/50 absolute inset-0 hidden items-end justify-start p-8 outline-none focus-visible:ring-4 md:flex"
                    @click="playVideo"
                >
                    <span
                        class="bg-primary text-primary-foreground flex items-center gap-3 rounded-full py-2 pr-5 pl-2 shadow-xl transition-transform duration-200 group-hover:scale-105 motion-reduce:transition-none"
                    >
                        <span
                            class="bg-primary-foreground text-primary flex size-10 items-center justify-center rounded-full"
                        >
                            <Play class="size-4 translate-x-px fill-current" />
                        </span>
                        <span class="font-medium">Play the tour</span>
                        <span class="text-primary-foreground/70 tabular-nums">
                            0:57
                        </span>
                    </span>
                </button>
            </div>
        </section>

        <!-- The app's own screens, by who is using it. -->
        <section
            id="app"
            class="scroll-mt-24 py-20 sm:py-28"
            aria-labelledby="app-heading"
        >
            <div
                class="mx-auto grid w-full max-w-6xl gap-8 px-4 sm:px-6 lg:grid-cols-[1fr_1.1fr] lg:gap-16"
            >
                <div v-reveal.left class="flex flex-col items-start gap-6">
                    <h2
                        id="app-heading"
                        class="font-heading text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        Made for both sides of the lease
                    </h2>
                    <Tabs v-model="audience">
                        <TabsList class="h-11 p-1">
                            <TabsTrigger value="residents" class="px-4">
                                For residents
                            </TabsTrigger>
                            <TabsTrigger value="owners" class="px-4">
                                For owners
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                </div>

                <ul class="flex flex-col lg:pt-2">
                    <li
                        v-for="(point, index) in tour.points"
                        :key="point"
                        v-reveal="index * 70"
                        class="border-border border-b py-3 text-pretty first:pt-0 last:border-b-0"
                    >
                        {{ point }}
                    </li>
                </ul>
            </div>

            <div
                class="mx-auto mt-12 flex w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
            >
                <p class="text-muted-foreground text-sm">
                    <span class="hidden sm:inline">Drag or use the arrows</span>
                    <span class="sm:hidden">Swipe</span>
                    to see each screen.
                </p>
                <div class="hidden gap-2 sm:flex">
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Previous screens"
                        :disabled="!canScrollBack"
                        @click="scrollByPage(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Next screens"
                        :disabled="!canScrollForward"
                        @click="scrollByPage(1)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>

            <!-- Starts in line with the page content and runs off the right
                 edge, so it reads as a row to swipe through. -->
            <ul
                ref="track"
                class="mt-6 flex scroll-px-4 [scrollbar-width:none] gap-6 overflow-x-auto px-4 pb-4 sm:scroll-px-6 sm:gap-8 sm:px-6 lg:scroll-px-[max(1.5rem,calc((100vw-72rem)/2+1.5rem))] lg:px-[max(1.5rem,calc((100vw-72rem)/2+1.5rem))] [&::-webkit-scrollbar]:hidden"
                :class="
                    isDragging
                        ? 'cursor-grabbing snap-none'
                        : 'cursor-grab snap-x snap-mandatory'
                "
                :aria-label="
                    audience === 'residents'
                        ? 'App screens for residents'
                        : 'App screens for owners'
                "
                @scroll.passive="updateScrollEnds"
                @pointerdown="startDrag"
                @pointermove="moveDrag"
                @pointerup="endDrag"
                @pointercancel="endDrag"
                @click.capture="swallowClickAfterDrag"
            >
                <li
                    v-for="(item, index) in tour.screens"
                    :key="`${audience}-${item.screen}`"
                    v-reveal="Math.min(index, 4) * 90"
                    class="flex w-[64vw] max-w-60 shrink-0 snap-start flex-col gap-4 sm:w-60"
                >
                    <PhoneFrame :screen="item.screen" :alt="item.title" />
                    <div>
                        <h3 class="font-heading font-medium">
                            {{ item.title }}
                        </h3>
                        <p
                            class="text-muted-foreground mt-1 text-sm text-pretty"
                        >
                            {{ item.description }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
