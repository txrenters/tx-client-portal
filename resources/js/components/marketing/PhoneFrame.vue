<script setup lang="ts">
import { computed } from 'vue';

/**
 * One of the mobile app's screens in a 3D phone, for the landing page. The
 * screens are the simulator captures from tx-mobile's store/raw, exported to
 * public/images/app/screens as 640px WebP, so the website shows the real app
 * without the store slides' baked-in headlines.
 *
 * Ported from tx-mobile's promo video (store/promo/src/walkthrough/Phone3D.tsx):
 * a stack of rounded slices makes a metal edge you see as it turns, side
 * buttons stand out of that edge, and a glare sits on the glass where the
 * light would catch it. Sizes are in container units, so the phone keeps its
 * proportions at any width; it drifts slowly unless motion is reduced.
 */
const {
    screen,
    alt,
    eager = false,
    turn = -18,
    lean = 6,
    roll = 0,
} = defineProps<{
    screen: string;
    alt: string;
    eager?: boolean;
    /** Turn around the vertical axis, in degrees: negative faces left. */
    turn?: number;
    /** Lean back, in degrees. */
    lean?: number;
    /** Roll, in degrees. */
    roll?: number;
}>();

/** How many slices build the edge; more is smoother, fewer is cheaper. */
const EDGE_SLICES = 14;

/** Edge depth, as a share of the phone's width (cqw). */
const DEPTH = 9;

const slices = Array.from({ length: EDGE_SLICES }, (_, index) => ({
    z: -DEPTH + (DEPTH * index) / (EDGE_SLICES - 1),
    isFirst: index === 0,
    hasButtons: index > EDGE_SLICES * 0.3 && index < EDGE_SLICES * 0.7,
}));

/** Buttons as [side, top, length] in shares of the phone's height: action and volume left, power right. */
const buttons: Array<['left' | 'right', number, number]> = [
    ['left', 19, 4.5],
    ['left', 27, 7.5],
    ['left', 36.5, 7.5],
    ['right', 30, 11],
];

const bodyTransform = computed(
    () => `rotateX(${lean}deg) rotateY(${turn}deg) rotateZ(${roll}deg)`,
);

// The glare follows the turn: facing left, it sits right, and the other way round.
const glare = computed(() => 50 - turn * 1.6);
const glareGradient = computed(
    () =>
        `linear-gradient(115deg, transparent ${glare.value - 22}%, color-mix(in oklab, var(--scrim-foreground) 22%, transparent) ${glare.value}%, transparent ${glare.value + 18}%)`,
);
</script>

<template>
    <div class="@container">
        <div class="relative [perspective:560cqw]">
            <!-- Soft floor shadow, outside the 3D so it stays a flat ellipse. -->
            <div
                aria-hidden="true"
                class="from-scrim/35 absolute inset-x-[8%] -bottom-[15cqw] h-[17cqw] rounded-[50%] bg-radial to-transparent to-70% blur-[4cqw]"
                :style="{ translate: `${-turn * 0.6}cqw 0` }"
            />

            <div class="phone-drift transform-3d">
                <div
                    class="relative transform-3d"
                    :style="{ transform: bodyTransform }"
                >
                    <!-- The edge: slices of brushed metal, back to front. -->
                    <div
                        v-for="(slice, index) in slices"
                        :key="index"
                        aria-hidden="true"
                        class="absolute inset-0 rounded-[15cqw]"
                        :class="
                            slice.isFirst
                                ? 'from-scrim via-scrim to-scrim/80 bg-linear-150'
                                : 'from-muted-foreground via-scrim-foreground to-muted-foreground bg-linear-90'
                        "
                        :style="{ transform: `translateZ(${slice.z}cqw)` }"
                    >
                        <template v-if="slice.hasButtons">
                            <div
                                v-for="[side, top, length] in buttons"
                                :key="`${side}-${top}`"
                                class="from-muted-foreground via-scrim-foreground to-muted-foreground absolute w-[1.7cqw] rounded-full bg-linear-90"
                                :class="
                                    side === 'left'
                                        ? '-left-[0.9cqw]'
                                        : '-right-[0.9cqw]'
                                "
                                :style="{
                                    top: `${top}%`,
                                    height: `${length}%`,
                                }"
                            />
                        </template>
                    </div>

                    <!-- The back, seen if it ever turns far enough. -->
                    <div
                        aria-hidden="true"
                        class="bg-scrim absolute inset-0 rounded-[15cqw] backface-hidden"
                        :style="{
                            transform: `translateZ(${-DEPTH - 0.2}cqw) rotateY(180deg)`,
                        }"
                    />

                    <!-- The front: dark glass, the screen inset, the island and the glare. -->
                    <div
                        class="bg-foreground ring-muted-foreground/40 dark:bg-card relative [transform:translateZ(0.15cqw)] rounded-[15cqw] p-[2.6cqw] ring-[0.5cqw] ring-inset"
                    >
                        <div
                            class="bg-background relative overflow-hidden rounded-[12.4cqw]"
                        >
                            <img
                                :src="`/images/app/screens/${screen}.webp`"
                                :alt="alt"
                                width="640"
                                height="1391"
                                :loading="eager ? 'eager' : 'lazy'"
                                decoding="async"
                                draggable="false"
                                class="block h-auto w-full select-none"
                            />
                            <div
                                aria-hidden="true"
                                class="bg-foreground dark:bg-card absolute top-[2.6cqw] left-1/2 h-[7cqw] w-[29cqw] -translate-x-1/2 rounded-full"
                            />
                            <div
                                aria-hidden="true"
                                class="pointer-events-none absolute inset-0 mix-blend-screen"
                                :style="{ backgroundImage: glareGradient }"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .phone-drift {
        animation: phone-drift 14s ease-in-out infinite;
    }
}

@keyframes phone-drift {
    0%,
    100% {
        transform: rotateY(0deg) rotateX(0deg);
    }

    25% {
        transform: rotateY(4deg) rotateX(-1.5deg);
    }

    50% {
        transform: rotateY(0deg) rotateX(-2.5deg);
    }

    75% {
        transform: rotateY(-4deg) rotateX(-1deg);
    }
}
</style>
