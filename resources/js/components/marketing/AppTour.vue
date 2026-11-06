<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PhoneFrame from '@/components/marketing/PhoneFrame.vue';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

type Audience = 'residents' | 'owners';

type Screen = {
    screen: string;
    title: string;
    description: string;
};

/**
 * The app's real screens, one phone beside a list like the app's own rows.
 * Picking a row puts its screen on the phone. The captions are the store
 * listing copy (tx-mobile's store/slides.json).
 */
const tours: Record<Audience, Screen[]> = {
    residents: [
        {
            screen: '01-home',
            title: 'Your home, in your pocket',
            description:
                'Repairs, messages, your lease and payments, all in one app.',
        },
        {
            screen: '02-repairs',
            title: 'Repairs, sorted in a tap',
            description: 'Request service with photos and follow every step.',
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
    ],
    owners: [
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
    ],
};

const audience = ref<Audience>('residents');
const screens = computed(() => tours[audience.value]);
const activeIndex = ref(0);
const activeScreen = computed(() => screens.value[activeIndex.value]);

watch(audience, () => {
    activeIndex.value = 0;
});
</script>

<template>
    <section
        class="mx-auto grid w-full max-w-5xl gap-8 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1fr_20rem] lg:gap-20"
        aria-labelledby="app-heading"
    >
        <div class="flex min-w-0 flex-col items-start gap-6">
            <h2
                id="app-heading"
                class="font-heading text-3xl font-semibold tracking-tight text-balance sm:text-5xl"
            >
                Made for both sides of the lease
            </h2>

            <Tabs v-model="audience">
                <TabsList class="h-11 rounded-full p-1">
                    <TabsTrigger value="residents" class="rounded-full px-4">
                        For residents
                    </TabsTrigger>
                    <TabsTrigger value="owners" class="rounded-full px-4">
                        For owners
                    </TabsTrigger>
                </TabsList>
            </Tabs>

            <ul
                class="bg-card ring-border divide-border w-full divide-y overflow-hidden rounded-2xl ring-1"
            >
                <li
                    v-for="(item, index) in screens"
                    :key="`${audience}-${item.screen}`"
                >
                    <button
                        type="button"
                        class="focus-visible:ring-ring/50 flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors outline-none focus-visible:ring-4 focus-visible:ring-inset"
                        :class="
                            index === activeIndex
                                ? 'bg-accent'
                                : 'hover:bg-muted'
                        "
                        :aria-pressed="index === activeIndex"
                        @click="activeIndex = index"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="font-heading block font-semibold">
                                {{ item.title }}
                            </span>
                            <span
                                class="text-muted-foreground block text-sm text-pretty"
                            >
                                {{ item.description }}
                            </span>
                        </span>
                        <ChevronRight
                            class="text-muted-foreground size-4 shrink-0"
                        />
                    </button>
                </li>
            </ul>
        </div>

        <div
            class="mx-auto grid w-64 lg:w-full lg:self-center"
            aria-live="polite"
        >
            <Transition
                enter-active-class="transition-opacity duration-300 motion-reduce:transition-none"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-300 motion-reduce:transition-none"
                leave-to-class="opacity-0"
            >
                <PhoneFrame
                    :key="`${audience}-${activeScreen.screen}`"
                    :screen="activeScreen.screen"
                    :alt="activeScreen.title"
                    class="[grid-area:1/1]"
                />
            </Transition>
        </div>
    </section>
</template>
