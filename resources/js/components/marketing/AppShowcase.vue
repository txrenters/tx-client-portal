<script setup lang="ts">
import { ChevronLeft, ChevronRight, Play } from '@lucide/vue';
import {
    useIntersectionObserver,
    useIntervalFn,
    usePreferredReducedMotion,
} from '@vueuse/core';
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

const activeIndex = ref(0);
const activeScreen = computed(() => tour.value.screens[activeIndex.value]);
const lastIndex = computed(() => tour.value.screens.length - 1);

const reducedMotion = usePreferredReducedMotion();

/** How long each screen stays in front while the row plays by itself. */
const autoplayInterval = 4000;

/**
 * The row plays by itself while it is on screen, and pauses while the pointer
 * or keyboard focus is on it. Once a visitor moves it themselves it stays where
 * they put it. Visitors who ask for reduced motion only move it by hand.
 */
const stage = useTemplateRef<HTMLElement>('stage');
const isStageVisible = ref(false);
const isPaused = ref(false);
const hasTakenControl = ref(false);

useIntersectionObserver(
    stage,
    ([entry]) => {
        isStageVisible.value = entry?.isIntersecting ?? false;
    },
    { threshold: 0.4 },
);

const isAutoplaying = computed(
    () =>
        isStageVisible.value &&
        !isPaused.value &&
        !hasTakenControl.value &&
        reducedMotion.value !== 'reduce',
);

const { pause: stopAutoplay, resume: startAutoplay } = useIntervalFn(
    () => {
        activeIndex.value =
            activeIndex.value >= lastIndex.value ? 0 : activeIndex.value + 1;
    },
    autoplayInterval,
    { immediate: false },
);

watch(
    [isAutoplaying, activeIndex],
    ([autoplaying]) => {
        // Restarting on every change keeps each screen up for the full interval.
        stopAutoplay();

        if (autoplaying) {
            startAutoplay();
        }
    },
    { immediate: true },
);

/** Bring a screen to the front, from the arrows, dots, keys, a drag or a click. */
const goTo = (index: number): void => {
    hasTakenControl.value = true;
    activeIndex.value = Math.min(Math.max(index, 0), lastIndex.value);
};

watch(audience, () => {
    activeIndex.value = 0;
});

/**
 * Where each phone sits around the one in front: neighbours step aside, turn
 * toward the middle and fall back, and anything further than two away is gone.
 */
const coverflowStyle = (index: number): Record<string, string> => {
    const offset = index - activeIndex.value;
    const distance = Math.abs(offset);
    const side = Math.sign(offset);

    return {
        transform: [
            `translateX(${offset * 68}%)`,
            `translateZ(${-distance * 140}px)`,
            `rotateY(${-side * Math.min(distance, 1) * 42}deg)`,
            `scale(${1 - Math.min(distance, 2) * 0.08})`,
        ].join(' '),
        opacity: String(
            distance === 0
                ? 1
                : distance === 1
                  ? 0.7
                  : distance === 2
                    ? 0.3
                    : 0,
        ),
        zIndex: String(10 - distance),
        pointerEvents: distance > 2 ? 'none' : 'auto',
    };
};

/**
 * A swipe or a mouse drag moves one screen per swipe. touch-action keeps
 * vertical swipes scrolling the page.
 */
const isDragging = ref(false);
const dragOffset = ref(0);
let dragStartX: number | null = null;
let hasDragged = false;

const startDrag = (event: PointerEvent): void => {
    if (event.pointerType === 'mouse' && event.button !== 0) {
        return;
    }

    dragStartX = event.clientX;
    isDragging.value = true;
    hasDragged = false;
    isPaused.value = true;
};

const moveDrag = (event: PointerEvent): void => {
    if (dragStartX === null) {
        return;
    }

    dragOffset.value = event.clientX - dragStartX;

    if (Math.abs(dragOffset.value) > 6 && !hasDragged) {
        hasDragged = true;
        stage.value?.setPointerCapture(event.pointerId);
    }
};

const endDrag = (): void => {
    if (dragStartX === null) {
        return;
    }

    if (dragOffset.value < -40) {
        goTo(activeIndex.value + 1);
    } else if (dragOffset.value > 40) {
        goTo(activeIndex.value - 1);
    }

    dragStartX = null;
    isDragging.value = false;
    dragOffset.value = 0;
    isPaused.value = false;
};

/** A click on a side phone brings it forward; a drag never counts as a click. */
const selectScreen = (index: number): void => {
    if (hasDragged) {
        hasDragged = false;

        return;
    }

    goTo(index);
};

/** The active dot fills up while the row plays, as a countdown to the next screen. */
// A ref inside v-for is always a list, even though only one dot has a fill.
const progress = useTemplateRef<HTMLElement[]>('progress');

