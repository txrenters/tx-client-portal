<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ContactDetailsFields from '@/components/ContactDetailsFields.vue';
import Heading from '@/components/Heading.vue';
import { detailsOnFile } from '@/routes';
import type { ContactDetailSection } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Details on file',
                href: detailsOnFile(),
            },
        ],
    },
});

defineProps<{
    hasClientAccount: boolean;
    /** Undefined while loading, null when it couldn't be read. */
    contactDetails?: ContactDetailSection[] | null;
}>();
</script>

<template>
    <Head title="Details on file" />

    <h1 class="sr-only">Details on file</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Details on file"
            description="What TexasRenters.com has on record for you"
        />

        <ContactDetailsFields
            v-if="hasClientAccount"
            :sections="contactDetails"
        />

        <p v-else class="text-muted-foreground text-sm">
            There are no details on file for this login.
        </p>
    </div>
</template>
