<script setup lang="ts">
import { Play } from '@lucide/vue';
import { nextTick, ref, useTemplateRef } from 'vue';

/**
 * The app's promo video, from tx-mobile's store/promo (Remotion), compressed
 * into public/videos. It is landscape from tablets up and portrait on phones,
 * where it fills the screen. preload="none" with a poster means only the one
 * being shown downloads, and only once it is played.
 */
const video = useTemplateRef<HTMLVideoElement>('video');
const isVideoStarted = ref(false);

/** Swap the poster's play button for the player's own controls. */
const playVideo = async (): Promise<void> => {
    isVideoStarted.value = true;
    await nextTick();
    video.value?.play();
};
</script>

<template>
    <section
        class="mx-auto w-full max-w-6xl px-4 pt-16 sm:px-6 sm:pt-24"
        aria-labelledby="promo-heading"
    >
        <h2
            id="promo-heading"
            class="font-heading text-3xl font-semibold tracking-tight text-balance sm:text-5xl"
        >
            See the app in a minute
        </h2>

        <div
            class="bg-muted ring-border relative mx-auto mt-8 aspect-[9/16] max-w-sm overflow-hidden rounded-[2rem] ring-1 md:aspect-video md:max-w-none"
        >
            <video
                ref="video"
                class="hidden size-full object-cover md:block"
                src="/videos/app-promo-landscape.mp4"
                poster="/videos/app-promo-landscape.webp"
                preload="none"
                playsinline
                :controls="isVideoStarted"
                aria-label="TexasRenters App promo video"
            ></video>
            <video
                class="size-full object-cover md:hidden"
                src="/videos/app-promo-portrait.mp4"
                poster="/videos/app-promo-portrait.webp"
                preload="none"
                playsinline
                controls
                aria-label="TexasRenters App promo video"
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
                    <span class="font-medium">Watch the video</span>
                    <span class="text-primary-foreground/70 tabular-nums">
                        0:57
                    </span>
                </span>
            </button>
        </div>
    </section>
</template>
