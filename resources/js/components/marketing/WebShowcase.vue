<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Play } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import BrowserFrame from '@/components/marketing/BrowserFrame.vue';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { vReveal } from '@/lib/reveal';
import { events as landingEvents } from '@/routes/landing';

type Audience = 'residents' | 'owners';

type Feature = {
    screen: string;
    title: string;
    description: string;
};

/**
 * The web portal's tutorial video and a tour of its pages, under the landing
 * page hero. The video comes from ~/projects/tx-web-tutorial (Remotion),
 * compressed into public/videos. Picking a feature puts its page in the browser window.
 */
const tours: Record<Audience, Feature[]> = {
    residents: [
        {
            screen: '01-resident-dashboard',
            title: 'Your home at a glance',
            description:
                'Open repairs, rent, your lease and a year of payments on one page.',
        },
        {
            screen: '04-new-request',
            title: 'Report a repair',
            description:
                'Tell us what is wrong, add photos, and say whether we can let ourselves in.',
        },
        {
            screen: '03-work-orders',
            title: 'Follow it to finished',
            description:
                'Every work order and where it stands, from open to completed.',
        },
        {
            screen: '05-messages',
            title: 'Message our team',
            description:
                'Real people answer, and replies show up here and by text.',
        },
        {
            screen: '06-documents',
            title: 'Your lease and documents',
            description: 'Read, download and upload your files.',
        },
    ],
    owners: [
        {
            screen: '02-owner-dashboard',
            title: 'Your portfolio at a glance',
            description:
                'Buildings, open work orders and this month’s money on one page.',
        },
        {
            screen: '07-ledger',
            title: 'Your ledger',
            description:
                'Income, expenses and your balance by property and month, ready to download.',
        },
        {
            screen: '03-work-orders',
            title: 'Work across every property',
            description:
                'See what is open, scheduled and done, and approve estimates.',
        },
        {
            screen: '05-messages',
            title: 'Message our team',
            description: 'Ask about a property and keep the answer on file.',
        },
    ],
};

const audience = ref<Audience>('residents');
const features = computed(() => tours[audience.value]);
const activeIndex = ref(0);
const activeFeature = computed(() => features.value[activeIndex.value]);

watch(audience, () => {
    activeIndex.value = 0;
});

const video = useTemplateRef<HTMLVideoElement>('video');
const isVideoStarted = ref(false);

/** Tells Insights the tutorial was played; it counts each visitor once a day. */
const tracker = useHttp({ event: 'tutorial_play' });

/** Swap the poster's play button for the player's own controls. */
const playVideo = async (): Promise<void> => {
    isVideoStarted.value = true;
    tracker.post(landingEvents.url());
    await nextTick();
    video.value?.play();
};
</script>

<template>
    <div>
        <!-- The tutorial video. preload="none" with a poster means it only
             downloads once it is played. -->
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
                    See how the portal works
                </h2>
                <p class="text-muted-foreground max-w-sm text-pretty">
                    Signing up, reporting a repair, messaging the team and
                    finding your documents, start to finish.
                </p>
            </div>

            <div
                v-reveal.zoom="120"
                class="bg-muted ring-border relative mt-10 aspect-video overflow-hidden rounded-2xl ring-1 sm:rounded-3xl"
            >
                <video
                    ref="video"
                    class="size-full object-cover"
                    src="/videos/web-tutorial.mp4"
                    poster="/videos/web-tutorial.webp"
                    preload="none"
                    playsinline
                    :controls="isVideoStarted"
                    aria-label="How to use the TexasRenters.com client portal"
                ></video>

                <button
                    v-if="!isVideoStarted"
                    type="button"
                    class="group focus-visible:ring-ring/50 absolute inset-0 flex items-end justify-start p-4 outline-none focus-visible:ring-4 sm:p-8"
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
                        <span class="font-medium">Watch the tutorial</span>
                    </span>
                </button>
            </div>
        </section>

        <!-- The portal's own pages, by who is using it. -->
        <section
            id="portal"
            class="mx-auto grid w-full max-w-6xl scroll-mt-24 gap-10 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-[1fr_1.6fr] lg:gap-14"
            aria-labelledby="portal-heading"
        >
            <div v-reveal.left class="flex flex-col items-start gap-6">
                <h2
                    id="portal-heading"
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

                <ul class="flex w-full flex-col">
                    <li
                        v-for="(feature, index) in features"
                        :key="`${audience}-${feature.screen}-${feature.title}`"
                    >
                        <button
                            type="button"
                            class="focus-visible:ring-ring/50 border-border w-full border-l-2 py-3 pl-4 text-left transition-colors outline-none focus-visible:ring-4"
                            :class="
                                index === activeIndex
                                    ? 'border-primary'
                                    : 'hover:border-foreground/30'
                            "
                            :aria-pressed="index === activeIndex"
                            @click="activeIndex = index"
                        >
                            <span class="font-heading block font-semibold">
                                {{ feature.title }}
                            </span>
                            <span
                                class="text-muted-foreground block text-sm text-pretty"
                            >
                                {{ feature.description }}
                            </span>
                        </button>
                    </li>
                </ul>
            </div>

            <div v-reveal.zoom="120" class="lg:pt-16" aria-live="polite">
                <Transition
                    mode="out-in"
                    enter-active-class="transition duration-300 ease-out motion-reduce:transition-none"
                    enter-from-class="translate-y-2 opacity-0"
                    leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
                    leave-to-class="opacity-0"
                >
                    <BrowserFrame
                        :key="`${audience}-${activeFeature.screen}`"
                        :screen="activeFeature.screen"
                        :alt="activeFeature.title"
                    />
                </Transition>
            </div>
        </section>
    </div>
</template>
