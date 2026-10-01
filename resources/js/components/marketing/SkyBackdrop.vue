<script setup lang="ts">
/**
 * A large line-drawn sun by day and a crescent moon with a few stars by night
 * (it follows the light and dark theme), for the right side of the landing
 * page hero, above the street in HouseBackdrop.
 *
 * It rises when the page opens: up from below while growing from half size,
 * like a sunrise. Switching the theme first sets one (it shrinks and sinks,
 * about a second), then raises the other; both are always drawn and the
 * `dark` class on <html> moves them with CSS transitions. At rest the sun's rays turn slowly and the
 * stars twinkle. With reduced motion they only fade.
 *
 * Drawn in currentColor so it follows the theme. Decorative only.
 */
import { onMounted, ref } from 'vue';

/** False for the first frame, so the opening sunrise has a start to animate from. */
const risen = ref(false);

onMounted(() => {
    requestAnimationFrame(() => {
        risen.value = true;
    });
});

const stars = [
    {
        d: 'M182 60 V72 M176 66 H188',
        delay: '[--star-delay:1500ms] [animation-delay:0s]',
    },
    {
        d: 'M30 34 V42 M26 38 H34',
        delay: '[--star-delay:1750ms] [animation-delay:1.3s]',
    },
    {
        d: 'M188 140 V148 M184 144 H192',
        delay: '[--star-delay:2000ms] [animation-delay:2.1s]',
    },
    {
        d: 'M22 168 V174 M19 171 H25',
        delay: '[--star-delay:2250ms] [animation-delay:0.7s]',
    },
];

/**
 * Shared by the sun and the moon: how each rises and sets. Each one's own
 * classes say when: setting takes 1s and starts at once, rising takes 1.4s and
 * waits for the other to finish setting.
 */
const body =
    'origin-center transition-[opacity,transform] ease-[cubic-bezier(0.22,1,0.36,1)] [transform-box:fill-box] motion-reduce:transform-none!';
</script>

<template>
    <svg
        viewBox="0 0 200 200"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="pointer-events-none overflow-visible"
    >
        <!-- The opening sunrise, shared by whichever is up -->
        <g
            :class="[
                body,
                'duration-[1400ms]',
                risen ? '' : 'translate-y-24 scale-50 opacity-0',
            ]"
        >
            <!-- Sun: up in light mode, set in dark mode -->
            <g
                :class="body"
                class="delay-1000 duration-[1400ms] dark:translate-y-24 dark:scale-50 dark:rotate-45 dark:opacity-0 dark:delay-0 dark:duration-1000"
            >
                <circle cx="100" cy="100" r="40" />
                <path
                    class="origin-center [transform-box:fill-box] motion-safe:animate-[spin_60s_linear_infinite]"
                    d="M100 44 V24 M100 156 V176 M44 100 H24 M156 100 H176 M60.4 60.4 L46.3 46.3 M139.6 60.4 L153.7 46.3 M60.4 139.6 L46.3 153.7 M139.6 139.6 L153.7 153.7"
                />
            </g>

            <!-- Moon: set in light mode, up in dark mode -->
            <g
                :class="body"
                class="translate-y-24 scale-50 -rotate-30 opacity-0 delay-0 duration-1000 dark:translate-y-0 dark:scale-100 dark:rotate-0 dark:opacity-100 dark:delay-1000 dark:duration-[1400ms]"
            >
                <path
                    d="M147 48 A67.6 67.6 0 1 0 168 152 A52 52 0 1 1 147 48 Z"
                />
            </g>
        </g>

        <!-- Stars come out one after another once the moon is up, then
             twinkle; they go out at once when the moon sets -->
        <g
            v-for="star in stars"
            :key="star.d"
            class="opacity-0 transition-opacity [transition-delay:0ms]! duration-700 dark:opacity-100 dark:[transition-delay:var(--star-delay)]!"
            :class="star.delay"
        >
            <path
                :d="star.d"
                class="origin-center [transform-box:fill-box] motion-safe:animate-[pulse_4s_ease-in-out_infinite]"
                :class="star.delay"
            />
        </g>
    </svg>
</template>
