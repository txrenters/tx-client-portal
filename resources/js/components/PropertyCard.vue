<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import ListCard from '@/components/ListCard.vue';
import { Badge } from '@/components/ui/badge';
import type { PortalProperty } from '@/types';

const props = defineProps<{
    property: PortalProperty;
    href?: NonNullable<InertiaLinkProps['href']>;
}>();

const location = [
    props.property.city,
    props.property.state,
    props.property.postalCode,
]
    .filter(Boolean)
    .join(', ');
</script>

<template>
    <ListCard
        :title="property.address"
        :subtitle="location || null"
        :icon="Building2"
        :href="href"
    >
        <template v-if="property.status" #trailing>
            <Badge variant="outline">{{ property.status }}</Badge>
        </template>

        <template #meta>
            <span v-if="property.unitName">Unit {{ property.unitName }}</span>
            <span v-if="property.bedrooms">{{ property.bedrooms }} bed</span>
            <span v-if="property.bathrooms">{{ property.bathrooms }} bath</span>
            <span v-if="property.type">{{ property.type }}</span>
        </template>
    </ListCard>
</template>