watch(
    [isAutoplaying, activeIndex, progress],
    async () => {
        await nextTick();
        const fill = progress.value?.[0];
        fill?.getAnimations().forEach((animation) => animation.cancel());

        if (isAutoplaying.value && fill) {
            fill.animate(
                [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }],
                {
                    duration: autoplayInterval,
                    easing: 'linear',
                    fill: 'forwards',
                },
            );
        }
    },
    { flush: 'post' },
);
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

            <!-- Coverflow: the screen in front is flat and full size, its
                 neighbours turn toward it in 3D and fade back. -->
            <div
                ref="stage"
                v-reveal.zoom="120"
                class="relative mt-14 overflow-hidden px-4 py-6 outline-none select-none [perspective:1600px] [touch-action:pan-y] focus-visible:ring-ring/50 focus-visible:ring-4 focus-visible:ring-inset"
                :class="isDragging ? 'cursor-grabbing' : 'cursor-grab'"
                role="region"
                aria-roledescription="carousel"
                :aria-label="
                    audience === 'residents'
                        ? 'App screens for residents'
                        : 'App screens for owners'
                "
                tabindex="0"
                @keydown.left.prevent="goTo(activeIndex - 1)"
                @keydown.right.prevent="goTo(activeIndex + 1)"
                @pointerenter="isPaused = $event.pointerType === 'mouse'"
                @pointerleave="isPaused = false"
                @focusin="isPaused = true"
                @focusout="isPaused = false"
                @pointerdown="startDrag"
                @pointermove="moveDrag"
                @pointerup="endDrag"
                @pointercancel="endDrag"
            >
                <!-- A soft glow behind the phone in front. -->
                <div
                    class="bg-primary/25 pointer-events-none absolute top-1/2 left-1/2 -z-10 size-72 -translate-x-1/2 -translate-y-1/2 rounded-full blur-3xl sm:size-96"
                    aria-hidden="true"
                ></div>

                <ul
                    class="mx-auto grid w-[58vw] max-w-64 [transform-style:preserve-3d] sm:w-64"
                >
                    <li
                        v-for="(item, index) in tour.screens"
                        :key="`${audience}-${item.screen}`"
                        class="[grid-area:1/1] transition-[transform,opacity] duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] motion-reduce:transition-none"
                        :style="coverflowStyle(index)"
                        :aria-hidden="index !== activeIndex"
                        :aria-label="`${index + 1} of ${tour.screens.length}`"
                        role="group"
                        aria-roledescription="slide"
                        @click="selectScreen(index)"
                    >
                        <PhoneFrame
                            :screen="item.screen"
                            :alt="item.title"
                            :class="index !== activeIndex && 'cursor-pointer'"
                        />
                    </li>
                </ul>
            </div>

            <div
                class="mx-auto flex w-full max-w-md flex-col items-center gap-5 px-4 text-center"
            >
                <div class="min-h-20" aria-live="polite">
                    <Transition
                        mode="out-in"
                        enter-active-class="transition duration-300 ease-out motion-reduce:transition-none"
                        enter-from-class="translate-y-2 opacity-0"
                        leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
                        leave-to-class="-translate-y-1 opacity-0"
                    >
                        <div :key="`${audience}-${activeScreen.screen}`">
                            <h3
                                class="font-heading text-xl font-semibold tracking-tight"
                            >
                                {{ activeScreen.title }}
                            </h3>
                            <p class="text-muted-foreground mt-1 text-pretty">
                                {{ activeScreen.description }}
                            </p>
                        </div>
                    </Transition>
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        variant="outline"
                        size="icon"
                        class="rounded-full"
                        aria-label="Previous screen"
                        :disabled="activeIndex === 0"
                        @click="goTo(activeIndex - 1)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>

                    <div class="flex items-center gap-1.5">
                        <button
                            v-for="(item, index) in tour.screens"
                            :key="`${audience}-dot-${item.screen}`"
                            type="button"
                            class="focus-visible:ring-ring/50 bg-foreground/15 hover:bg-foreground/30 relative h-2 overflow-hidden rounded-full transition-[width,background-color] duration-300 outline-none focus-visible:ring-4"
                            :class="index === activeIndex ? 'w-8' : 'w-2'"
                            :aria-label="`Show ${item.title}`"
                            :aria-current="index === activeIndex"
                            @click="goTo(index)"
                        >
                            <span
                                v-if="index === activeIndex"
                                ref="progress"
                                class="bg-primary absolute inset-0 origin-left rounded-full"
                            ></span>
                        </button>
                    </div>

                    <Button
                        variant="outline"
                        size="icon"
                        class="rounded-full"
                        aria-label="Next screen"
                        :disabled="activeIndex === lastIndex"
                        @click="goTo(activeIndex + 1)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
