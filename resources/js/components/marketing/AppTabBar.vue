<script setup lang="ts">
import {
    Download,
    Footprints,
    House,
    ShieldCheck,
    Smartphone,
} from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { onMounted, ref, shallowRef } from 'vue';

/**
 * The app's floating tab bar, as the landing page's navigation. The tab for
 * whichever section crosses the middle of the screen stays lit.
 */
const tabs = [
    { id: 'top', label: 'Home', icon: House },
    { id: 'the-app', label: 'The app', icon: Smartphone },
    { id: 'how-it-works', label: 'Start', icon: Footprints },
    { id: 'security', label: 'Security', icon: ShieldCheck },
    { id: 'download', label: 'Download', icon: Download },
];

const activeTab = ref('top');
const sections = shallowRef<HTMLElement[]>([]);

onMounted(() => {
    sections.value = tabs.flatMap(({ id }) => {
        const section = document.getElementById(id);

        return section ? [section] : [];
    });
});

useIntersectionObserver(
    sections,
    (entries) => {
        const current = entries.findLast((entry) => entry.isIntersecting);

        if (current) {
            activeTab.value = current.target.id;
        }
    },
    { rootMargin: '-50% 0px -50% 0px' },
);
</script>

<template>
    <nav
        class="bg-card/90 ring-foreground/10 dark:ring-border fixed bottom-[max(0.75rem,env(safe-area-inset-bottom))] left-1/2 z-50 w-[calc(100%-1.5rem)] max-w-md -translate-x-1/2 rounded-full p-1.5 shadow-xl ring-1 backdrop-blur-lg"
        aria-label="Sections"
    >
        <ul class="grid grid-cols-5">
            <li v-for="tab in tabs" :key="tab.id">
                <a
                    :href="`#${tab.id}`"
                    class="focus-visible:ring-ring/50 flex flex-col items-center gap-0.5 rounded-full py-1.5 text-[0.7rem] font-medium transition-colors outline-none focus-visible:ring-4"
                    :class="
                        activeTab === tab.id
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="
                        activeTab === tab.id ? 'location' : undefined
                    "
                >
                    <component :is="tab.icon" class="size-5" />
                    {{ tab.label }}
                </a>
            </li>
        </ul>
    </nav>
</template>
