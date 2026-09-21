<script setup lang="ts">
import { computed } from 'vue';

/**
 * How much of a portfolio is leased, as a ring: the filled arc is occupied
 * buildings, the recessive track is the vacant ones. The counts are written
 * below it, so the ring is never the only way to read the numbers.
 */
const props = defineProps<{
    occupancy: { total: number; occupied: number; vacant: number };
}>();

const RADIUS = 52;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
/** Surface gap between the two arcs, in path units. */
const GAP = 4;

const share = computed(() =>
    props.occupancy.total === 0
        ? 0
        : props.occupancy.occupied / props.occupancy.total,
);

const percent = computed(() => Math.round(share.value * 100));

/** With everything leased (or nothing) there is one arc, so no gap to cut. */
const isWhole = computed(
    () => props.occupancy.occupied === 0 || props.occupancy.vacant === 0,
);

const arcLength = (part: number): number =>
    Math.max(CIRCUMFERENCE * part - (isWhole.value ? 0 : GAP), 0);

const occupiedArc = computed(() => arcLength(share.value));
const vacantArc = computed(() => arcLength(1 - share.value));
</script>

<template>
    <div class="flex w-full flex-col gap-4">
        <div class="relative mx-auto w-full max-w-44">
            <svg
                viewBox="0 0 128 128"
                class="w-full"
                role="img"
                :aria-label="`${occupancy.occupied} of ${occupancy.total} buildings occupied`"
            >
                <g :transform="`rotate(-90 64 64)`">
                    <circle
                        :r="RADIUS"
                        cx="64"
                        cy="64"
                        fill="none"
                        stroke="var(--muted)"
                        stroke-width="12"
                        :stroke-dasharray="`${vacantArc} ${CIRCUMFERENCE - vacantArc}`"
                        :stroke-dashoffset="
                            -(CIRCUMFERENCE * share) - (isWhole ? 0 : GAP / 2)
                        "
                    />
                    <circle
                        :r="RADIUS"
                        cx="64"
                        cy="64"
                        fill="none"
                        stroke="var(--primary)"
                        stroke-width="12"
                        :stroke-dasharray="`${occupiedArc} ${CIRCUMFERENCE - occupiedArc}`"
                        :stroke-dashoffset="isWhole ? 0 : -(GAP / 2)"
                    />
                </g>
            </svg>

            <div
                class="absolute inset-0 flex flex-col items-center justify-center"
            >
                <p class="text-3xl leading-none font-semibold">
                    {{ percent }}%
                </p>
                <p class="text-muted-foreground text-xs">occupied</p>
            </div>
        </div>

        <ul class="flex w-full items-center justify-center gap-6 text-sm">
            <li class="flex items-center gap-2">
                <span
                    class="bg-primary size-2.5 rounded-full"
                    aria-hidden="true"
                />
                <span class="text-muted-foreground">Occupied</span>
                <span class="font-medium">{{ occupancy.occupied }}</span>
            </li>
            <li class="flex items-center gap-2">
                <span
                    class="bg-muted border-border size-2.5 rounded-full border"
                    aria-hidden="true"
                />
                <span class="text-muted-foreground">Vacant</span>
                <span class="font-medium">{{ occupancy.vacant }}</span>
            </li>
        </ul>
    </div>
</template>
