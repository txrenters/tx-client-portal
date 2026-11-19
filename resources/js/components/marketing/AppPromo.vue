<script setup lang="ts">
import { Play } from '@lucide/vue';
import { ref } from 'vue';

/**
 * The app's promo video, hosted on the TexasRenters YouTube channel. Until it
 * is played the section shows a local poster, so YouTube's player (and its
 * cookies) only loads for visitors who click play.
 */
const youTubeVideoId = '8oDalAMczQs';
const embedUrl = `https://www.youtube-nocookie.com/embed/${youTubeVideoId}?autoplay=1&rel=0&playsinline=1`;

const isVideoStarted = ref(false);
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
            class="bg-muted ring-border relative mt-8 aspect-video overflow-hidden rounded-2xl ring-1 sm:rounded-[2rem]"
        >
            <iframe
                v-if="isVideoStarted"
                class="size-full"
                :src="embedUrl"
                title="Introducing the TexasRenters App"
                allow="
                    autoplay;
                    encrypted-media;
                    picture-in-picture;
                    fullscreen;
                "
                referrerpolicy="strict-origin-when-cross-origin"
                allowfullscreen
            ></iframe>

            <button
                v-else
                type="button"
                class="group focus-visible:ring-ring/50 absolute inset-0 flex items-end justify-start p-4 outline-none focus-visible:ring-4 sm:p-8"
                @click="isVideoStarted = true"
            >
                <img
                    src="/videos/app-promo-landscape.webp"
                    alt=""
                    class="absolute inset-0 size-full object-cover"
                />
                <span
                    class="bg-primary text-primary-foreground relative flex items-center gap-3 rounded-full py-2 pr-5 pl-2 shadow-xl transition-transform duration-200 group-hover:scale-105 motion-reduce:transition-none"
                >
                    <span
                        class="bg-primary-foreground text-primary flex size-10 items-center justify-center rounded-full"
                    >
                        <Play class="size-4 translate-x-px fill-current" />
                    </span>
                    <span class="font-medium">Watch the video</span>
                </span>
            </button>
        </div>
    </section>
</template>
