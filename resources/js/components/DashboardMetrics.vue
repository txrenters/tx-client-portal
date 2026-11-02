<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardAction,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

export type MetricTone =
    | 'blue'
    | 'green'
    | 'amber'
    | 'violet'
    | 'rose'
    | 'teal';

export type Metric = {
    label: string;
    value: string | number | null;
    loading?: boolean;
    badge?: string | null;
    icon?: LucideIcon;
    caption?: string | null;
    hint?: string | null;
    /** Colours the card; without one it stays the plain glass panel. */
    tone?: MetricTone;
    /** Where the card goes when clicked: the list behind the number. */
    href?: string;
};

const props = defineProps<{
    metrics: Metric[];
    loading?: boolean;
}>();

/** How many cards share a row; on phones each row becomes one swipeable strip. */
const ROW_SIZE = 4;

const rows = computed<Metric[][]>(() => {
    const chunks: Metric[][] = [];

    for (let start = 0; start < props.metrics.length; start += ROW_SIZE) {
        chunks.push(props.metrics.slice(start, start + ROW_SIZE));
    }

    return chunks;
});

function toneStyle(metric: Metric): Record<string, string> | undefined {
    return metric.tone ? { '--tone': `var(--tone-${metric.tone})` } : undefined;
}

const tracks = ref<HTMLElement[]>([]);
const activeSlides = ref<number[]>([]);

function slideWidth(track: HTMLElement): number {
    const first = track.firstElementChild as HTMLElement | null;
    const gap = parseFloat(getComputedStyle(track).columnGap) || 0;

    return first ? first.offsetWidth + gap : track.clientWidth;
}

function onTrackScroll(rowIndex: number): void {
    const track = tracks.value[rowIndex];

    if (!track) {
        return;
    }

    activeSlides.value[rowIndex] = Math.round(
        track.scrollLeft / slideWidth(track),
    );
}

function goToSlide(rowIndex: number, slideIndex: number): void {
    const track = tracks.value[rowIndex];

    track?.scrollTo({
        left: slideIndex * slideWidth(track),
        behavior: 'smooth',
    });
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-4">
        <div
            v-for="(row, rowIndex) in rows"
            :key="rowIndex"
            class="flex min-w-0 flex-col gap-3"
        >
            <div
                :ref="(el) => (tracks[rowIndex] = el as HTMLElement)"
                class="flex min-w-0 snap-x snap-mandatory [scrollbar-width:none] gap-4 overflow-x-auto @xl/main:grid @xl/main:grid-cols-2 @xl/main:overflow-visible @5xl/main:grid-cols-4 [&::-webkit-scrollbar]:hidden"
                @scroll.passive="onTrackScroll(rowIndex)"
            >
                <component
                    :is="metric.href ? Link : 'div'"
                    v-for="metric in row"
                    :key="metric.label"
                    :href="metric.href"
                    class="focus-visible:ring-ring flex min-w-0 shrink-0 basis-full snap-center rounded-2xl focus-visible:ring-2 focus-visible:outline-none"
                    :class="{ 'cursor-pointer': metric.href }"
                >
                    <Card
                        class="glass @container/card relative min-w-0 flex-1 overflow-hidden rounded-2xl"
                        :class="{
                            'bg-linear-to-br from-(--tone)/20 via-transparent to-transparent transition-transform motion-safe:hover:-translate-y-0.5':
                                metric.tone,
                        }"
                        :style="toneStyle(metric)"
                    >
                        <div
                            v-if="metric.tone"
                            class="pointer-events-none absolute -top-10 -right-10 size-32 rounded-full bg-(--tone)/30 blur-2xl"
                            aria-hidden="true"
                        />
                        <CardHeader>
                            <CardDescription class="flex items-center gap-2">
                                <span
                                    v-if="metric.icon"
                                    class="flex size-7 items-center justify-center rounded-lg"
                                    :class="
                                        metric.tone
                                            ? 'bg-(--tone)/20 text-(--tone) ring-1 ring-(--tone)/25'
                                            : 'bg-primary/10 text-primary'
                                    "
                                >
                                    <component
                                        :is="metric.icon"
                                        class="size-4"
                                    />
                                </span>
                                {{ metric.label }}
                            </CardDescription>
                            <CardTitle
                                class="min-w-0 text-2xl font-semibold wrap-anywhere tabular-nums @[250px]/card:text-3xl"
                            >
                                <Skeleton
                                    v-if="metric.loading ?? loading"
                                    class="h-8 w-20"
                                />
                                <template v-else>{{
                                    metric.value ?? '—'
                                }}</template>
                            </CardTitle>
                            <CardAction v-if="metric.badge">
                                <Badge variant="outline">{{
                                    metric.badge
                                }}</Badge>
                            </CardAction>
                        </CardHeader>
                        <CardFooter
                            v-if="metric.caption || metric.hint"
                            class="flex-col items-start gap-1.5 text-sm"
                        >
                            <div
                                v-if="metric.caption"
                                class="text-muted-foreground text-xs wrap-anywhere"
                            >
                                {{ metric.caption }}
                            </div>
                            <div
                                v-if="metric.hint"
                                class="text-muted-foreground"
                            >
                                {{ metric.hint }}
                            </div>
                        </CardFooter>
                    </Card>
                </component>
            </div>

            <div
                v-if="row.length > 1"
                class="flex justify-center gap-1.5 @xl/main:hidden"
            >
                <button
                    v-for="(metric, slideIndex) in row"
                    :key="metric.label"
                    type="button"
                    class="h-1.5 cursor-pointer rounded-full transition-all"
                    :class="
                        (activeSlides[rowIndex] ?? 0) === slideIndex
                            ? 'bg-primary w-4'
                            : 'bg-muted-foreground/30 w-1.5'
                    "
                    :aria-label="`Show ${metric.label}`"
                    :aria-current="
                        (activeSlides[rowIndex] ?? 0) === slideIndex
                            ? 'true'
                            : undefined
                    "
                    @click="goToSlide(rowIndex, slideIndex)"
                />
            </div>
        </div>
    </div>
</template>
