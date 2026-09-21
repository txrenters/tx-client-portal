<script setup lang="ts">
import { Mail } from '@lucide/vue';
import { computed } from 'vue';
import CollapsibleCard from '@/components/CollapsibleCard.vue';
import { Badge } from '@/components/ui/badge';
import type { PropertyDetails } from '@/types';

/**
 * The extra building details under a property's main facts, as collapsible
 * sections that start closed. The server has already trimmed them to what
 * the viewing client may see.
 */
const props = defineProps<{
    details: PropertyDetails;
    /** One column, for a sidebar; otherwise two columns on large screens. */
    stacked?: boolean;
}>();

type Panel = {
    key: string;
    title: string;
    count: number;
};

const panels = computed<Panel[]>(() => [
    ...props.details.sections.map((section) => ({
        key: `section:${section.title}`,
        title: section.title,
        count: section.items.length,
    })),
    ...(props.details.amenities.length > 0
        ? [
              {
                  key: 'amenities',
                  title: 'Amenities',
                  count: props.details.amenities.length,
              },
          ]
        : []),
    ...(props.details.managers.length > 0
        ? [
              {
                  key: 'managers',
                  title: 'Your property managers',
                  count: props.details.managers.length,
              },
          ]
        : []),
]);

const sectionItems = (key: string) =>
    props.details.sections.find((section) => `section:${section.title}` === key)
        ?.items ?? [];
</script>

<template>
    <div
        v-if="panels.length > 0"
        class="grid items-start gap-4"
        :class="{ 'lg:grid-cols-2': !stacked }"
    >
        <CollapsibleCard
            v-for="panel in panels"
            :key="panel.key"
            :title="panel.title"
            :count="panel.count"
        >
            <ul v-if="panel.key === 'amenities'" class="flex flex-wrap gap-2">
                <li v-for="amenity in details.amenities" :key="amenity">
                    <Badge variant="secondary">{{ amenity }}</Badge>
                </li>
            </ul>

            <ul
                v-else-if="panel.key === 'managers'"
                class="flex flex-col gap-3"
            >
                <li
                    v-for="manager in details.managers"
                    :key="manager.name"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span class="text-sm font-medium">{{ manager.name }}</span>
                    <a
                        v-if="manager.email"
                        :href="`mailto:${manager.email}`"
                        class="text-primary flex items-center gap-1 text-sm hover:underline"
                    >
                        <Mail class="size-3.5" />
                        {{ manager.email }}
                    </a>
                </li>
            </ul>

            <dl
                v-else
                class="grid gap-3"
                :class="{ 'sm:grid-cols-2': !stacked }"
            >
                <div v-for="item in sectionItems(panel.key)" :key="item.label">
                    <dt class="text-muted-foreground text-xs">
                        {{ item.label }}
                    </dt>
                    <dd class="text-sm font-medium wrap-anywhere">
                        {{ item.value }}
                    </dd>
                </div>
            </dl>
        </CollapsibleCard>
    </div>
</template>
