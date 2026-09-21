<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { index as ownerMessages } from '@/routes/owner/messages';
import { index as residentMessages } from '@/routes/resident/messages';
import type { ContactDetailSection } from '@/types';

/**
 * What TexasRenters.com has on record for the client in Propertyware, as
 * read-only fields under the profile form. Changes go through our office.
 */
defineProps<{
    /** Undefined while loading, null when it failed. */
    sections?: ContactDetailSection[] | null;
}>();

const page = usePage();

const messagesUrl = computed(() =>
    page.props.client?.activeRole === 'resident'
        ? residentMessages.url()
        : ownerMessages.url(),
);

function fieldId(section: string, label: string): string {
    return `on-file-${section}-${label}`
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-');
}
</script>

<template>
    <div class="grid gap-6">
        <p class="text-muted-foreground text-sm">
            These are on file with TexasRenters.com and can't be changed here.
            To update them,
            <Link :href="messagesUrl" class="text-primary hover:underline"
                >send us a message</Link
            >.
        </p>

        <div
            v-if="sections === undefined"
            class="grid gap-3"
            role="status"
            aria-label="Loading your details"
        >
            <Skeleton v-for="item in 3" :key="item" class="h-9 w-full" />
        </div>

        <p
            v-else-if="!sections || sections.length === 0"
            class="text-muted-foreground text-sm"
        >
            We couldn't load your details right now.
        </p>

        <section
            v-for="section in sections ?? []"
            v-else
            :key="section.title"
            class="grid gap-4"
        >
            <h3 class="text-sm font-medium">{{ section.title }}</h3>
            <div
                v-for="item in section.items"
                :key="item.label"
                class="grid gap-2"
            >
                <Label :for="fieldId(section.title, item.label)">{{
                    item.label
                }}</Label>
                <Input
                    :id="fieldId(section.title, item.label)"
                    :model-value="item.value"
                    readonly
                    class="bg-muted/40 text-muted-foreground cursor-default"
                />
            </div>
        </section>
    </div>
</template>
